import { describe, expect, it } from 'vitest'

/**
 * PERIOD-LABEL-001 — a period is written ONE way: `formatPeriodLabel` / `formatPeriodRange` / `<PeriodText>`.
 *
 * Any other file that joins two dates with an arrow or a dash, or formats a date range with the browser's
 * `toLocaleDateString`/`Intl.DateTimeFormat`, is a hand-written period and fails here. The allowlist is for
 * lines that are NOT periods; every entry says why.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })

function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, '')).replace(/(^|\s)\/\/.*$/gm, '$1')
}

/** The start / end operand names a date pair is written with in this codebase. */
const START = String.raw`(?:from|start|since|period_start|window_start|window_from|starts_on|\.from|\bfrom)`
const END = String.raw`(?:to|end|until|period_end|window_end|window_to|ends_on|\.to|\bto)`
const JOINER = String.raw`(?:→|–|—|–|—|→| - )`
/** `{x.from} → {x.to}`, `${a.from} – ${a.to}`, `from + ' – ' + to`, `.from ?? '…'} → {…to`. */
const GAP = String.raw`(?:[^\w\s]|[ \t]|\w+(?:\?\.|\.))*?`
export const HAND_WRITTEN_PERIOD = new RegExp(`${START}${GAP}${JOINER}${GAP}${END}\\b`, 'u')
/** A date range through the browser's formatter. */
export const BROWSER_DATE_RANGE = /(?:toLocaleDateString|DateTimeFormat)\([^\n]*\)[^\n]{0,40}(?:→|–|—)[^\n]*(?:toLocaleDateString|DateTimeFormat)|formatRange(?:ToParts)?\(/u

/** «path-suffix:needle» — a line holding the needle is skipped, and WHY is stated beside it. */
const ALLOW: ReadonlyArray<string> = [
  // A table's «1–25 / 400» row counter: row numbers, not dates.
  'components/ui/DataTable.tsx:{from}–{to} / {totalRows}',
  'components/ui/FixedPageTable.tsx:{from}–{to} / {shown.length}',
  // A currency pair («USD→SAR») and a time-zone pair, both drawn from a `.from`/`.to` field that is not a date.
  'features/analytics/AnalyticsPage.tsx:converted.map((c) => `${c.from}→${c.to}`)',
  'features/analytics/AnalyticsPage.tsx:shifted.map((t) => `${t.from}→${t.to}`)',
]

const isAllowed = (path: string, line: string): boolean =>
  ALLOW.some((entry) => { const i = entry.indexOf(':'); return path.endsWith(entry.slice(0, i)) && line.includes(entry.slice(i + 1)) })

const files = Object.entries(TREE).filter(([path]) =>
  !/\.test\.(ts|tsx)$/.test(path) && !path.includes('/src/test/') && !path.includes('/src/lib/period/') && !path.endsWith('/components/ui/PeriodText.tsx'))

describe('no hand-written period', () => {
  it('no source file joins a start and an end date itself', () => {
    const offenders = files.flatMap(([path, source]) => code(source).split('\n')
      .map((line, i) => [i + 1, line] as const)
      .filter(([, line]) => HAND_WRITTEN_PERIOD.test(line) && !isAllowed(path, line))
      .map(([n, line]) => `${path}:${n}  ${line.trim().slice(0, 140)}`))

    expect(offenders, 'write a period with formatPeriodLabel / formatPeriodRange / <PeriodText> (src/lib/period/formatPeriodLabel.ts)').toEqual([])
  })

  it('no source file formats a date range with the browser formatter', () => {
    const offenders = files.flatMap(([path, source]) => code(source).split('\n')
      .map((line, i) => [i + 1, line] as const)
      .filter(([, line]) => BROWSER_DATE_RANGE.test(line) && !isAllowed(path, line))
      .map(([n, line]) => `${path}:${n}  ${line.trim().slice(0, 140)}`))

    expect(offenders).toEqual([])
  })

  it('the guard recognises a hand-written period (and leaves a single date and a sentence alone)', () => {
    for (const bad of [
      'const s = `${r.period.from} → ${r.period.to}`',
      '<span>{d.previous.from} – {d.previous.to}</span>',
      "const x = from + ' – ' + to",
      '{c.starts_on ?? "…"} — {c.ends_on ?? "…"}',
    ]) expect(HAND_WRITTEN_PERIOD.test(bad), bad).toBe(true)
    for (const ok of [
      'const s = formatPeriodRange(r.period.from, r.period.to, locale)',
      '<PeriodText from={d.from} to={d.to} />',
      'Visits are counted since the first of the month',
      'navigate to the page',
    ]) expect(HAND_WRITTEN_PERIOD.test(ok), ok).toBe(false)
  })
})
