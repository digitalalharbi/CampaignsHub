import { createContext, useContext, useLayoutEffect } from 'react'

/**
 * NOV-FIT-001 — how a page tells the portal frame «I fit the screen, lock to it».
 *
 * The frame cannot know which page is mounted, and a lock applied to every page would cut off the ones that
 * have not been converted. So the lock is opt-in from below: a page that fits calls `useFitLock()`, the frame
 * sets `data-fit` and `index.css` pins the height (on a desktop only — see `FIT_LOCK_QUERY`). Leaving the page
 * lifts it. Outside a frame the default is a no-op, so a fitted page renders anywhere.
 */
export const FitContext = createContext<(fit: boolean) => void>(() => undefined)

export function useFitLock(active = true): void {
  const set = useContext(FitContext)
  useLayoutEffect(() => {
    if (!active) return
    set(true)

    return () => set(false)
  }, [active, set])
}
