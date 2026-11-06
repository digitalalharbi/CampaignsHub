import { describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { QUERY_STALE_TIME_MS } from './providers'

/**
 * PERF-REFETCH-001 — a second component asking the same key moments after the first does not ask the
 * network again.
 *
 * React Query dedupes requests in flight; with `staleTime: 0` it does NOT dedupe a request that has
 * just landed, so the dashboard's header, KPI strip and tab — three subscribers of one freshness
 * key, mounted a render apart — produced three identical requests. The product default below is
 * what the app's `QueryClient` carries.
 */
function Reader({ ask, label }: { ask: () => Promise<string>; label: string }) {
  const q = useQuery({ queryKey: ['shared', 'key'], queryFn: ask })

  return <span data-testid={label}>{q.data ?? '…'}</span>
}

describe('the query client defaults (PERF-REFETCH-001)', () => {
  it('reuses an answer for a later subscriber within the stale window', async () => {
    const ask = vi.fn(async () => 'answer')
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: QUERY_STALE_TIME_MS } } })

    const { rerender } = render(
      <QueryClientProvider client={client}><Reader ask={ask} label="first" /></QueryClientProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('first')).toHaveTextContent('answer'))

    rerender(
      <QueryClientProvider client={client}><Reader ask={ask} label="first" /><Reader ask={ask} label="second" /></QueryClientProvider>,
    )
    await waitFor(() => expect(screen.getByTestId('second')).toHaveTextContent('answer'))

    expect(ask).toHaveBeenCalledTimes(1)
  })

  it('is long enough to cover a page mount and short enough that nothing on screen goes stale', () => {
    expect(QUERY_STALE_TIME_MS).toBeGreaterThanOrEqual(10_000)
    expect(QUERY_STALE_TIME_MS).toBeLessThanOrEqual(60_000)
  })
})
