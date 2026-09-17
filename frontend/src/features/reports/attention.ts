import type { ActionSeverity } from '@/features/recommendations/actionCenter'
import type { FindingKpi, KpiKind } from '@/features/recommendations/findings'

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the `recommendations` section's items, as the server sends them.
 *
 * One shape on every surface: the live link, the shared snapshot, the in-app preview and the PDF all
 * receive this from `ReportAttention` on the backend, already cut to what their reader may see. The
 * page does not filter, rank or recompute anything — an operator-internal item a client must not see
 * is absent from the JSON, not hidden by this file.
 */
export interface AttentionKpi {
  key: string
  kind: KpiKind
  before: number | null
  current: number | null
  change: number | null
  currency: string | null
  higher_is_better: boolean
  primary: boolean
  /** Only on a budget comparison: the other platform on the same objective family, and its figure. */
  peer?: { platform: string; value: number }
}

export interface AttentionItem {
  key: string
  code: string
  severity: ActionSeverity
  nature: 'problem' | 'opportunity'
  family: string
  family_label_ar: string
  family_label_en: string
  platform: string
  kpis: AttentionKpi[]
  action: string
  impact: { kind: 'extra_cost_vs_previous_rate' | 'spend_without_results'; amount: number; currency: string } | null
  evidence: { platform: string; content: Array<{ name: string | null; content_key?: string }> }
  /** Operator views only — never present in a client payload. */
  audience?: 'client' | 'operator'
  decision?: 'approved' | 'hidden' | null
}

/** The server's KPI in the shape the shared before/now tile draws. */
export function toFindingKpi(k: AttentionKpi): FindingKpi {
  return { key: k.key, kind: k.kind, before: k.before, current: k.current, currency: k.currency, higherIsBetter: k.higher_is_better }
}

type Pair = { ar: string; en: string }
const pick = (p: Pair | undefined, ar: boolean, fallback: string) => (p ? (ar ? p.ar : p.en) : fallback)

/*
 * Client words. Written for the person paying: no campaign, no bid, no audience mechanics — those
 * actions exist (`review_bidding`, `shift_budget`) and carry operator copy, because the server only
 * sends them to a client when the operator approved that exact item.
 */
const HEADLINES: Record<string, Pair> = {
  cpm_rise: { ar: 'ارتفعت تكلفة الألف ظهور', en: 'Cost per thousand impressions rose' },
  cpm_drop: { ar: 'انخفضت تكلفة الألف ظهور', en: 'Cost per thousand impressions fell' },
  ctr_drop: { ar: 'تراجع معدل النقر', en: 'Click-through rate fell' },
  ctr_rise: { ar: 'تحسّن معدل النقر', en: 'Click-through rate improved' },
  cpc_rise: { ar: 'ارتفعت تكلفة النقرة', en: 'Cost per click rose' },
  cpc_drop: { ar: 'انخفضت تكلفة النقرة', en: 'Cost per click fell' },
  lpv_rate_drop: { ar: 'زيارات أقل لصفحة الهبوط لكل نقرة', en: 'Fewer landing-page views per click' },
  lpv_rate_rise: { ar: 'زيارات أكثر لصفحة الهبوط لكل نقرة', en: 'More landing-page views per click' },
  cpl_rise: { ar: 'ارتفعت تكلفة العميل المحتمل', en: 'Cost per lead rose' },
  cpl_drop: { ar: 'انخفضت تكلفة العميل المحتمل', en: 'Cost per lead fell' },
  cpa_rise: { ar: 'ارتفعت تكلفة الطلب', en: 'Cost per order rose' },
  cpa_drop: { ar: 'انخفضت تكلفة الطلب', en: 'Cost per order fell' },
  roas_drop: { ar: 'تراجع العائد على الإنفاق', en: 'Return on ad spend fell' },
  roas_rise: { ar: 'تحسّن العائد على الإنفاق', en: 'Return on ad spend improved' },
  results_stopped: { ar: 'توقفت النتائج مع استمرار الإنفاق', en: 'Results stopped while spend continued' },
  budget_shift: { ar: 'فرق كبير في التكلفة بين منصتين', en: 'A large cost gap between two platforms' },
}
export const attentionHeadline = (code: string, ar: boolean) => pick(HEADLINES[code], ar, code)

const ACTIONS: Record<string, Pair> = {
  refresh_creative: { ar: 'جدّد المحتوى على هذه المنصة', en: 'Refresh the content on this platform' },
  review_landing_page: { ar: 'راجع سرعة صفحة الهبوط ومطابقتها للإعلان', en: 'Review the landing page’s speed and match with the ad' },
  review_cost_drivers: { ar: 'راجع ما يرفع التكلفة على هذه المنصة', en: 'Review what is driving the cost on this platform' },
  check_conversion_tracking: { ar: 'تحقّق من تتبّع النتائج قبل الاستمرار', en: 'Check result tracking before continuing' },
  keep_what_works: { ar: 'حافظ على ما ينجح هنا', en: 'Keep what is working here' },
  shift_budget: { ar: 'انقل جزءًا من الميزانية نحو المنصة الأقل تكلفة', en: 'Move part of the budget toward the cheaper platform' },
  review_bidding: { ar: 'راجع استراتيجية المزايدة', en: 'Review the bid strategy' },
  review_retargeting: { ar: 'راجع إعدادات إعادة الاستهداف', en: 'Review the retargeting setup' },
}
export const attentionAction = (action: string, ar: boolean) => pick(ACTIONS[action], ar, action)

const IMPACT: Record<NonNullable<AttentionItem['impact']>['kind'], Pair & { basis: Pair }> = {
  extra_cost_vs_previous_rate: {
    ar: 'تكلفة إضافية مقارنة بالفترة السابقة', en: 'Extra cost against the previous period',
    basis: { ar: 'فرق التكلفة لكل نتيجة × نتائج هذه الفترة', en: 'Difference in cost per result × this period’s results' },
  },
  spend_without_results: {
    ar: 'إنفاق دون نتيجة مُقاسة', en: 'Spend with no measured result',
    basis: { ar: 'إنفاق هذه الفترة مع صفر نتائج مُبلَّغ عنها', en: 'This period’s spend with zero reported results' },
  },
}
export const attentionImpact = (kind: keyof typeof IMPACT, ar: boolean) => pick(IMPACT[kind], ar, kind)
export const attentionImpactBasis = (kind: keyof typeof IMPACT, ar: boolean) => pick(IMPACT[kind]?.basis, ar, '')
