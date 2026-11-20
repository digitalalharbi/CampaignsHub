import type { LivePayload } from '@/features/reports/api'
import { metricName, type Reader, type ResultSource } from './results'

export type TrendOption = { key: string; name: string; color: string; kind: 'num' }

/**
 * The daily chart's metric switch: the report's result first (orders on a sales objective), then clicks, then impressions —
 * a result with no daily figure of its own (defined Results) is left out so the line is never drawn empty.
 */
export function trendOptions(payload: LivePayload, reader: Reader, source: ResultSource, ar: boolean): TrendOption[] {
  const result = source.key

  return [
    ...(result === 'results' ? [] : [{ key: result, name: source.key === 'form_leads' ? source.name : metricName(reader, result, ar, payload), color: 'var(--purple)', kind: 'num' as const }]),
    ...(result !== 'clicks' ? [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }] : []),
    ...(result !== 'impressions' ? [{ key: 'impressions', name: ar ? 'الظهور' : 'Impressions', color: 'var(--brand-600)', kind: 'num' as const }] : []),
  ]
}

/** The larger-scale series takes the second axis, so a few hundred results are not flattened under thousands of clicks. */
export function rightAxisFor(on: string[]): string | undefined {
  return on.length > 1 ? (on.includes('impressions') ? 'impressions' : on.includes('clicks') ? 'clicks' : undefined) : undefined
}

/** Toggle one metric on or off; the last one standing cannot be switched off. */
export function toggleTrend(current: string[], key: string): string[] {
  return current.includes(key) ? (current.length > 1 ? current.filter((k) => k !== key) : current) : [...current, key]
}

/** One metric's daily points, as numbers (a missing day is null, never 0), for a single-metric switch. */
export function dailyPoints(payload: LivePayload, key: string): Array<{ date: string; value: number | null }> {
  return (payload.timeseries ?? []).map((row) => ({ date: String(row.date ?? ''), value: row[key] === null || row[key] === undefined ? null : Number(row[key]) }))
}
