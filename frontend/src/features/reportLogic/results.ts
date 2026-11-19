import { canonicalPlatform } from '@/lib/platforms'
import type { LivePayload } from '@/features/reports/api'
import { metricLabel, type useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import { NATIVE_LEADS_LABEL, nativeLeads, resultWords } from '@/features/reports/live/leadResults'

/**
 * REPORT-LOGIC-001 — the report's result logic, moved here so the analytics board, the report and the content
 * library read ONE implementation. Nothing in this file is new: it is `ExecutiveSummary`'s own code, lifted out so
 * that a figure cannot be derived two ways. `ExecutiveSummary` re-exports every name below from its old place.
 */
export type Reader = ReturnType<typeof useLiveMetricReader>

/** The result an objective is judged on, in the order the reader's keys may name them. */
export const RESULT_KEYS = ['purchases', 'conversions', 'leads', 'installs', 'landing_page_views', 'engagements', 'clicks', 'reach', 'impressions']
/** Each result's cost, as the totals carry it. */
export const COST_FOR: Record<string, string> = {
  purchases: 'cpa', conversions: 'cpa', leads: 'cpl', installs: 'cpi', landing_page_views: 'cost_per_lpv',
  engagements: 'cpe', clicks: 'cpc', reach: 'cpm', impressions: 'cpm',
}
/** The results board, after the headline result: outcomes first, then the reach that led to them. */
export const BOARD_KEYS = ['revenue', 'purchases', 'leads', 'installs', 'landing_page_views', 'engagements', 'reach', 'impressions', 'clicks', 'ctr', 'conversion_rate']
/** Spend and every figure derived from it. */
export const SPEND_DERIVED = new Set(['spend', 'roas', 'cpa', 'cpm', 'cpc', 'cpl', 'cpi', 'cpe', 'cost_per_lpv', 'budget', 'profit', 'mer', 'blended_roas'])

export function headlineKeys(payload: LivePayload, reader: Reader): { result: string; cost: string } {
  // NASEL 2026-10-29 — a link without Results never heads its summary with the platform's generic conversion.
  const keys = reader.keys(payload).filter((k) => k !== 'conversions' || payload.results?.configured === true)
  const totals = payload.totals ?? {}
  // The objective's own result first, in RESULT_KEYS' priority — a reach figure never outranks an order.
  const result = RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined && Number(totals[k]) > 0)
    ?? RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined)
    ?? 'clicks'

  return { result, cost: COST_FOR[result] ?? 'cpa' }
}

/** The board's keys: the headline result, then every result figure the period actually carries. */
export function resultBoardKeys(payload: LivePayload, reader: Reader, result: string): string[] {
  const t = payload.totals ?? {}
  // «Results» and «Purchases» are one figure on a sales objective; the same count is not shown twice.
  const sameAsResult = (k: string) => (k === 'purchases' || k === 'conversions') && (result === 'purchases' || result === 'conversions') && Number(t[k]) === Number(t[result])
  const carried = BOARD_KEYS.filter((k) => k !== result && !sameAsResult(k) && reader.meta[k] && !SPEND_DERIVED.has(k) && t[k] !== null && t[k] !== undefined && Number(t[k]) > 0)

  return [result, ...carried].slice(0, 8)
}

/**
 * What the platforms summary, the split and the comparison count, read ONCE. Where verified leads are configured it is the server's native
 * lead-form figure, per platform and in total; where Results are defined on an event it is the resolver's; otherwise the objective's own result.
 */
export type ResultSource = { key: string; name: string; of: (p: Record<string, unknown>) => number | null; total: number | null }

export const count = (v: unknown) => (v === null || v === undefined ? null : Number(v))

export const metricName = (reader: Reader, key: string, ar: boolean, payload?: LivePayload) => (reader.meta[key] ? metricLabel(reader.meta[key], key, payload, ar) : key)

export function resultSource(payload: LivePayload, reader: Reader, result: string, ar: boolean): ResultSource {
  const native = nativeLeads(payload)
  if (native) {
    return { key: 'form_leads', name: ar ? NATIVE_LEADS_LABEL.ar : NATIVE_LEADS_LABEL.en, of: (p) => native.of(String(p.provider ?? '')), total: native.total }
  }

  // RESULT-EVENTS-001 — Results defined on an event: the resolver's per-platform results under the link's own name.
  if (payload.results?.configured === true) {
    const words = resultWords(payload, ar)
    return { key: 'results', name: words.result, of: (p) => payload.results?.by_platform?.[canonicalPlatform(String(p.provider ?? ''))]?.results ?? null, total: payload.results.total ?? null }
  }

  return { key: result, name: metricName(reader, result, ar, payload), of: (p) => count(p[result]), total: count(payload.totals?.[result]) }
}

/** A platform is shown when it delivered anything or produced the result. */
export const shownPlatform = (p: Record<string, unknown>, source: ResultSource) => Number(p.impressions ?? 0) > 0 || Number(p.clicks ?? 0) > 0 || Number(source.of(p) ?? 0) > 0
