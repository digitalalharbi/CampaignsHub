import { MONTHS_AR, MONTHS_EN, matchPreset, parseYmd, presetLabel, riyadhToday, type PeriodKey } from './periodMath'

/**
 * PERIOD-LABEL-001 — the ONE way a period is written, on every surface (Owner order 2026-11-19).
 *
 *   «{اسم الاختيار}: {تاريخ البداية} – {تاريخ النهاية} {السنة}»
 *
 *   آخر 7 أيام: 12 نوفمبر – 18 نوفمبر 2026        Last 7 days: Nov 12 – Nov 18, 2026
 *   أمس: 18 نوفمبر 2026                           (a one-day period is one date)
 *   15 ديسمبر 2025 – 14 يناير 2026                (two years: both years; a custom range has NO name)
 *
 * Latin digits, Gregorian, the en-dash «–», the year once at the end (written on the first date too only when the
 * two ends are in different years). No `Intl`: it is identical in every engine and never swaps the digits.
 *
 * The PHP twin is `App\Support\PeriodLabel`; both read the same examples from `periodLabelExamples.json`, so the
 * two cannot drift. Do not write a period any other way — `periodLabelGuard.test.ts` fails the build when a file
 * formats one by hand.
 */

export type PeriodLocale = 'ar' | 'en'

export interface PeriodLabelInput {
  /** The preset the person chose. Absent or `custom` → a custom range (dates only) unless `inferName` is set. */
  key?: PeriodKey | null
  from: string
  to: string
  locale: PeriodLocale
  /** Today's Riyadh date — only used with `inferName`. Injectable so a test holds the clock still. */
  today?: string
  /** Name the period after the preset its dates match today (a page that has dates but not the person's choice). */
  inferName?: boolean
}

function day(s: string, ar: boolean, withYear: boolean): string {
  const p = parseYmd(s)
  if (!p) return s
  if (ar) return `${p.d} ${MONTHS_AR[p.m - 1]}${withYear ? ` ${p.y}` : ''}`

  return `${MONTHS_EN[p.m - 1].slice(0, 3)} ${p.d}${withYear ? `, ${p.y}` : ''}`
}

/** The two dates alone: «12 نوفمبر – 18 نوفمبر 2026». The label of a custom range, and the tail of every other. */
export function formatPeriodRange(from: string, to: string, locale: PeriodLocale): string {
  const ar = locale === 'ar'
  const [a, b] = from <= to ? [from, to] : [to, from]
  if (a === b) return day(a, ar, true)
  const pa = parseYmd(a)
  const pb = parseYmd(b)
  if (!pa || !pb) return `${a} – ${b}`

  return `${day(a, ar, pa.y !== pb.y)} – ${day(b, ar, true)}`
}

export function formatPeriodLabel(input: PeriodLabelInput): string {
  const { from, to, locale } = input
  const range = formatPeriodRange(from, to, locale)
  let key: PeriodKey | null = input.key && input.key !== 'custom' ? input.key : null
  if (!key && input.inferName) key = matchPreset({ from, to }, { today: input.today ?? riyadhToday() })
  if (!key) return range

  return `${presetLabel(key, locale === 'ar')}: ${range}`
}

/** A range that may be open at either end (a campaign's flight, a contract): «1 نوفمبر – …». Both ends → the normal range. */
export function formatPeriodBounds(from: string | null | undefined, to: string | null | undefined, locale: PeriodLocale): string {
  if (from && to) return formatPeriodRange(from, to, locale)
  const ar = locale === 'ar'
  if (from) return `${day(from, ar, true)} – …`
  if (to) return `… – ${day(to, ar, true)}`

  return ''
}
