import { moneyExact, num, percent, ratio } from '@/features/analytics/format'

/*
 * REPORT-OBJECTIVE-ANALYTICS-001 — the objective section's payload, as the server builds it.
 *
 * One shape for the live link, the snapshot (and its shared link) and the PDF. Nothing here computes a
 * figure: every value, state and ranking arrives decided, and this file only says how to write it.
 */

export type KpiState = 'reported' | 'unavailable'

export interface ObjectiveKpi {
  key: string
  label_ar: string
  label_en: string
  kind: 'money' | 'rate' | 'multiplier' | 'ratio' | 'count'
  value: number | null
  state: KpiState
  reason: string | null
  /** Platforms in this family that did not send the figure (or one of a ratio's parts). */
  not_reported_by?: string[]
}

export interface RankingEnd {
  provider: string | null
  name?: string
  format?: string | null
  value: number
  volume: { key: string; value: number }
}

export interface ObjectiveRanking {
  metric: string | null
  best: RankingEnd | null
  weakest: RankingEnd | null
  reason: string | null
  eligible: number
  candidates: number
}

export interface ObjectiveFamilyBlock {
  family: string
  label_ar: string
  label_en: string
  kpis: ObjectiveKpi[]
  platforms: { provider: string; kpis: ObjectiveKpi[]; spend_share: number | null }[]
  contribution: {
    outcome: string
    label_ar: string
    label_en: string
    total: number
    rows: { provider: string; value: number | null; share: number | null }[]
  } | null
  platform_ranking: ObjectiveRanking
  content_ranking: ObjectiveRanking | null
  trend: {
    metric: string | null
    outcome: string | null
    points: { date: string; reported: boolean; spend: number | null; outcome: number | null; value: number | null }[]
  } | null
}

export interface ObjectiveAnalytics {
  version: number
  period: { from: string; to: string }
  families: ObjectiveFamilyBlock[]
  mixed: boolean
  cross_family_blend: false
  unclassified_present: boolean
}

/** The metrics a ranking may rest on — the server's `MINIMUM_VOLUME` keys — with their names and kinds. */
export const RANKING_METRICS: Record<string, { ar: string; en: string; kind: ObjectiveKpi['kind'] }> = {
  cpm: { ar: 'تكلفة الألف ظهور', en: 'CPM', kind: 'money' },
  ctr: { ar: 'نسبة النقر', en: 'CTR', kind: 'rate' },
  cpc: { ar: 'تكلفة النقرة', en: 'CPC', kind: 'money' },
  engagement_rate: { ar: 'معدل التفاعل', en: 'Engagement rate', kind: 'rate' },
  cpe: { ar: 'تكلفة التفاعل', en: 'CPE', kind: 'money' },
  cost_per_view: { ar: 'تكلفة المشاهدة', en: 'Cost per view', kind: 'money' },
  cpl: { ar: 'تكلفة العميل المحتمل', en: 'CPL', kind: 'money' },
  cpa: { ar: 'تكلفة الشراء', en: 'CPA', kind: 'money' },
  cost_per_conversion: { ar: 'تكلفة التحويل', en: 'Cost per conversion', kind: 'money' },
  roas: { ar: 'العائد على الإنفاق', en: 'ROAS', kind: 'multiplier' },
  cpi: { ar: 'تكلفة التثبيت', en: 'CPI', kind: 'money' },
}

/** A figure in the notation every other report card uses. Unavailable is «—», a reported 0 is «0». */
export function formatFigure(kind: ObjectiveKpi['kind'], value: number | null | undefined, currency: string | null | undefined): string {
  if (value === null || value === undefined) return '—'

  switch (kind) {
    case 'money':
      return moneyExact(value, currency)
    case 'rate':
      return percent(value, 2)
    case 'multiplier':
      return ratio(value)
    case 'ratio':
      return ratio(value, '')
    default:
      return num(value)
  }
}

export function formatKpi(kpi: ObjectiveKpi, currency: string | null | undefined): string {
  return kpi.state === 'reported' ? formatFigure(kpi.kind, kpi.value, currency) : '—'
}

export function rankingMetricLabel(metric: string | null, ar: boolean): string {
  if (!metric) return ''
  const spec = RANKING_METRICS[metric]

  return spec ? (ar ? spec.ar : spec.en) : metric
}

export function formatRankingValue(metric: string | null, value: number, currency: string | null | undefined): string {
  return formatFigure(metric ? RANKING_METRICS[metric]?.kind ?? 'count' : 'count', value, currency)
}

/** Why a figure reads «—», in the reader's words — never the pipeline's. */
export function unavailableNote(reason: string | null, ar: boolean): string {
  switch (reason) {
    case 'zero_denominator':
      return ar ? 'لا يوجد أساس للقسمة في هذه الفترة' : 'Nothing to divide by in this period'
    case 'money_not_converted':
      return ar ? 'بعض المبالغ غير محوّلة إلى عملة التقرير' : 'Some amounts are not converted to the report currency'
    default:
      return ar ? 'لا تُبلّغ المنصة عن هذا المؤشر' : 'Not reported by the platform'
  }
}

/** A family worth drawing: at least one figure somebody reported. A card of «—» is not a section. */
export function drawableFamilies(section: ObjectiveAnalytics | null | undefined): ObjectiveFamilyBlock[] {
  return (section?.families ?? []).filter((block) => block.kpis.some((k) => k.state === 'reported'))
}
