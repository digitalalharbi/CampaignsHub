import type { ActionSeverity } from './actionCenter'
import type { EvidenceTarget, FindingAction, FindingFactKey, FindingNature, ImpactKind } from './findings'

/**
 * RECOMMENDATIONS-VISUAL-001 — the words around the figures, and only those.
 *
 * A headline names WHAT happened in three or four words; the card's figures say how much. Nothing
 * here restates a number, so nothing here can disagree with one.
 */
type Pair = { ar: string; en: string }
const pick = (p: Pair | undefined, ar: boolean, fallback: string) => (p ? (ar ? p.ar : p.en) : fallback)

const HEADLINES: Record<string, Pair> = {
  roas_drop: { ar: 'تراجع العائد على الإنفاق', en: 'Return on ad spend fell' },
  cpa_increase: { ar: 'ارتفعت تكلفة النتيجة', en: 'Cost per result rose' },
  cpl_increase: { ar: 'ارتفعت تكلفة العميل المحتمل', en: 'Cost per lead rose' },
  no_results: { ar: 'إنفاق بلا نتائج', en: 'Spending with no results' },
  budget_risk: { ar: 'الميزانية تقترب من النفاد', en: 'Budget close to exhausted' },
  metric_anomaly: { ar: 'يوم غير معتاد', en: 'An unusual day' },
  sync_failure: { ar: 'فشلت مزامنة البيانات', en: 'Data sync failed' },
  token_expiry: { ar: 'صلاحية الربط تنتهي قريبًا', en: 'Connection authorisation expiring' },
  lead_unassigned: { ar: 'عملاء محتملون بلا مسؤول', en: 'Leads with no owner' },
  lead_no_contact: { ar: 'عملاء محتملون لم يُتواصل معهم', en: 'Leads not contacted' },
  lead_follow_up_overdue: { ar: 'متابعات متأخرة', en: 'Follow-ups overdue' },
  report_failed: { ar: 'تعذّر إنشاء تقرير', en: 'A report failed to generate' },
  limit_over: { ar: 'تجاوز حدّ الإنفاق الداخلي', en: 'Past an internal spend limit' },
  limit_approaching: { ar: 'يقترب من حدّ الإنفاق الداخلي', en: 'Approaching an internal spend limit' },
  limit_unknown: { ar: 'حدّ إنفاق لا يمكن قياسه', en: 'A spend limit that cannot be measured' },
  fatigue: { ar: 'إرهاق المحتوى', en: 'Creative fatigue' },
  creative_rising: { ar: 'محتوى يتحسّن', en: 'Creative improving' },
  creative_declining: { ar: 'محتوى يتراجع', en: 'Creative declining' },
}

export const headline = (code: string, ar: boolean) => pick(HEADLINES[code], ar, code)

const NATURE: Record<FindingNature, Pair> = {
  problem: { ar: 'مشكلة', en: 'Problem' },
  opportunity: { ar: 'فرصة', en: 'Opportunity' },
  risk: { ar: 'خطر', en: 'Risk' },
  tracking: { ar: 'تتبّع وبيانات', en: 'Tracking & data' },
}
export const natureLabel = (n: FindingNature, ar: boolean) => pick(NATURE[n], ar, n)

const SEVERITY: Record<ActionSeverity, Pair> = {
  critical: { ar: 'حرجة', en: 'Critical' },
  warning: { ar: 'مهمة', en: 'Important' },
  info: { ar: 'للمتابعة', en: 'Monitor' },
}
export const severityLabel = (s: ActionSeverity, ar: boolean) => pick(SEVERITY[s], ar, s)

const ACTIONS: Record<FindingAction, Pair> = {
  review_budget: { ar: 'راجع ميزانية الحملة أو وتيرة صرفها', en: 'Review the campaign budget or its pacing' },
  pause_or_raise_limit: { ar: 'أوقف على المنصة أو ارفع الحدّ عمدًا', en: 'Pause on the platform, or raise the limit deliberately' },
  check_conversion_tracking: { ar: 'تحقّق من تتبّع التحويلات قبل رفع الإنفاق', en: 'Check conversion tracking before spending more' },
  review_cost_drivers: { ar: 'راجع الاستهداف والمزايدة والمحتوى في هذه الحملة', en: 'Review targeting, bidding and creative in this campaign' },
  refresh_creative: { ar: 'جدّد هذا المحتوى أو خفّف الإنفاق عليه', en: 'Refresh this creative or reduce its spend' },
  scale_winner: { ar: 'فكّر في منحه ميزانية أكبر', en: 'Consider giving it more budget' },
  reconnect: { ar: 'أعد ربط المنصة', en: 'Reconnect the platform' },
  assign_leads: { ar: 'عيّن مسؤولًا لهؤلاء العملاء', en: 'Assign an owner to these leads' },
  contact_leads: { ar: 'تواصل مع هؤلاء العملاء', en: 'Contact these leads' },
  investigate_day: { ar: 'افتح الحملة وافحص هذا اليوم', en: 'Open the campaign and inspect that day' },
  fix_budget_currency: { ar: 'وحّد عملة الميزانية مع عملة الإنفاق', en: 'Align the budget currency with the spend currency' },
  review_report: { ar: 'أعد إنشاء التقرير', en: 'Regenerate the report' },
  review_written: { ar: 'راجع التوصية', en: 'Review the recommendation' },
}
export const actionLabel = (a: FindingAction, ar: boolean) => pick(ACTIONS[a], ar, a)

const EVIDENCE: Record<EvidenceTarget, Pair> = {
  campaign: { ar: 'افتح تحليل الحملة', en: 'Open the campaign analysis' },
  content: { ar: 'افتح تحليلات المحتوى', en: 'Open the content analytics' },
  spend_limits: { ar: 'افتح حدود الإنفاق', en: 'Open spend limits' },
  integrations: { ar: 'افتح الربط', en: 'Open integrations' },
  leads: { ar: 'افتح العملاء المحتملين', en: 'Open leads' },
  alerts: { ar: 'افتح التنبيه', en: 'Open the alert' },
}
export const evidenceLabel = (t: EvidenceTarget, ar: boolean) => pick(EVIDENCE[t], ar, t)

const IMPACT: Record<ImpactKind, Pair & { basisAr: string; basisEn: string }> = {
  overspend: { ar: 'تجاوز الحدّ بمقدار', en: 'Over the limit by', basisAr: 'الإنفاق المُقاس ناقص الحدّ، بعملة الحدّ', basisEn: 'Measured spend minus the limit, in the limit’s currency' },
  projected_overrun: { ar: 'تجاوز متوقّع بمقدار', en: 'Projected overrun', basisAr: 'بوتيرة الصرف الحالية حتى نهاية الفترة', basisEn: 'At the current pace to the end of the period' },
  spend_without_results: { ar: 'إنفاق دون نتيجة مُقاسة', en: 'Spend with no measured result', basisAr: 'الإنفاق في نافذة التنبيه مع صفر تحويلات مُبلَّغ عنها', basisEn: 'Spend in the alert window with zero reported conversions' },
  spend_on_fatigued: { ar: 'إنفاق على محتوى مُرهق', en: 'Spend on a fatigued creative', basisAr: 'إنفاق هذا المحتوى في الفترة كما قاسه ملخص المحتوى', basisEn: 'This creative’s spend in the period, as the content pulse measured it' },
}
export const impactLabel = (k: ImpactKind, ar: boolean) => pick(IMPACT[k], ar, k)
export const impactBasis = (k: ImpactKind, ar: boolean) => (ar ? IMPACT[k].basisAr : IMPACT[k].basisEn)

const FACTS: Record<FindingFactKey, Pair> = {
  provider: { ar: 'المنصة', en: 'Platform' },
  expires_at: { ar: 'تنتهي في', en: 'Expires' },
  error: { ar: 'الخطأ', en: 'Error' },
  count: { ar: 'العدد', en: 'Count' },
  basis: { ar: 'سبب تعذّر القياس', en: 'Why it cannot be measured' },
  threshold: { ar: 'العتبة', en: 'Threshold' },
  window_days: { ar: 'النافذة (أيام)', en: 'Window (days)' },
  utilisation: { ar: 'الاستهلاك', en: 'Utilisation' },
  pace: { ar: 'الوتيرة', en: 'Pace' },
  exhaustion: { ar: 'النفاد المتوقع', en: 'Projected exhaustion' },
  date: { ar: 'اليوم', en: 'Day' },
}
export const factLabel = (k: FindingFactKey, ar: boolean) => pick(FACTS[k], ar, k)

export const extraMetricLabel = (key: string, ar: boolean): string | null =>
  key === 'projected_spend' ? (ar ? 'الإنفاق المتوقع' : 'Projected spend') : null
