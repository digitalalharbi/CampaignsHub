import { addDays, addMonths, addYears, spanLength } from '@/lib/period/periodMath'

/**
 * REPORT-LOGIC-001 — «مقارنة»: one side against another, from real numbers only.
 *
 * A missing side is `na`, never zero and never an estimate. A percentage needs a non-zero baseline: against a baseline of
 * zero the difference exists but its size does not, so `pct` is null and the screen says so.
 */
export type CompareDirection = 'up' | 'down' | 'flat' | 'na'
export type Comparison = { a: number | null; b: number | null; diff: number | null; pct: number | null; direction: CompareDirection }

const real = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)

/** A against B: the difference is A minus B and the percentage is relative to B (the baseline). */
export function compareFigure(a: unknown, b: unknown): Comparison {
  const x = real(a)
  const y = real(b)
  if (x === null || y === null) return { a: x, b: y, diff: null, pct: null, direction: 'na' }
  const diff = x - y

  return { a: x, b: y, diff, pct: y === 0 ? null : diff / Math.abs(y), direction: diff === 0 ? 'flat' : diff > 0 ? 'up' : 'down' }
}

/** Whether a move is good or bad depends on the metric: more spend per result is worse. Neutral for a flat or unavailable figure. */
export function comparisonTone(c: Comparison, invertGood: boolean): 'good' | 'bad' | 'neutral' {
  if (c.direction === 'na' || c.direction === 'flat') return 'neutral'

  return (c.direction === 'up') !== invertGood ? 'good' : 'bad'
}

export type CompareSide = { label: string; totals: Record<string, number | null> | null; daily: Array<Record<string, unknown>> }

/** Every key both sides could carry, compared; a side that returned nothing makes every row unavailable. */
export function compareTotals(a: CompareSide, b: CompareSide, keys: string[]): Array<{ key: string } & Comparison> {
  return keys.map((key) => ({ key, ...compareFigure(a.totals?.[key], b.totals?.[key]) }))
}

/**
 * Two sides' daily lines laid over each other by DAY NUMBER (day 1 of each side), so two different periods share one axis.
 * A day one side does not have is null — the line breaks there rather than dropping to zero.
 */
export function overlayDaily(a: Array<Record<string, unknown>>, b: Array<Record<string, unknown>>, key: string): Array<{ day: number; a: number | null; b: number | null }> {
  const n = Math.max(a.length, b.length)

  return Array.from({ length: n }, (_, i) => ({ day: i + 1, a: real(a[i]?.[key]), b: real(b[i]?.[key]) }))
}

/** The baseline window of a period comparison, the same length as the page's period. */
export type DateRange = { from: string; to: string }

export function baselineRange(range: DateRange, key: string): DateRange {
  if (key === 'month') return { from: addMonths(range.from, -1), to: addMonths(range.to, -1) }
  if (key === 'year') return { from: addYears(range.from, -1), to: addYears(range.to, -1) }
  const n = spanLength(range)

  return { from: addDays(range.from, -n), to: addDays(range.to, -n) }
}
