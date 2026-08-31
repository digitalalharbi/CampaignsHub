import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * ANALYTICS-FILTER-TRUTH-001 — a filter lives in the URL, or it does not survive.
 *
 * Every filter on the dashboard and on Analytics lived in `useState` and nowhere else. A reader who
 * narrowed to one platform and one objective, then refreshed — or pressed Back, or sent the link to
 * a colleague — got the unfiltered page, with nothing to say anything had been dropped. The link
 * they shared showed their colleague a different answer to the question they were discussing.
 *
 * Three rules hold across all of these:
 *
 *   1. **A default is absent, not spelled out.** `?objective=all&provider=&days=30` is unreadable,
 *      makes two links to the same view look different, and leaves a parameter behind after the
 *      filter is cleared. Going back to the default REMOVES the key.
 *   2. **An empty multi-select is «every», said by saying nothing.** `provider=` reads as «no
 *      platforms» as easily as «all of them»; the absent key is unambiguous, and it is what an empty
 *      multi-select already means everywhere in this product.
 *   3. **Writing one filter never disturbs another.** They share one query string, so each write
 *      reads the current params and edits its own key.
 *
 * `replace: true` throughout: narrowing a filter is not a navigation, and Back should leave the page
 * rather than walk backwards through eight filter changes to get there.
 */

/** A single-valued filter — objective, client, tab. */
export function useUrlState(key: string, fallback: string): [string, (value: string) => void] {
  const [params, setParams] = useSearchParams()
  const value = params.get(key) ?? fallback

  const set = useCallback(
    (next: string) => {
      setParams(
        (current) => {
          const out = new URLSearchParams(current)
          if (next === fallback) out.delete(key)
          else out.set(key, next)

          return out
        },
        { replace: true },
      )
    },
    [key, fallback, setParams],
  )

  return [value, set]
}

/**
 * Write several URL keys in ONE update.
 *
 * Two `useUrlState` setters called in the same handler do not compose: each functional update is
 * applied against the params of the render it was created in, so the second silently drops the
 * first's change. Drilling into an entity writes both the path and the tab — «where am I» is one
 * statement, and half of it landing is worse than neither, because the breadcrumb would then describe
 * a scope the request never used.
 *
 * A value equal to its fallback is removed rather than written, matching `useUrlState` — a default
 * does not belong in a shared link.
 */
export function useUrlWriter(): (next: Record<string, { value: string; fallback: string }>) => void {
  const [, setParams] = useSearchParams()

  return useCallback(
    (next) => {
      setParams(
        (current) => {
          const out = new URLSearchParams(current)
          for (const [key, { value, fallback }] of Object.entries(next)) {
            if (value === fallback) out.delete(key)
            else out.set(key, value)
          }

          return out
        },
        { replace: true },
      )
    },
    [setParams],
  )
}

/** A multi-select filter — platforms, campaigns. Empty means every, and writes nothing. */
export function useUrlList(key: string): [string[], (next: string[] | ((prev: string[]) => string[])) => void] {
  const [params, setParams] = useSearchParams()
  const raw = params.get(key)
  const value = raw === null || raw === '' ? [] : raw.split(',').filter(Boolean)

  const set = useCallback(
    (next: string[] | ((prev: string[]) => string[])) => {
      setParams(
        (current) => {
          const out = new URLSearchParams(current)
          const before = current.get(key)
          const list = typeof next === 'function'
            ? next(before === null || before === '' ? [] : before.split(',').filter(Boolean))
            : next

          if (list.length === 0) out.delete(key)
          else out.set(key, list.join(','))

          return out
        },
        { replace: true },
      )
    },
    [key, setParams],
  )

  return [value, set]
}

/** The period. A non-numeric, zero or negative value is not a period and falls back rather than
 *  asking the backend for a window that ends before it starts. */
export function useUrlNumber(key: string, fallback: number): [number, (value: number) => void] {
  const [raw, setRaw] = useUrlState(key, String(fallback))
  const parsed = Number(raw)
  const value = Number.isFinite(parsed) && parsed > 0 ? parsed : fallback

  const set = useCallback((next: number) => setRaw(String(next)), [setRaw])

  return [value, set]
}
