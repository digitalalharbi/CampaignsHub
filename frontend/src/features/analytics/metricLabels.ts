/**
 * The advertiser's vocabulary, in both languages (APP-100).
 *
 * The `/app` dashboard is the flagship page of the advertiser portal, and it was **Arabic only**.
 * Switching the product to English flipped `dir` to `ltr` and left ninety-odd Arabic words on the
 * page: the heading, the objective filter, every KPI label, the demo badge. The interface changed
 * direction and the content did not, which is worse than never offering English — a half-translated
 * page reads as broken rather than as unfinished.
 *
 * The vocabulary lives here rather than in the page because analytics, reports and the campaign
 * overview all name the same metrics, and three copies of "cost per result" drift the moment one is
 * corrected.
 *
 * Acronyms — CPM, CTR, CPC, CPL, CPA, ROAS, CPI, CPE, AOV — are deliberately NOT translated. They are
 * how the platforms themselves report, and an advertiser reading an Arabic dashboard still reconciles
 * it against Meta Ads Manager.
 */

export type Lang = 'ar' | 'en'

type Pair = { ar: string; en: string }

const pick = (p: Pair, ar: boolean) => (ar ? p.ar : p.en)

/** Campaign objectives — the keys match the `CampaignObjective` enum. */
const OBJECTIVE_LABELS: Record<string, Pair> = {
  all: { ar: 'كل الأهداف', en: 'All objectives' },
  awareness: { ar: 'الوعي', en: 'Awareness' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  sales: { ar: 'المبيعات', en: 'Sales' },
  app_installs: { ar: 'التطبيقات', en: 'App installs' },
  engagement: { ar: 'التفاعل', en: 'Engagement' },
}

export const OBJECTIVE_KEYS = Object.keys(OBJECTIVE_LABELS)

export const objectiveLabel = (key: string, ar: boolean) =>
  OBJECTIVE_LABELS[key] ? pick(OBJECTIVE_LABELS[key], ar) : key

/** Metric names. Acronyms map to themselves — see the note above. */
const METRIC_LABELS: Record<string, Pair> = {
  active: { ar: 'حملات نشطة', en: 'Active campaigns' },
  campaigns: { ar: 'عدد الحملات', en: 'Campaigns' },
  spend: { ar: 'الإنفاق', en: 'Spend' },
  spend_total: { ar: 'إجمالي الإنفاق', en: 'Total spend' },
  reach: { ar: 'الوصول', en: 'Reach' },
  impressions: { ar: 'الظهور', en: 'Impressions' },
  frequency: { ar: 'التكرار', en: 'Frequency' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  clicks: { ar: 'النقرات', en: 'Clicks' },
  landing_page_views: { ar: 'مشاهدات الصفحة', en: 'Landing page views' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  qualified_leads: { ar: 'المؤهلون', en: 'Qualified leads' },
  conversion_rate: { ar: 'معدل التحويل', en: 'Conversion rate' },
  purchases: { ar: 'المشتريات', en: 'Purchases' },
  revenue: { ar: 'الإيرادات', en: 'Revenue' },
  cost_per_result: { ar: 'تكلفة النتيجة', en: 'Cost per result' },
  installs: { ar: 'التثبيتات', en: 'Installs' },
  registrations: { ar: 'التسجيلات', en: 'Registrations' },
  in_app_events: { ar: 'أحداث داخل التطبيق', en: 'In-app events' },
  engagements: { ar: 'التفاعلات', en: 'Engagements' },
  engagement_rate: { ar: 'معدل التفاعل', en: 'Engagement rate' },
}

export const metricLabel = (key: string, ar: boolean) =>
  METRIC_LABELS[key] ? pick(METRIC_LABELS[key], ar) : key

/** Page-level copy for the advertiser dashboard. */
export const DASH_COPY = {
  operationalView: { ar: 'لوحة التحكم — نظرة تشغيلية', en: 'Dashboard — operational view' },
  objectiveViewPrefix: { ar: 'لوحة أداء حملات', en: 'Campaign performance —' },
  totalSpendHint: { ar: 'إجمالي الإنفاق عبر كل الأهداف', en: 'Total spend across every objective' },
  pacingAhead: { ar: 'استهلاك أسرع من المخطط', en: 'spending ahead of plan' },
  spendNoConversions: { ar: 'إنفاق مرتفع دون تحويلات', en: 'High spend, no conversions' },
} as const

export const dash = (key: keyof typeof DASH_COPY, ar: boolean) => pick(DASH_COPY[key], ar)
