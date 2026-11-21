import { describe, expect, it } from 'vitest'
import { queryClient, QUERY_GC_TIME_MS, QUERY_STALE_TIME_MS } from './providers'

/** PERF-INSTANT-DATA-001 — the shared client keeps answers for a session's worth of revisits and revalidates behind them. */
describe('the shared query client', () => {
  it('keeps an unmounted answer for at least 15 minutes and never lets it outlive an hour', () => {
    const q = queryClient.getDefaultOptions().queries
    expect(q?.gcTime).toBe(QUERY_GC_TIME_MS)
    expect(QUERY_GC_TIME_MS).toBeGreaterThanOrEqual(15 * 60_000)
    expect(QUERY_GC_TIME_MS).toBeLessThanOrEqual(60 * 60_000)
  })

  it('revalidates a stale answer behind the cached one: a short stale time, no focus refetch storm', () => {
    const q = queryClient.getDefaultOptions().queries
    expect(q?.staleTime).toBe(QUERY_STALE_TIME_MS)
    expect(q?.refetchOnWindowFocus).toBe(false)
    expect(q?.staleTime).toBeLessThan(QUERY_GC_TIME_MS)
  })
})
