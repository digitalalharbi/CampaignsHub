/**
 * DATE-RANGE-001 — the one place that decides what a period IS.
 *
 * ## Why the platforms never matched
 *
 * Every page offered «7 / 30 / 90 days» counted back from TODAY, so «30 days» on 15 November was
 * 17 October → 15 November. The ad platforms end their «last 30 days» YESTERDAY (16 October → 14
 * November), because today is not finished. Same label, different days, different spend — and nobody
 * could tell which of two figures was wrong.
 *
 * ## The rule, written once
 *
 * Every rolling preset ends YESTERDAY. The only presets that include today are the ones that say so in
 * their name («اليوم», «اليوم وأمس», «هذا الأسبوع», «هذا الشهر»), and those are flagged `ongoing`, which is
 * what puts the «جاري — غير مكتمل» badge on their numbers.
 *
 * ## Which clock
 *
 * The PERIOD is Riyadh time (GMT+3, no daylight saving) whatever the browser's zone is: a person in
 * London opening the product at 23:30 their time is already on tomorrow's date in Riyadh. The DATES of
 * the data are the platform account's own (an account in another zone keeps its own day boundary) —
 * the coverage endpoint flags those accounts instead of pretending they line up.
 *
 * Everything here is arithmetic on `YYYY-MM-DD` strings built with `Date.UTC(y, m, d)`. Never
 * `new Date('2026-11-15')` on a bare date: it is UTC in one engine and local in another, and Safari
 * refuses «2026-11-5» altogether. The backend twin is `App\Domains\Metrics\Services\PeriodResolver`;
 * both are pinned to the same fixtures on the same fixed clock.
 */

export const RIYADH_OFFSET_HOURS = 3

export type PresetKey =
  | 'today' | 'yesterday' | 'today_yesterday'
  | 'last_7' | 'last_14' | 'last_28' | 'last_30' | 'last_90'
  | 'this_week' | 'last_week' | 'this_month' | 'last_month'
  | 'last_3m' | 'last_6m' | 'last_12m' | 'ytd' | 'lifetime'

export type PeriodKey = PresetKey | 'custom'

export interface DateSpan { from: string; to: string }

export type CompareMode = 'none' | 'previous' | 'last_year' | 'custom'

export interface Period {
  key: PeriodKey
  from: string
  to: string
  /** The period contains today (Riyadh) — its newest day is not finished, so its numbers are not final. */
  ongoing: boolean
}

export interface Comparison {
  mode: Exclude<CompareMode, 'none'>
  from: string
  to: string
}

/** The order the panel lists them in, and what each is called. `ongoing` ones are the only ones that include today. */
export const PRESETS: ReadonlyArray<{ key: PresetKey; ar: string; en: string; includesToday: boolean }> = [
  { key: 'today', ar: 'اليوم (جاري)', en: 'Today (in progress)', includesToday: true },
  { key: 'yesterday', ar: 'أمس', en: 'Yesterday', includesToday: false },
  { key: 'today_yesterday', ar: 'اليوم وأمس', en: 'Today and yesterday', includesToday: true },
  { key: 'last_7', ar: 'آخر 7 أيام', en: 'Last 7 days', includesToday: false },
  { key: 'last_14', ar: 'آخر 14 يوم', en: 'Last 14 days', includesToday: false },
  { key: 'last_28', ar: 'آخر 28 يوم', en: 'Last 28 days', includesToday: false },
  { key: 'last_30', ar: 'آخر 30 يوم', en: 'Last 30 days', includesToday: false },
  { key: 'last_90', ar: 'آخر 90 يوم', en: 'Last 90 days', includesToday: false },
  { key: 'this_week', ar: 'هذا الأسبوع', en: 'This week', includesToday: true },
  { key: 'last_week', ar: 'الأسبوع الماضي', en: 'Last week', includesToday: false },
  { key: 'this_month', ar: 'هذا الشهر', en: 'This month', includesToday: true },
  { key: 'last_month', ar: 'الشهر الماضي', en: 'Last month', includesToday: false },
  { key: 'last_3m', ar: 'آخر 3 أشهر', en: 'Last 3 months', includesToday: false },
  { key: 'last_6m', ar: 'آخر 6 أشهر', en: 'Last 6 months', includesToday: false },
  { key: 'last_12m', ar: 'آخر 12 شهر', en: 'Last 12 months', includesToday: false },
  { key: 'ytd', ar: 'من بداية السنة', en: 'Year to date', includesToday: false },
  { key: 'lifetime', ar: 'طوال المدة', en: 'All time', includesToday: false },
]

/** A new user's period: the last seven COMPLETE days. */
export const DEFAULT_PRESET: PresetKey = 'last_7'

/** Where «طوال المدة» starts when the page does not know its earliest day. */
export const LIFETIME_FLOOR = '2024-01-01'

export const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
export const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
/** Week starts Sunday: the Saudi week. */
export const WEEKDAYS_AR = ['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت']
export const WEEKDAYS_EN = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']

const pad = (n: number, w = 2) => String(n).padStart(w, '0')

export const ymd = (y: number, m: number, d: number): string => `${pad(y, 4)}-${pad(m)}-${pad(d)}`

export function parseYmd(s: string): { y: number; m: number; d: number } | null {
  const hit = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s.trim())
  if (!hit) return null
  const y = Number(hit[1]); const m = Number(hit[2]); const d = Number(hit[3])
  if (m < 1 || m > 12 || d < 1 || d > daysInMonth(y, m)) return null

  return { y, m, d }
}

export const isValidYmd = (s: string): boolean => parseYmd(s) !== null

export function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate()
}

/** Days since 1970-01-01 — the integer every comparison and offset below is done on. */
function toEpochDay(s: string): number {
  const p = parseYmd(s)
  if (!p) throw new RangeError(`not a date: ${s}`)

  return Math.round(Date.UTC(p.y, p.m - 1, p.d) / 86_400_000)
}

function fromEpochDay(n: number): string {
  const d = new Date(n * 86_400_000)

  return ymd(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate())
}

export const addDays = (s: string, n: number): string => fromEpochDay(toEpochDay(s) + n)
export const diffDays = (a: string, b: string): number => toEpochDay(b) - toEpochDay(a)
/** Inclusive number of days in a span. */
export const spanLength = (r: DateSpan): number => diffDays(r.from, r.to) + 1
/** 0 = Sunday … 6 = Saturday. */
export const weekday = (s: string): number => ((toEpochDay(s) + 4) % 7 + 7) % 7

/** Same day N months earlier/later, clamped to the month's last day (31 May − 3 months → 28/29 Feb). */
export function addMonths(s: string, n: number): string {
  const p = parseYmd(s)
  if (!p) throw new RangeError(`not a date: ${s}`)
  const idx = p.y * 12 + (p.m - 1) + n
  const y = Math.floor(idx / 12)
  const m = (idx % 12 + 12) % 12 + 1

  return ymd(y, m, Math.min(p.d, daysInMonth(y, m)))
}

export const addYears = (s: string, n: number): string => addMonths(s, n * 12)
export const startOfMonth = (s: string): string => `${s.slice(0, 8)}01`
export const endOfMonth = (s: string): string => { const p = parseYmd(s)!; return ymd(p.y, p.m, daysInMonth(p.y, p.m)) }
export const startOfWeek = (s: string): string => addDays(s, -weekday(s))

/** Today's DATE in Riyadh for an instant — whatever zone the browser is in. */
export function riyadhToday(now: Date = new Date()): string {
  const shifted = new Date(now.getTime() + RIYADH_OFFSET_HOURS * 3_600_000)

  return ymd(shifted.getUTCFullYear(), shifted.getUTCMonth() + 1, shifted.getUTCDate())
}

export interface ResolveContext {
  /** Today's Riyadh date. Injected so a test can hold the clock still. */
  today: string
  /** First day the project has data, when the page knows it — «طوال المدة» starts there. */
  earliest?: string | null
}

/** A preset's days. Every one ends yesterday unless its name says «اليوم» or «هذا». */
export function resolvePreset(key: PresetKey, ctx: ResolveContext): DateSpan {
  const t = ctx.today
  const y = addDays(t, -1)

  switch (key) {
    case 'today': return { from: t, to: t }
    case 'yesterday': return { from: y, to: y }
    case 'today_yesterday': return { from: y, to: t }
    case 'last_7': return { from: addDays(t, -7), to: y }
    case 'last_14': return { from: addDays(t, -14), to: y }
    case 'last_28': return { from: addDays(t, -28), to: y }
    case 'last_30': return { from: addDays(t, -30), to: y }
    case 'last_90': return { from: addDays(t, -90), to: y }
    case 'this_week': return { from: startOfWeek(t), to: t }
    case 'last_week': { const s = addDays(startOfWeek(t), -7); return { from: s, to: addDays(s, 6) } }
    case 'this_month': return { from: startOfMonth(t), to: t }
    case 'last_month': { const prev = addMonths(startOfMonth(t), -1); return { from: prev, to: endOfMonth(prev) } }
    case 'last_3m': return { from: addMonths(t, -3), to: y }
    case 'last_6m': return { from: addMonths(t, -6), to: y }
    case 'last_12m': return { from: addMonths(t, -12), to: y }
    case 'ytd': {
      const from = `${t.slice(0, 4)}-01-01`
      // On 1 January there is no complete day of the new year: show today alone rather than last year.
      return { from, to: y < from ? t : y }
    }
    case 'lifetime': {
      const from = ctx.earliest && isValidYmd(ctx.earliest) && ctx.earliest <= y ? ctx.earliest : LIFETIME_FLOOR
      return { from, to: y }
    }
  }
}

export function presetPeriod(key: PresetKey, ctx: ResolveContext): Period {
  const span = resolvePreset(key, ctx)

  return { key, ...span, ongoing: span.to >= ctx.today }
}

export function customPeriod(span: DateSpan, ctx: ResolveContext): Period {
  const ordered = span.from <= span.to ? span : { from: span.to, to: span.from }

  return { key: 'custom', ...ordered, ongoing: ordered.to >= ctx.today }
}

/** The preset a span corresponds to TODAY, if any. A shared link keeps its dates; the label is inferred, never stored. */
export function matchPreset(span: DateSpan, ctx: ResolveContext): PresetKey | null {
  for (const p of PRESETS) {
    if (p.key === 'lifetime') continue
    const r = resolvePreset(p.key, ctx)
    if (r.from === span.from && r.to === span.to) return p.key
  }

  return null
}

/** The comparison span for a mode: the same number of days straight before, or the same dates a year earlier. */
export function resolveComparison(period: DateSpan, mode: Exclude<CompareMode, 'none'>, custom?: DateSpan | null): Comparison | null {
  if (mode === 'custom') {
    if (!custom || !isValidYmd(custom.from) || !isValidYmd(custom.to)) return null
    const ordered = custom.from <= custom.to ? custom : { from: custom.to, to: custom.from }

    return { mode, ...ordered }
  }

  if (mode === 'previous') {
    const len = spanLength(period)

    return { mode, from: addDays(period.from, -len), to: addDays(period.from, -1) }
  }

  return { mode, from: addYears(period.from, -1), to: addYears(period.to, -1) }
}

/** Percent change of `current` against `previous`; null when there is no baseline to divide by. */
export function percentChange(current: number | null | undefined, previous: number | null | undefined): number | null {
  if (current === null || current === undefined || previous === null || previous === undefined) return null
  if (!Number.isFinite(current) || !Number.isFinite(previous) || previous === 0) return null

  return ((current - previous) / Math.abs(previous)) * 100
}

/** «16 أكتوبر 2026» / «16 Oct 2026». Latin digits, Gregorian, no Intl — identical in every engine. */
export function formatDay(s: string, ar: boolean, withYear = true): string {
  const p = parseYmd(s)
  if (!p) return s
  const month = (ar ? MONTHS_AR : MONTHS_EN)[p.m - 1]
  const short = ar ? month : month.slice(0, 3)

  return `${p.d} ${short}${withYear ? ` ${p.y}` : ''}`
}

/** «16 أكتوبر – 14 نوفمبر 2026»; the year is stated on each end only when the two differ. */
export function formatSpan(span: DateSpan, ar: boolean): string {
  if (span.from === span.to) return formatDay(span.from, ar)
  const a = parseYmd(span.from); const b = parseYmd(span.to)
  if (!a || !b) return `${span.from} – ${span.to}`
  const sameYear = a.y === b.y

  return `${formatDay(span.from, ar, !sameYear)} – ${formatDay(span.to, ar)}`
}

export function presetLabel(key: PeriodKey, ar: boolean): string {
  if (key === 'custom') return ar ? 'مخصص' : 'Custom'
  const p = PRESETS.find((x) => x.key === key)

  return p ? (ar ? p.ar.replace(' (جاري)', '') : p.en.replace(' (in progress)', '')) : ''
}

/** The button's text: «آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026». */
export function periodSummary(period: Period, ar: boolean): string {
  return `${presetLabel(period.key, ar)}: ${formatSpan(period, ar)}`
}

export const COMPARE_LABEL: Record<Exclude<CompareMode, 'none'>, { ar: string; en: string }> = {
  previous: { ar: 'الفترة السابقة', en: 'Previous period' },
  last_year: { ar: 'نفس الفترة من السنة الماضية', en: 'Same period last year' },
  custom: { ar: 'فترة مخصصة', en: 'Custom period' },
}
