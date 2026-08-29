import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

vi.mock('@/lib/api/client', () => ({ getData: vi.fn() }))
import { getData } from '@/lib/api/client'

import { useCampaignOptionSource } from './useCampaignOptionSource'

/**
 * UX-MULTISELECT-SCALE-002 — the campaign options come from the server, and the term costs a request.
 *
 * The properties that matter are the ones a reader pays for:
 *
 *   1. A burst of keystrokes is ONE request, not one per character. Typing «ramadan» against an
 *      un-debounced source is seven round trips over the whole estate.
 *   2. The term reaches the SERVER as `q`. If it did not, this would be the frontend-only filtering
 *      ANALYTICS-FILTER-TRUTH forbids, dressed up as a server call.
 *   3. A campaign's NAME survives leaving the option list, because the reader's own selection
 *      routinely does.
 */
const wrapper = () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

const page = (options: Array<{ id: string; name: string }>, has_more = false) => ({
  options,
  has_more,
  limit: 120,
})

const urls = () => vi.mocked(getData).mock.calls.map((c) => String(c[0]))

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  vi.mocked(getData).mockReset()
  vi.mocked(getData).mockResolvedValue(page([{ id: 'a', name: 'Ramadan Retargeting' }]))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('the campaign option source', () => {
  it('asks the option endpoint, not the breakdown', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.options).toHaveLength(1))
    expect(urls()[0]).toBe('/projects/p1/metrics/campaign-options')
    /* Not the metrics breakdown, and not windowed by a period it must not depend on. */
    expect(urls()[0]).not.toContain('from=')
  })

  it('collapses a burst of keystrokes into a single request', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(urls()).toHaveLength(1))

    for (const term of ['r', 'ra', 'ram', 'rama', 'ramad', 'ramada', 'ramadan']) {
      act(() => result.current.search.onTerm(term))
      act(() => void vi.advanceTimersByTime(40))
    }
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(urls()).toHaveLength(2))
    expect(urls()[1]).toBe('/projects/p1/metrics/campaign-options?q=ramadan')
  })

  it('sends the term to the server rather than filtering what it already has', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(urls()).toHaveLength(1))

    act(() => result.current.search.onTerm('  riyadh  '))
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(urls()).toHaveLength(2))
    /* Trimmed: a trailing space is not a different search, and would be a different cache key. */
    expect(urls()[1]).toBe('/projects/p1/metrics/campaign-options?q=riyadh')
  })

  it('asks nothing at all without a project', () => {
    renderHook(() => useCampaignOptionSource(null), { wrapper: wrapper() })

    expect(urls()).toHaveLength(0)
  })

  it('reports the server has_more as given, never inferred from a full page', async () => {
    vi.mocked(getData).mockResolvedValue(
      page(Array.from({ length: 120 }, (_, i) => ({ id: `c-${i}`, name: `Campaign ${i}` })), false),
    )
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.options).toHaveLength(120))
    /* A full page with has_more false is NOT more. Inferring it from the length would say otherwise. */
    expect(result.current.search.hasMore).toBe(false)
  })

  it('remembers a campaign name after that campaign leaves the option list', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.options).toHaveLength(1))
    expect(result.current.labelOf('a')).toBe('Ramadan Retargeting')

    vi.mocked(getData).mockResolvedValue(page([{ id: 'z', name: 'Riyadh Season' }]))
    act(() => result.current.search.onTerm('riyadh'))
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(result.current.options[0]?.value).toBe('z'))
    expect(result.current.labelOf('a')).toBe('Ramadan Retargeting')
  })

  it('falls back to the id for a campaign it has never seen named', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.options).toHaveLength(1))

    expect(result.current.labelOf('never-seen')).toBe('never-seen')
  })
})
