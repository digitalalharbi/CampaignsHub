import { useCallback } from 'react'
import { useLatestSearchParams } from '@/lib/url/latestSearchParams'

/**
 * AGENCY-FIT-001 — which tab a fitted page is on, kept in the address (`?tab=`).
 *
 * A reload, Back and a shared link land on the same tab, and a spec or a link can open one directly. The write goes
 * through `useLatestSearchParams`, so a tab click and another writer in the same tick (the period picker's mount
 * effect) cannot overwrite each other. An unknown value falls back to the first tab rather than an empty panel.
 */
export function useTabParam<T extends string>(keys: readonly T[], fallback: T = keys[0]!, name = 'tab'): [T, (next: T) => void] {
  const [params, write] = useLatestSearchParams()
  const raw = params.get(name)
  const active = (keys as readonly string[]).includes(raw ?? '') ? (raw as T) : fallback

  const set = useCallback((next: T) => {
    write((p) => { if (next === fallback) p.delete(name); else p.set(name, next); return p }, { replace: true })
  }, [write, fallback, name])

  return [active, set]
}
