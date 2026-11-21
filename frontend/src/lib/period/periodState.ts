import {
  DEFAULT_PRESET, customPeriod, isValidYmd, matchPreset, presetPeriod, resolveComparison,
  type CompareMode, type Comparison, type DateSpan, type Period, type PeriodKey, type PresetKey, type ResolveContext,
} from './periodMath'

/**
 * DATE-RANGE-001 — what the person picked, how it travels, and where it is kept.
 *
 * A selection is the CHOICE (a preset name, or two dates) rather than the days it resolves to today,
 * so «آخر 7 أيام» is still the last seven complete days tomorrow, while a custom range stays exactly
 * the two days that were typed.
 *
 * It travels in the page address as `from`, `to`, `compare` (and `cfrom`/`cto` for a custom
 * comparison), so a link opens the same period for whoever receives it. A link carries DATES, not
 * a preset name: on the day it is opened the label is inferred again ({@see matchPreset}), and a link
 * sent yesterday reads as the two dates it was, rather than silently sliding by a day.
 */
export interface Selection {
  key: PeriodKey
  /** Present when `key` is `custom`. A preset has no stored dates — it is resolved against the clock. */
  from?: string
  to?: string
  compare: CompareMode
  cfrom?: string
  cto?: string
}

export const DEFAULT_SELECTION: Selection = { key: DEFAULT_PRESET, compare: 'none' }

export const URL_KEYS = ['from', 'to', 'compare', 'cfrom', 'cto'] as const
const COMPARE_MODES: ReadonlyArray<CompareMode> = ['none', 'previous', 'last_year', 'custom']

export interface Resolved {
  period: Period
  comparison: Comparison | null
}

export function resolveSelection(sel: Selection, ctx: ResolveContext): Resolved {
  const period = sel.key !== 'custom'
    ? presetPeriod(sel.key, ctx)
    : customPeriod({ from: sel.from ?? ctx.today, to: sel.to ?? ctx.today }, ctx)

  const comparison = sel.compare === 'none'
    ? null
    : resolveComparison(period, sel.compare, sel.cfrom && sel.cto ? { from: sel.cfrom, to: sel.cto } : null)

  return { period, comparison }
}

/** A selection from page-address parameters, or null when the address names no period (so the stored one applies). */
export function selectionFromParams(params: URLSearchParams, ctx: ResolveContext): Selection | null {
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  if (!isValidYmd(from) || !isValidYmd(to)) return legacyDays(params)

  const span: DateSpan = from <= to ? { from, to } : { from: to, to: from }
  const preset = matchPreset(span, ctx)
  const rawCompare = (params.get('compare') ?? 'none') as CompareMode
  const compare = COMPARE_MODES.includes(rawCompare) ? rawCompare : 'none'
  const cfrom = params.get('cfrom') ?? ''
  const cto = params.get('cto') ?? ''

  const sel: Selection = preset ? { key: preset, compare } : { key: 'custom', ...span, compare }
  if (compare === 'custom') {
    if (isValidYmd(cfrom) && isValidYmd(cto)) { sel.cfrom = cfrom; sel.cto = cto } else sel.compare = 'none'
  }

  return sel
}

/** Old links carried `?days=7|30|90`. They map onto the matching rolling preset (which now ends yesterday). */
function legacyDays(params: URLSearchParams): Selection | null {
  const n = Number(params.get('days'))
  const key = ({ 7: 'last_7', 14: 'last_14', 28: 'last_28', 30: 'last_30', 90: 'last_90' } as Record<number, PresetKey>)[n]

  return key ? { key, compare: 'none' } : null
}

/** Write the selection's resolved days into a set of parameters, replacing whatever period keys were there. */
export function paramsWithPeriod(current: URLSearchParams, resolved: Resolved, compare: CompareMode): URLSearchParams {
  const out = new URLSearchParams(current)
  for (const k of [...URL_KEYS, 'days']) out.delete(k)
  out.set('from', resolved.period.from)
  out.set('to', resolved.period.to)
  if (resolved.comparison && compare !== 'none') {
    out.set('compare', compare)
    if (compare === 'custom') { out.set('cfrom', resolved.comparison.from); out.set('cto', resolved.comparison.to) }
  }

  return out
}

/* ── Storage: per user, never trusted, never required ─────────────────────────────────────────── */

const KEY = (userId: string | null) => `ch.period.v1.${userId ?? 'anon'}`
const RECENT_MAX = 3

export interface Stored { selection: Selection; recent: Selection[] }

function sane(x: unknown): Selection | null {
  if (!x || typeof x !== 'object') return null
  const o = x as Record<string, unknown>
  const compare = COMPARE_MODES.includes(o.compare as CompareMode) ? (o.compare as CompareMode) : 'none'
  if (o.key === 'custom') {
    if (typeof o.from !== 'string' || typeof o.to !== 'string' || !isValidYmd(o.from) || !isValidYmd(o.to)) return null

    return { key: 'custom', from: o.from, to: o.to, compare, ...(compare === 'custom' && typeof o.cfrom === 'string' && typeof o.cto === 'string' && isValidYmd(o.cfrom) && isValidYmd(o.cto) ? { cfrom: o.cfrom, cto: o.cto } : {}) }
  }
  if (typeof o.key !== 'string') return null
  const known: PresetKey[] = ['today', 'yesterday', 'today_yesterday', 'last_7', 'last_14', 'last_28', 'last_30', 'last_90', 'this_week', 'last_week', 'this_month', 'last_month', 'last_3m', 'last_6m', 'last_12m', 'ytd', 'this_year', 'lifetime']
  if (!known.includes(o.key as PresetKey)) return null

  return { key: o.key as PresetKey, compare: compare === 'custom' ? 'none' : compare }
}

export function loadStored(userId: string | null): Stored | null {
  try {
    const raw = window.localStorage.getItem(KEY(userId))
    if (!raw) return null
    const parsed = JSON.parse(raw) as { selection?: unknown; recent?: unknown }
    const selection = sane(parsed.selection)
    if (!selection) return null
    const recent = Array.isArray(parsed.recent) ? parsed.recent.map(sane).filter((s): s is Selection => s !== null).slice(0, RECENT_MAX) : []

    return { selection, recent }
  } catch {
    return null
  }
}

const sameChoice = (a: Selection, b: Selection) => a.key === b.key && (a.key !== 'custom' || (a.from === b.from && a.to === b.to))

/** Newest first, no repeats, at most three — «مستخدمة مؤخرًا». */
export function pushRecent(recent: Selection[], sel: Selection): Selection[] {
  return [sel, ...recent.filter((r) => !sameChoice(r, sel))].slice(0, RECENT_MAX)
}

export function saveStored(userId: string | null, stored: Stored): void {
  try {
    window.localStorage.setItem(KEY(userId), JSON.stringify(stored))
  } catch {
    /* private mode, quota, blocked storage: the period still works for this session, it just is not remembered */
  }
}
