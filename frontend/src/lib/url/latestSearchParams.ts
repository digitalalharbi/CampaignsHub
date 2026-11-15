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
 * Every writer that goes through here records what it wrote, tagged with the location it was written FROM (its key). A
 * writer whose render is still that same location — nothing has been committed since — starts from the pending address
 * instead of its stale render. As soon as the router commits ANY navigation (this write, another writer's, a link, Back)
 * the location key changes, the record no longer applies, and the rendered params are the truth again; it can never
 * replay an address the router superseded. Scoped to the path, and it expires.
 */
let pending: { path: string; key: string; search: string; at: number } | null = null
const PENDING_MS = 1500

function baseFor(path: string, key: string, rendered: URLSearchParams): URLSearchParams {
  if (pending && pending.path === path && pending.key === key && Date.now() - pending.at < PENDING_MS) return new URLSearchParams(pending.search)

  return new URLSearchParams(rendered)
}

export type LatestParamsUpdate = (latest: URLSearchParams) => URLSearchParams

export function useLatestSearchParams(): [URLSearchParams, (update: LatestParamsUpdate, options?: NavigateOptions) => void] {
  const [params, setParams] = useSearchParams()
  const { pathname, key } = useLocation()

  /* A navigation was committed after the write: the rendered params are current again. */
  useEffect(() => {
    if (pending && (pending.path !== pathname || pending.key !== key)) pending = null
  }, [pathname, key])

  const write = useCallback((update: LatestParamsUpdate, options?: NavigateOptions) => {
    setParams((rendered) => {
      const next = update(baseFor(pathname, key, rendered))
      pending = { path: pathname, key, search: next.toString(), at: Date.now() }

      return next
    }, options)
  }, [key, pathname, setParams])

  return [params, write]
}

/** Test seam. */
export function resetPendingSearchParams(): void {
  pending = null
}
