/**
 * ADS-MANAGER-002 (Owner 2026-11-24) — the table's figures as presets by objective: «مؤشرات المبيعات», «الوعي»,
 * «الزيارات», «تحميل التطبيق», «العملاء المحتملون», «الفيديو», «التفاعل», and the general «الأداء» set.
 *
 * Each column reads one field the metric endpoints already send, or divides two of them. A field the platform did not send
 * is null and reads «—», never zero. `additive` says whether the totals row may sum the column; a rate never sums.
 */
export type ColumnKind = 'money' | 'count' | 'percent' | 'ratio'

export interface ColumnDef {
  key: string
  ar: string
  en: string
  kind: ColumnKind
  additive: boolean
  get: (t: Record<string, unknown>) => number | null
}

export type PresetKey = 'performance' | 'sales' | 'awareness' | 'traffic' | 'app' | 'leads' | 'video' | 'engagement'

const n = (t: Record<string, unknown>, k: string): number | null => {
  const v = t[k]
  return typeof v === 'number' && Number.isFinite(v) ? v : null
}
const per = (a: string, b: string, scale = 1) => (t: Record<string, unknown>) => {
  const x = n(t, a)
  const y = n(t, b)
  return x === null || y === null || y <= 0 ? null : (x / y) * scale
}
const field = (k: string) => (t: Record<string, unknown>) => n(t, k)
const orPer = (k: string, a: string, b: string, scale = 1) => (t: Record<string, unknown>) => n(t, k) ?? per(a, b, scale)(t)

const C = {
  spend: { key: 'spend', ar: 'الإنفاق', en: 'Amount spent', kind: 'money', additive: true, get: field('spend') },
  impressions: { key: 'impressions', ar: 'الظهور', en: 'Impressions', kind: 'count', additive: true, get: field('impressions') },
  reach: { key: 'reach', ar: 'الوصول', en: 'Reach', kind: 'count', additive: false, get: field('reach') },
  frequency: { key: 'frequency', ar: 'التكرار', en: 'Frequency', kind: 'ratio', additive: false, get: orPer('frequency', 'impressions', 'reach') },
  cpm: { key: 'cpm', ar: 'تكلفة الألف ظهور', en: 'CPM', kind: 'money', additive: false, get: orPer('cpm', 'spend', 'impressions', 1000) },
  clicks: { key: 'clicks', ar: 'النقرات', en: 'Clicks', kind: 'count', additive: true, get: field('clicks') },
  ctr: { key: 'ctr', ar: 'معدل النقر', en: 'Click rate', kind: 'percent', additive: false, get: orPer('ctr', 'clicks', 'impressions') },
  cpc: { key: 'cpc', ar: 'تكلفة النقرة', en: 'CPC', kind: 'money', additive: false, get: orPer('cpc', 'spend', 'clicks') },
  lpv: { key: 'landing_page_views', ar: 'زيارات الصفحة', en: 'Landing page views', kind: 'count', additive: true, get: field('landing_page_views') },
  cplpv: { key: 'cost_per_lpv', ar: 'تكلفة الزيارة', en: 'Cost per visit', kind: 'money', additive: false, get: orPer('cost_per_lpv', 'spend', 'landing_page_views') },
  purchases: { key: 'purchases', ar: 'المشتريات', en: 'Purchases', kind: 'count', additive: true, get: (t) => n(t, 'purchases') ?? n(t, 'conversions') },
  revenue: { key: 'revenue', ar: 'المبيعات', en: 'Revenue', kind: 'money', additive: true, get: field('revenue') },
  roas: { key: 'roas', ar: 'العائد', en: 'ROAS', kind: 'ratio', additive: false, get: orPer('roas', 'revenue', 'spend') },
  cpa: { key: 'cpa', ar: 'تكلفة الطلب', en: 'Cost per purchase', kind: 'money', additive: false, get: (t) => { const c = n(t, 'cpa'); if (c !== null) return c; const s = n(t, 'spend'); const o = n(t, 'purchases') ?? n(t, 'conversions'); return s === null || o === null || o <= 0 ? null : s / o } },
  aov: { key: 'aov', ar: 'متوسط الطلب', en: 'Avg. order value', kind: 'money', additive: false, get: field('aov') },
  installs: { key: 'installs', ar: 'التحميلات', en: 'Installs', kind: 'count', additive: true, get: field('installs') },
  cpi: { key: 'cpi', ar: 'تكلفة التحميل', en: 'Cost per install', kind: 'money', additive: false, get: orPer('cpi', 'spend', 'installs') },
  leads: { key: 'leads', ar: 'العملاء المحتملون', en: 'Leads', kind: 'count', additive: true, get: field('leads') },
  cpl: { key: 'cpl', ar: 'تكلفة العميل', en: 'Cost per lead', kind: 'money', additive: false, get: orPer('cpl', 'spend', 'leads') },
  convRate: { key: 'conversion_rate', ar: 'معدل التحويل', en: 'Conversion rate', kind: 'percent', additive: false, get: field('conversion_rate') },
  videoViews: { key: 'video_views', ar: 'مشاهدات الفيديو', en: 'Video views', kind: 'count', additive: true, get: field('video_views') },
  completions: { key: 'video_completions', ar: 'المشاهدات الكاملة', en: 'Completed views', kind: 'count', additive: true, get: (t) => n(t, 'video_completions') ?? n(t, 'video_p100') },
  cpv: { key: 'cost_per_view', ar: 'تكلفة المشاهدة', en: 'Cost per view', kind: 'money', additive: false, get: orPer('cost_per_view', 'spend', 'video_views') },
  engagements: { key: 'engagements', ar: 'التفاعلات', en: 'Engagements', kind: 'count', additive: true, get: field('engagements') },
  engRate: { key: 'engagement_rate', ar: 'معدل التفاعل', en: 'Engagement rate', kind: 'percent', additive: false, get: orPer('engagement_rate', 'engagements', 'impressions') },
  cpe: { key: 'cpe', ar: 'تكلفة التفاعل', en: 'Cost per engagement', kind: 'money', additive: false, get: orPer('cpe', 'spend', 'engagements') },
} satisfies Record<string, ColumnDef>

/** `performance` is drawn with the objective-aware result and its cost (see AdsManager), then these. */
export const PRESETS: Record<PresetKey, { ar: string; en: string; columns: ColumnDef[] }> = {
  performance: { ar: 'الأداء', en: 'Performance', columns: [C.spend, C.impressions, C.clicks, C.ctr] },
  sales: { ar: 'المبيعات', en: 'Sales', columns: [C.spend, C.purchases, C.revenue, C.roas, C.cpa, C.aov] },
  awareness: { ar: 'الوعي', en: 'Awareness', columns: [C.spend, C.impressions, C.reach, C.frequency, C.cpm] },
  traffic: { ar: 'الزيارات', en: 'Traffic', columns: [C.spend, C.clicks, C.ctr, C.cpc, C.lpv, C.cplpv] },
  app: { ar: 'تحميل التطبيق', en: 'App installs', columns: [C.spend, C.installs, C.cpi, C.clicks, C.ctr] },
  leads: { ar: 'العملاء المحتملون', en: 'Leads', columns: [C.spend, C.leads, C.cpl, C.clicks, C.convRate] },
  video: { ar: 'الفيديو', en: 'Video', columns: [C.spend, C.videoViews, C.completions, C.cpv, C.impressions] },
  engagement: { ar: 'التفاعل', en: 'Engagement', columns: [C.spend, C.engagements, C.engRate, C.cpe, C.impressions] },
}

export const PRESET_ORDER: PresetKey[] = ['performance', 'sales', 'awareness', 'traffic', 'leads', 'app', 'video', 'engagement']
