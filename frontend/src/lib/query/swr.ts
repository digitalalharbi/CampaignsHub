import { useQuery, type QueryKey } from '@tanstack/react-query'

/**
 * PERF-INSTANT-DATA-001 — stale-while-revalidate, written once.
 *
 * ## What the Owner saw
 *
 * A page that opened on a blank block and then filled in. Two separate causes hide behind that one
 * sight, and this module owns the second:
 *
 *  1. A revisit that waits. Answered by the cache itself — the client keeps an answer for
 *     {@link QUERY_GC_TIME_MS} after its reader leaves and hands it back on the first frame, while
 *     {@link QUERY_STALE_TIME_MS} decides whether a second request goes out behind it.
 *  2. A key that changes. Every page here builds its key from the project, the window and the
 *     filters, so moving the date range made a NEW key, which had no data, which drew the skeleton
 *     over a screen that had been full a moment earlier.
 *
 * ## The rule that makes keeping the old answer safe
 *
 * A key is split into a SCOPE and a WINDOW. The scope is everything that decides WHICH data this is
 * and WHO may see it: the project, the account, every filter, the currency. The window is only
 * WHEN. The previous answer is kept across a change of window and across nothing else:
 *
 *  - another project, account, client or filter has no answer on the first frame — the skeleton,
 *    never somebody else's figures while the right ones load;
 *  - another window keeps the previous answer, flagged `isShowingPrevious`, and the caller is
 *    expected to name the period the figures belong to (the payload carries it) and to say that the
 *    page is updating. A figure is never presented as belonging to a period it was not read for.
 *
 * Nothing here writes to storage. The cache is in memory, so sign-out and a workspace switch
 * (`queryClient.clear()`) end it by construction; see `persistence.test.ts`.
 */

/** How long a settled answer is reused before a reader that mounts asks again. Mirrors the client default. */
export const QUERY_STALE_TIME_MS = 30_000

/**
 * How long an answer survives after its last reader left — PERF-INSTANT-DATA-001.
 *
 * The library default is five minutes, so leaving Analytics for a quarter of an hour of other work
 * and coming back drew the cold skeleton again. Thirty minutes covers that round trip. It is a
 * MEMORY bound, not a freshness claim: a reader coming back after staleTime still revalidates, and
 * the answer is shown with its own `updatedAt` stamp until the new one lands.
 */
export const QUERY_GC_TIME_MS = 30 * 60_000

type KeyPart = string | number | boolean | null | undefined

const norm = (p: KeyPart): string | number | boolean => (p === null || p === undefined ? '' : p)

/**
 * `[name, scope, window]` — the scope always sits in slot 1, so "same scope" is one comparison and a
 * prefix invalidation by name still reaches every key of a family.
 */
export function scopedKey(name: string, scope: readonly KeyPart[], window: readonly KeyPart[]): QueryKey {
  return [name, scope.map(norm), window.map(norm)]
}

const sameScope = (a: QueryKey | undefined, b: QueryKey): boolean =>
  a !== undefined && a[0] === b[0] && JSON.stringify(a[1]) === JSON.stringify(b[1])

export interface SwrOptions<T> {
  name: string
  /** Project, account, filters, currency — anything that changes whose figures these are. */
  scope: readonly KeyPart[]
  /** The period (and anything else that only changes WHEN). The one dimension an old answer may bridge. */
  window: readonly KeyPart[]
  queryFn: () => Promise<T>
  enabled?: boolean
}

export function useSwrQuery<T>({ name, scope, window, queryFn, enabled = true }: SwrOptions<T>) {
  const key = scopedKey(name, scope, window)
  const q = useQuery({
    queryKey: key,
    queryFn,
    enabled,
    placeholderData: (previous, previousQuery) => (sameScope(previousQuery?.queryKey, key) ? previous : undefined),
  })

  const hasData = q.data !== undefined

  return {
    data: q.data,
    error: q.error,
    refetch: q.refetch,
    /** Nothing to show yet: the cold open. Never true while any correct answer is on screen. */
    isLoading: !hasData && q.isFetching,
    /** Nothing to show and nothing coming — a real failure, not a spinner. */
    isError: !hasData && q.isError,
    /** An answer is on screen and a newer one is on its way. */
    isRefreshing: hasData && q.isFetching,
    /** The answer on screen was read for a different window than the one now selected. */
    isShowingPrevious: q.isPlaceholderData,
    /** The newest request failed but an earlier answer is still on screen — say so, keep the figures. */
    refreshFailed: hasData && q.isError,
    /** When the answer on screen was fetched (ms epoch), 0 for a kept previous window. */
    updatedAt: q.dataUpdatedAt,
  }
}
