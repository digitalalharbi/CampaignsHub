import type { LivePayload } from '@/features/reports/api'
import { cardHasFigure, metricLabel } from '@/features/reports/live/liveMetrics'
import { revenueReported } from '@/features/reports/live/leadResults'
import { headlineKeys, type Reader } from './results'

export type BoardKpi = {
  key: string
  label: string
  /** The report's own reading of the figure (`meta.format`): compact text and the exact value behind it. */
  value: string
  exact: string | null
  /** Period-over-period ratio; undefined where the windows may not be compared. */
  delta: number | null | undefined
  invertGood: boolean
}

/**
 * The five key indicators — spend, clicks, the objective's result (orders on a sales objective), revenue, return on spend —
 * each read through the report's own metric catalogue, so the text on the board is the text on the report. Revenue and return
 * leave when no platform reported revenue; a figure the period does not carry is left out rather than printed as zero.
 */
export function boardKpis(payload: LivePayload, reader: Reader, ar: boolean): BoardKpi[] {
  const { result } = headlineKeys(payload, reader)
  const t = payload.totals ?? {}
  // «Orders» and «Results» are one figure on a sales objective: the same count is never shown twice.
  const sameAsResult = (k: string) => (k === 'purchases' || k === 'conversions') && (result === 'purchases' || result === 'conversions') && Number(t[k]) === Number(t[result])
  const keys = [...new Set(['spend', 'clicks', result, 'purchases', 'revenue', 'roas'])].filter((k) => k === result || !sameAsResult(k))
  const withheld = payload.comparison !== undefined && !payload.comparison.comparable
  const d: Record<string, number | null | undefined> = withheld ? {} : (payload.deltas ?? {})

  return keys.flatMap((key): BoardKpi[] => {
    const meta = reader.meta[key]
    if (!meta) return []
    if (!revenueReported(payload) && (key === 'revenue' || key === 'roas')) return []
    const read = meta.format(payload.totals, payload, reader.asMoney, reader.count)
    if (!cardHasFigure(key, read.text, payload)) return []

    return [{ key, label: metricLabel(meta, key, payload, ar), value: read.text, exact: read.exact, delta: d[key], invertGood: meta.invertGood === true }]
  })
}
