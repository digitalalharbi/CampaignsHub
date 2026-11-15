import { useCallback, useEffect } from 'react'
import { useLocation, useSearchParams, type NavigateOptions } from 'react-router-dom'

/**
 * URL-WRITE-RACE-001 — merge a search-param write into the LATEST address, not the one this component rendered with.
 *
 * React Router's functional `setSearchParams(fn)` hands `fn` the params of the render that created the setter. Two
 * writers in one tick — a page's mount effect writing the period (`usePeriod`, DATE-RANGE-001) and a tab click writing
 * `?tab=` — each start from that same rendered address, and the second navigation replaces the first: the tab click is
 * lost (`analytics-normalization`, Firefox, CI PR #1071), or the period is. Which one loses depends on how the browser
 * orders the effect and the event, which is why it only showed in one browser.
 *
 * Every writer that goes through here records what it wrote until the router commits it; a write in the meantime starts
 * from that pending address instead of its stale render. Once a render shows the address committed, the record is
 * dropped and the rendered params are the truth again. The record is scoped to the path it was written on, and expires,
 * so it can never leak a page's keys into another page.
 */
let pending: { path: string; search: string; at: number } | null = null
const PENDING_MS = 1500

function baseFor(path: string, rendered: URLSearchParams): URLSearchParams {
  if (pending && pending.path === path && Date.now() - pending.at < PENDING_MS) return new URLSearchParams(pending.search)

  return new URLSearchParams(rendered)
}

export type LatestParamsUpdate = (latest: URLSearchParams) => URLSearchParams

export function useLatestSearchParams(): [URLSearchParams, (update: LatestParamsUpdate, options?: NavigateOptions) => void] {
  const [params, setParams] = useSearchParams()
  const { pathname, search } = useLocation()

  /* The router committed what was pending: the rendered params are current again. */
  useEffect(() => {
    if (pending && pending.path === pathname && pending.search === search.replace(/^\?/, '')) pending = null
  }, [pathname, search])

  const write = useCallback((update: LatestParamsUpdate, options?: NavigateOptions) => {
    setParams((rendered) => {
      const next = update(baseFor(pathname, rendered))
      pending = { path: pathname, search: next.toString(), at: Date.now() }

      return next
    }, options)
  }, [pathname, setParams])

  return [params, write]
}

/** Test seam. */
export function resetPendingSearchParams(): void {
  pending = null
}
