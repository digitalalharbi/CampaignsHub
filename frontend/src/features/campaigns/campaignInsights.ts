import type { UnifiedCampaign } from './types'

/**
 * Objective-aware "result" model + the needs-attention rules, kept as pure functions so both the
 * comparison view and the needs-attention view read the SAME definitions (and so they can be unit
 * tested without rendering anything).
 *
 * The standing product rule this file encodes: a result is NOT the same thing across objectives, so
 * an awareness campaign is never scored on CPA and a sales campaign is never scored on CPM. Anything
 * that would blend the two returns `null` instead of a number.
 */

export type ResultKind = 'conversions' | 'leads' | 'installs' | 'reach' | 'clicks' | 'engagements'

export interface ResultModel {
  /** Metric that counts as one "result" for this objective. */
  metric: ResultKind
  /** Cost-per-result key on the metrics payload (already computed server-side). */
  costKey: 'cpa' | 'cpl' | 'cpi' | 'cpm' | 'cpc' | 'cpe'
  labelAr: string
  labelEn: string
  costLabelAr: string
  costLabelEn: string
}

/** Objective → what a result means. `other` has no agreed result metric, so it reports spend only. */
const RESULTS: Record<string, ResultModel> = {
  sales: { metric: 'conversions', costKey: 'cpa', labelAr: 'التحويلات', labelEn: 'Conversions', costLabelAr: 'تكلفة التحويل', costLabelEn: 'CPA' },
  conversions: { metric: 'conversions', costKey: 'cpa', labelAr: 'التحويلات', labelEn: 'Conversions', costLabelAr: 'تكلفة التحويل', costLabelEn: 'CPA' },
  leads: { metric: 'leads', costKey: 'cpl', labelAr: 'العملاء المحتملون', labelEn: 'Leads', costLabelAr: 'تكلفة العميل المحتمل', costLabelEn: 'CPL' },
  app_installs: { metric: 'installs', costKey: 'cpi', labelAr: 'التثبيتات', labelEn: 'Installs', costLabelAr: 'تكلفة التثبيت', costLabelEn: 'CPI' },
  awareness: { metric: 'reach', costKey: 'cpm', labelAr: 'الوصول', labelEn: 'Reach', costLabelAr: 'تكلفة الألف ظهور', costLabelEn: 'CPM' },
  traffic: { metric: 'clicks', costKey: 'cpc', labelAr: 'النقرات', labelEn: 'Clicks', costLabelAr: 'تكلفة النقرة', costLabelEn: 'CPC' },
  engagement: { metric: 'engagements', costKey: 'cpe', labelAr: 'التفاعلات', labelEn: 'Engagements', costLabelAr: 'تكلفة التفاعل', costLabelEn: 'CPE' },
}

export function resultModel(objective: string | null | undefined): ResultModel | null {
  return (objective && RESULTS[objective]) || null
}

/** True when a set of campaigns spans more than one result definition — the UI must not blend them. */
export function hasMixedResults(objectives: Array<string | null | undefined>): boolean {
  const kinds = new Set(objectives.map((o) => resultModel(o)?.metric ?? 'none'))
  return kinds.size > 1
}

export type AttentionSeverity = 'high' | 'medium'

export interface AttentionFlag {
  code: string
  severity: AttentionSeverity
  ar: string
  en: string
}

/** Per-campaign metric slice the rules need. Everything is optional — a missing figure is never a flag. */
export interface AttentionMetrics {
  spend?: number | null
  conversions?: number | null
  leads?: number | null
  installs?: number | null
  reach?: number | null
  clicks?: number | null
  engagements?: number | null
  impressions?: number | null
}

/**
 * Rules are deliberately conservative: each one fires only when the data actually proves the problem.
 * "No metrics at all" is reported as an unknown-state flag rather than silently looking healthy —
 * but it is never dressed up as a performance problem.
 */
export function attentionFlags(c: UnifiedCampaign, m: AttentionMetrics | undefined): AttentionFlag[] {
  const flags: AttentionFlag[] = []
  const spend = Number(m?.spend ?? 0)
  const model = resultModel(c.objective)
  const results = model ? Number(m?.[model.metric] ?? 0) : null

  if ((c.external_campaigns_count ?? 0) === 0) {
    flags.push({
      code: 'unlinked', severity: 'high',
      ar: 'غير مرتبطة بأي حملة على منصة إعلانية — لا يمكن قياس أدائها',
      en: 'Not linked to any ad-platform campaign — its performance cannot be measured',
    })
  }

  if (c.status === 'active' && spend === 0) {
    flags.push({
      code: 'active_no_spend', severity: 'high',
      ar: 'نشطة بلا إنفاق في الفترة المحددة',
      en: 'Active but spent nothing in the selected period',
    })
  }

  if (spend > 0 && results !== null && results === 0) {
    flags.push({
      code: 'spend_no_results', severity: 'high',
      ar: `إنفاق بلا ${model?.labelAr ?? 'نتائج'}`,
      en: `Spend with no ${model?.labelEn?.toLowerCase() ?? 'results'}`,
    })
  }

  if (c.status === 'paused' && spend > 0) {
    flags.push({
      code: 'paused_with_spend', severity: 'medium',
      ar: 'متوقفة رغم وجود إنفاق مسجَّل في الفترة',
      en: 'Paused although spend was recorded in the period',
    })
  }

  const budget = Number(c.total_budget ?? 0)
  if (budget > 0 && spend > budget) {
    flags.push({
      code: 'over_budget', severity: 'high',
      ar: `تجاوزت الميزانية المخططة (${Math.round((spend / budget) * 100)}%)`,
      en: `Over its planned budget (${Math.round((spend / budget) * 100)}%)`,
    })
  }

  if (!model && c.objective === 'other') {
    flags.push({
      code: 'no_result_definition', severity: 'medium',
      ar: 'هدف الحملة «أخرى» — لا يوجد تعريف نتيجة متفق عليه لقياسها',
      en: 'Objective is "other" — no agreed result definition to measure it by',
    })
  }

  if (m === undefined) {
    flags.push({
      code: 'no_metrics', severity: 'medium',
      ar: 'لا توجد بيانات أداء لهذه الحملة في الفترة المحددة',
      en: 'No performance data for this campaign in the selected period',
    })
  }

  return flags
}

/** High-severity first, then by flag count — the worst campaigns surface at the top. */
export function attentionRank(flags: AttentionFlag[]): number {
  return flags.reduce((a, f) => a + (f.severity === 'high' ? 10 : 1), 0)
}
