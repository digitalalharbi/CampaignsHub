import { describe, expect, it, vi } from 'vitest'
import { act, render, renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { scopedKey, useSwrQuery } from './swr'

/**
 * PERF-INSTANT-DATA-001 — the last answer stays on screen while the next one is fetched, and only
 * ever within the scope it was true for.
 */
function clientWith(gcTime = 60_000) {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime, staleTime: 0 } } })
}
const wrap = (client: QueryClient) => ({ children }: { children: ReactNode }) => (
  <QueryClientProvider client={client}>{children}</QueryClientProvider>
)

interface Args { project: string; from: string; filter?: string }
function useReading(fetcher: (a: Args) => Promise<string>, a: Args) {
  return useSwrQuery({
    name: 'reading',
    scope: [a.project, a.filter ?? ''],
    window: [a.from],
    queryFn: () => fetcher(a),
  })
}

describe('scopedKey', () => {
  it('puts the scope in a fixed slot and normalises absent parts, so two readers build one key', () => {
    expect(scopedKey('x', ['p1', null, undefined], ['a', 'b'])).toEqual(['x', ['p1', '', ''], ['a', 'b']])
  })
})

describe('useSwrQuery', () => {
  it('keeps the previous answer on screen when only the window moves, and says it is the previous one', async () => {
    const fetcher = vi.fn(async (a: Args) => `answer-${a.from}`)
    const { result, rerender } = renderHook((a: Args) => useReading(fetcher, a), {
      wrapper: wrap(clientWith()),
      initialProps: { project: 'p1', from: '2026-10-01' },
    })
    await waitFor(() => expect(result.current.data).toBe('answer-2026-10-01'))

    let release: (v: string) => void = () => {}
    fetcher.mockImplementationOnce(() => new Promise<string>((r) => { release = r }))
    rerender({ project: 'p1', from: '2026-09-01' })

    // The very first frame after the change: not empty, not loading, flagged as previous.
    expect(result.current.data).toBe('answer-2026-10-01')
    expect(result.current.isLoading).toBe(false)
    expect(result.current.isShowingPrevious).toBe(true)
    expect(result.current.isRefreshing).toBe(true)

    await act(async () => { release('answer-2026-09-01') })
    await waitFor(() => expect(result.current.data).toBe('answer-2026-09-01'))
    expect(result.current.isShowingPrevious).toBe(false)
    expect(result.current.isRefreshing).toBe(false)
  })

  it('never carries an answer across projects: the first frame of another project has no data', async () => {
    const fetcher = vi.fn(async (a: Args) => `answer-${a.project}`)
    const { result, rerender } = renderHook((a: Args) => useReading(fetcher, a), {
      wrapper: wrap(clientWith()),
      initialProps: { project: 'p1', from: '2026-10-01' },
    })
    await waitFor(() => expect(result.current.data).toBe('answer-p1'))

    fetcher.mockImplementationOnce(() => new Promise<string>(() => {}))
    rerender({ project: 'p2', from: '2026-10-01' })

    expect(result.current.data).toBeUndefined()
    expect(result.current.isLoading).toBe(true)
    expect(result.current.isShowingPrevious).toBe(false)
  })

  it('never carries an answer across a filter either: a filter is part of the scope', async () => {
    const fetcher = vi.fn(async (a: Args) => `answer-${a.filter ?? 'all'}`)
    const { result, rerender } = renderHook((a: Args) => useReading(fetcher, a), {
      wrapper: wrap(clientWith()),
      initialProps: { project: 'p1', from: '2026-10-01' } as Args,
    })
    await waitFor(() => expect(result.current.data).toBe('answer-all'))

    fetcher.mockImplementationOnce(() => new Promise<string>(() => {}))
    rerender({ project: 'p1', from: '2026-10-01', filter: 'meta' })

    expect(result.current.data).toBeUndefined()
    expect(result.current.isLoading).toBe(true)
  })

  it('shows the cached answer on the first frame of a revisit and refreshes it behind', async () => {
    const client = clientWith()
    const fetcher = vi.fn(async () => 'first')
    const a: Args = { project: 'p1', from: '2026-10-01' }
    const first = renderHook(() => useReading(fetcher, a), { wrapper: wrap(client) })
    await waitFor(() => expect(first.result.current.data).toBe('first'))
    first.unmount()

    fetcher.mockImplementationOnce(() => new Promise<string>(() => {}))
    const second = renderHook(() => useReading(fetcher, a), { wrapper: wrap(client) })

    expect(second.result.current.data).toBe('first')
    expect(second.result.current.isLoading).toBe(false)
    expect(second.result.current.isRefreshing).toBe(true)
    expect(second.result.current.updatedAt).toBeGreaterThan(0)
  })

  it('keeps the data and flags the failure when a background refresh fails', async () => {
    const client = clientWith()
    const fetcher = vi.fn(async () => 'good')
    const a: Args = { project: 'p1', from: '2026-10-01' }
    const { result } = renderHook(() => useReading(fetcher, a), { wrapper: wrap(client) })
    await waitFor(() => expect(result.current.data).toBe('good'))

    fetcher.mockRejectedValueOnce(new Error('boom'))
    await act(async () => { await result.current.refetch() })

    await waitFor(() => expect(result.current.refreshFailed).toBe(true))
    expect(result.current.data).toBe('good')
  })

  it('a cold failure is an error with no data, not a spinner', async () => {
    const fetcher = vi.fn(async () => { throw new Error('down') })
    const { result } = renderHook(() => useReading(fetcher, { project: 'p1', from: '2026-10-01' }), { wrapper: wrap(clientWith()) })
    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(result.current.isLoading).toBe(false)
    expect(result.current.data).toBeUndefined()
    expect(result.current.refreshFailed).toBe(false)
  })

  it('after the cache is cleared (sign-out, workspace switch) a mounted reader never renders the old answer again', async () => {
    const client = clientWith()
    let answer = 'workspace-A'
    const frames: Array<string | undefined> = []
    function Probe() {
      const [from] = useState('2026-10-01')
      const q = useReading(async () => answer, { project: 'p1', from })
      frames.push(q.data)
      return <span data-testid="v">{q.data ?? 'none'}</span>
    }
    render(<QueryClientProvider client={client}><Probe /></QueryClientProvider>)
    await waitFor(() => expect(screen.getByTestId('v')).toHaveTextContent('workspace-A'))

    answer = 'workspace-B'
    frames.length = 0
    await act(async () => { client.clear() })
    await act(async () => { await client.refetchQueries() })

    expect(frames).not.toContain('workspace-A')
  })
})
