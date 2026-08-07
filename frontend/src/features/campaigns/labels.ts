import type { Locale } from '@/stores/ui'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const status: Record<string, { ar: string; en: string; tone: Tone }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'neutral' },
  active: { ar: 'نشطة', en: 'Active', tone: 'success' },
  paused: { ar: 'موقوفة', en: 'Paused', tone: 'warning' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'info' },
  archived: { ar: 'مؤرشفة', en: 'Archived', tone: 'neutral' },
  pending: { ar: 'قيد المراجعة', en: 'Pending', tone: 'warning' },
  unknown: { ar: 'غير معروفة', en: 'Unknown', tone: 'neutral' },
}

/*
 * Every case of `CampaignObjective`, not the common eight.
 *
 * The six below the first block were missing, and `objectiveLabel` falls back to the raw key — so a
 * Snapchat reach campaign or a Google store-visits campaign rendered the literal `reach` and
 * `store_visits` in the middle of Arabic copy, on every surface that names an objective. Found by
 * opening the creative library's objective filter, which is the first control that lists the whole
 * enum rather than whichever objectives a demo happened to seed.
 */
const objective: Record<string, { ar: string; en: string }> = {
  awareness: { ar: 'الوعي', en: 'Awareness' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  engagement: { ar: 'التفاعل', en: 'Engagement' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  app_installs: { ar: 'تثبيت التطبيق', en: 'App installs' },
  sales: { ar: 'المبيعات', en: 'Sales' },
  conversions: { ar: 'التحويلات', en: 'Conversions' },
  other: { ar: 'أخرى', en: 'Other' },
  reach: { ar: 'الوصول', en: 'Reach' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  landing_page_views: { ar: 'زيارات صفحة الهبوط', en: 'Landing page views' },
  add_to_cart: { ar: 'الإضافة إلى السلة', en: 'Add to cart' },
  purchases: { ar: 'الشراء', en: 'Purchases' },
  store_visits: { ar: 'زيارات المتجر', en: 'Store visits' },
}

/**
 * The three marketing PATHS — the buckets objectives fall into, not objectives themselves.
 *
 * Kept beside the objectives deliberately: they were duplicated into a page as a four-entry map that
 * included `leads` and `sales`, neither of which is a path, and omitted `conversion`, which is. The
 * result was an unlabelled option reading `conversion` in Arabic.
 */
const marketingPath: Record<string, { ar: string; en: string }> = {
  awareness: { ar: 'الوعي', en: 'Awareness' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  conversion: { ar: 'التحويل والمبيعات', en: 'Conversion & sales' },
}

const provider: Record<string, { ar: string; en: string }> = {
  meta: { ar: 'ميتا', en: 'Meta' },
  google: { ar: 'جوجل', en: 'Google Ads' },
  tiktok: { ar: 'تيك توك', en: 'TikTok' },
  snapchat: { ar: 'سناب شات', en: 'Snapchat' },
  x: { ar: 'إكس', en: 'X' },
  linkedin: { ar: 'لينكدإن', en: 'LinkedIn' },
  microsoft: { ar: 'مايكروسوفت', en: 'Microsoft' },
  pinterest: { ar: 'بنترست', en: 'Pinterest' },
  sandbox: { ar: 'Sandbox', en: 'Sandbox' },
}

// Internal (team-facing) classification — persisted, editable, filterable, audited.
const stage: Record<string, { ar: string; en: string; tone: Tone }> = {
  planning: { ar: 'تخطيط', en: 'Planning', tone: 'neutral' },
  setup: { ar: 'إعداد', en: 'Setup', tone: 'neutral' },
  learning: { ar: 'تعلّم', en: 'Learning', tone: 'info' },
  scaling: { ar: 'توسّع', en: 'Scaling', tone: 'info' },
  optimization: { ar: 'تحسين', en: 'Optimization', tone: 'info' },
  stable: { ar: 'مستقرة', en: 'Stable', tone: 'success' },
  declining: { ar: 'متراجعة', en: 'Declining', tone: 'warning' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'neutral' },
  archived: { ar: 'مؤرشفة', en: 'Archived', tone: 'neutral' },
}

const performance: Record<string, { ar: string; en: string; tone: Tone }> = {
  top_performing: { ar: 'أداء متميز', en: 'Top Performing', tone: 'success' },
  on_track: { ar: 'على المسار', en: 'On Track', tone: 'success' },
  needs_optimization: { ar: 'تحتاج تحسينًا', en: 'Needs Optimization', tone: 'warning' },
  budget_risk: { ar: 'خطر ميزانية', en: 'Budget Risk', tone: 'danger' },
  no_results: { ar: 'بلا نتائج', en: 'No Results', tone: 'danger' },
  tracking_issue: { ar: 'مشكلة تتبّع', en: 'Tracking Issue', tone: 'danger' },
  stale_data: { ar: 'بيانات قديمة', en: 'Stale Data', tone: 'warning' },
}

const priority: Record<string, { ar: string; en: string; tone: Tone }> = {
  critical: { ar: 'حرجة', en: 'Critical', tone: 'danger' },
  high: { ar: 'عالية', en: 'High', tone: 'warning' },
  medium: { ar: 'متوسطة', en: 'Medium', tone: 'info' },
  low: { ar: 'منخفضة', en: 'Low', tone: 'neutral' },
}

export const STAGE_KEYS = Object.keys(stage)
export const PERFORMANCE_KEYS = Object.keys(performance)
export const PRIORITY_KEYS = Object.keys(priority)

export function stageLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (stage[key]?.[locale] ?? key) : ''
}
export function stageTone(key: string | null | undefined): Tone {
  return (key && stage[key]?.tone) || 'neutral'
}
export function performanceLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (performance[key]?.[locale] ?? key) : ''
}
export function performanceTone(key: string | null | undefined): Tone {
  return (key && performance[key]?.tone) || 'neutral'
}
export function priorityLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (priority[key]?.[locale] ?? key) : ''
}
export function priorityTone(key: string | null | undefined): Tone {
  return (key && priority[key]?.tone) || 'neutral'
}

export function campaignStatusLabel(key: string, locale: Locale): string {
  return status[key]?.[locale] ?? key
}

export function campaignStatusTone(key: string): Tone {
  return status[key]?.tone ?? 'neutral'
}

export function objectiveLabel(key: string, locale: Locale): string {
  return objective[key]?.[locale] ?? key
}

export function providerLabel(key: string, locale: Locale): string {
  return provider[key]?.[locale] ?? key
}

export function marketingPathLabel(key: string, locale: Locale): string {
  return marketingPath[key]?.[locale] ?? key
}

export const MARKETING_PATH_KEYS = Object.keys(marketingPath)

/**
 * Which objectives fall in each path — a mirror of `CampaignObjective::path()` (UX-DASH-001).
 *
 * The dashboard's path control is not a separate server filter: it selects this path's objectives
 * and sends them on the objective axis the metrics API already supports. That is deliberate. A
 * filter this product cannot express server-side would have to be applied to rows already fetched,
 * and a page that narrows its KPI row but not its chart is worse than one that does not narrow.
 *
 * Because the request is always a list of OBJECTIVES, a drift between this map and the enum can
 * only mis-group the choices — it can never produce a wrong figure, since the server filters by
 * exactly the objectives it was handed. `CampaignObjectivePathTest` fails if the enum moves one.
 */
const PATH_OBJECTIVES: Record<string, string[]> = {
  awareness: ['awareness', 'reach', 'video_views', 'engagement', 'other'],
  traffic: ['traffic', 'landing_page_views', 'store_visits'],
  conversion: ['leads', 'app_installs', 'add_to_cart', 'sales', 'conversions', 'purchases'],
}

/** The objectives a path covers, or every objective when no path is chosen. */
export function objectivesForPath(path: string): string[] {
  return PATH_OBJECTIVES[path] ?? Object.keys(objective)
}

/** The path an objective belongs to — for labelling a choice with the money it represents. */
export function pathOfObjective(key: string): string {
  return Object.keys(PATH_OBJECTIVES).find((p) => PATH_OBJECTIVES[p].includes(key)) ?? 'awareness'
}
