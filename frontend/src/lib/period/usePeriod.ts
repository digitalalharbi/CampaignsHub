import { useCallback, useEffect, useMemo } from 'react'
import { useLatestSearchParams } from '@/lib/url/latestSearchParams'
import { useAuth } from '@/stores/auth'
import { riyadhToday, spanLength, type Comparison, type CompareMode, type Period, type PresetKey, type ResolveContext } from './periodMath'
import {
  DEFAULT_SELECTION, loadStored, paramsWithPeriod, pushRecent, resolveSelection, saveStored, selectionFromParams,
  type Selection,
} from './periodState'

/**
 * DATE-RANGE-001 — the period of the page you are on, shared by every page.
 *
 * Where the selection comes from, in order:
 *   1. the page address (`from`, `to`, `compare`) — a shared link wins, so it opens the same period;
 *   2. what this user last chose on any page (kept in memory for the session, and in storage per user);
 *   3. the default: the last seven COMPLETE days.
 *
 * When the address names no period, the resolved one is written back into it (replace, not push), so the
 * page is shareable the moment it opens and a reload or Back stays on the same days.
 *
 * `range` has the `{ from, to }` shape every metrics query already takes, so a page swaps its old
 * `useLastNDaysRange(days)` for this and nothing downstream changes.
 */

/** Per user, for the life of the tab — so the period survives navigation even where storage is blocked. */
const session = new Map<string, Selection>()
const sessionRecent = new Map<string, Selection[]>()

export interface PeriodState {
  period: Period
  comparison: Comparison | null
  range: { from: string; to: string }
  /** The comparison days in the `{ from, to }` shape, or null when not comparing. */
  compareRange: { from: string; to: string } | null
  /** Inclusive day count — what the old «7 / 30 / 90» number was. */
  days: number
  selection: Selection
  recent: Selection[]
  ctx: ResolveContext
  /** `remember: false` — a live preview while the picker is open: the page follows it, the «recently used» list does not. */
  setSelection: (next: Selection, opts?: { remember?: boolean }) => void
  setPreset: (key: PresetKey) => void
  /** Old call sites that pass a day count: maps 7/14/28/30/90 onto the matching preset (which ends yesterday). */
  setDays: (days: number) => void
  setCompare: (mode: CompareMode) => void
}

const DAY_PRESET: Record<number, PresetKey> = { 7: 'last_7', 14: 'last_14', 28: 'last_28', 30: 'last_30', 90: 'last_90' }

/**
 * `writeUrl: false` — a reader that does not own the page's address (a report or live-link builder opened in a dialog on
 * `/reports`) seeds its own fields from the period and leaves the address alone: a dialog that rewrites the page URL
 * the moment it opens navigates under the person's first click (CI PR #1071, live-link builder on Firefox).
 */
export function usePeriod(options: { earliest?: string | null; writeUrl?: boolean } = {}): PeriodState {
  const writeUrl = options.writeUrl !== false
  const [params, setParams] = useLatestSearchParams()
  const userId = useAuth((s) => s.user?.id ?? null)
  const uid = userId ?? 'anon'
  const today = riyadhToday()
  const earliest = options.earliest ?? null
  const ctx = useMemo<ResolveContext>(() => ({ today, earliest }), [today, earliest])

  const search = params.toString()
  const fromUrl = useMemo(() => selectionFromParams(new URLSearchParams(search), ctx), [search, ctx])
  const stored = useMemo(() => (session.has(uid) ? null : loadStored(userId)), [uid, userId])

  const selection = fromUrl ?? session.get(uid) ?? stored?.selection ?? DEFAULT_SELECTION
  const recent = sessionRecent.get(uid) ?? stored?.recent ?? []

  const resolved = useMemo(
    () => resolveSelection(selection, ctx),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- the selection's fields are the identity
    [selection.key, selection.from, selection.to, selection.compare, selection.cfrom, selection.cto, ctx],
  )

  /* The address names no period (or only an old `?days=`): write the resolved one so the link is shareable. */
  const hasPeriodInUrl = params.has('from') && params.has('to') && !params.has('days')
  useEffect(() => {
    if (hasPeriodInUrl || !writeUrl) return
    // URL-WRITE-RACE-001 — merged into the latest address, and only when that address still names no period.
    setParams((latest) => (latest.has('from') && latest.has('to') && !latest.has('days') ? latest : paramsWithPeriod(latest, resolved, selection.compare)), { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasPeriodInUrl, writeUrl, resolved.period.from, resolved.period.to, selection.compare])

  /* Remember what the address said, so the next page opens on it. */
  useEffect(() => {
    if (!fromUrl) return
    session.set(uid, fromUrl)
    saveStored(userId, { selection: fromUrl, recent: sessionRecent.get(uid) ?? stored?.recent ?? [] })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fromUrl?.key, fromUrl?.from, fromUrl?.to, fromUrl?.compare, fromUrl?.cfrom, fromUrl?.cto, uid])

  const setSelection = useCallback((next: Selection, opts?: { remember?: boolean }) => {
    const prevRecent = sessionRecent.get(uid) ?? stored?.recent ?? []
    const nextRecent = opts?.remember === false ? prevRecent : pushRecent(prevRecent, next)
    session.set(uid, next)
    sessionRecent.set(uid, nextRecent)
    saveStored(userId, { selection: next, recent: nextRecent })
    const r = resolveSelection(next, ctx)
    if (writeUrl) setParams((latest) => paramsWithPeriod(latest, r, next.compare), { replace: true })
  }, [ctx, setParams, stored, uid, userId, writeUrl])

  const setPreset = useCallback((key: PresetKey) => setSelection({ key, compare: selection.compare === 'custom' ? 'none' : selection.compare }), [selection.compare, setSelection])
  const setDays = useCallback((n: number) => { const key = DAY_PRESET[n]; if (key) setPreset(key) }, [setPreset])
  const setCompare = useCallback((mode: CompareMode) => setSelection({ ...selection, compare: mode }), [selection, setSelection])

  const range = useMemo(() => ({ from: resolved.period.from, to: resolved.period.to }), [resolved.period.from, resolved.period.to])
  const compareRange = useMemo(
    () => (resolved.comparison ? { from: resolved.comparison.from, to: resolved.comparison.to } : null),
    [resolved.comparison],
  )

  return {
    period: resolved.period, comparison: resolved.comparison, range, compareRange, days: spanLength(range),
    selection, recent, ctx, setSelection, setPreset, setDays, setCompare,
  }
}

/** Test seam: forget what this tab remembers. */
export function resetPeriodSession(): void {
  session.clear()
  sessionRecent.clear()
}
