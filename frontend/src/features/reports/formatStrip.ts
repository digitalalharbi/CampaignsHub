import type { FormatComparisonObjective, FormatComparisonPayload, FormatComparisonRow } from '@/features/content/api'

/**
 * REPORT-FORMAT-COMPARE-COMPACT-001 — which figures the report's compact format strip draws, with no React: the deck's page list
 * (`reportSections`) asks the same question without loading the renderer.
 */
export type StripMetric = 'results' | 'cost_per_result' | 'ctr' | 'conversion_rate' | 'roas'

/** The KPIs an objective is judged on, in reading order. A sales objective adds conversion and return; others do not. */
const FAMILY_METRICS: Record<string, StripMetric[]> = {
  sales: ['results', 'cost_per_result', 'conversion_rate', 'roas', 'ctr'],
  leads: ['results', 'cost_per_result', 'conversion_rate', 'ctr'],
  app: ['results', 'cost_per_result', 'conversion_rate', 'ctr'],
}
const DEFAULT_METRICS: StripMetric[] = ['results', 'cost_per_result', 'ctr']
export const MONEY: StripMetric[] = ['cost_per_result', 'roas']
export const LOWER_IS_BETTER: StripMetric[] = ['cost_per_result']

export const present = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v) && v > 0

/** The metrics this objective's cards carry: the objective's own, answered by at least one format, money only for an internal reader. */
export function stripMetrics(objective: FormatComparisonObjective, hidesMoney: boolean): StripMetric[] {
  const own = FAMILY_METRICS[objective.family] ?? DEFAULT_METRICS

  return own.filter((m) => !(hidesMoney && MONEY.includes(m)) && (objective.formats ?? []).some((f) => present(f[m])))
}

/** The formats with at least one figure on those metrics — a card of nothing is not a card. */
export function stripFormats(objective: FormatComparisonObjective, metrics: StripMetric[]): FormatComparisonRow[] {
  return (objective.formats ?? []).filter((f) => metrics.some((m) => present(f[m])))
}

/** Whether the strip would draw anything at all — a section with no figure is not a section. */
export function stripHasFigures(data: FormatComparisonPayload | null | undefined, hidesMoney: boolean): boolean {
  return (data?.objectives ?? []).some((o) => {
    const m = stripMetrics(o, hidesMoney)

    return m.length > 0 && stripFormats(o, m).length > 0
  })
}

