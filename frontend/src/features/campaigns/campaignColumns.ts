import type { Locale } from '@/stores/ui'

/**
 * CAMPAIGNS-TABLE-001 — the campaign table's configurable columns and its metric presets.
 *
 * Three leading columns are fixed (campaign, objective, status): without them a row is not a campaign. Every
 * other column is chosen — through a preset (performance / sales / engagement) or one by one — and the choice
 * is remembered per reader. The `objective-aware` columns (results, cost per result, return) read through the
 * campaign's own catalogue, so a sales campaign is still priced on what it sold; the plain metric columns read
 * through the shared metric catalogue, so a figure the platform never reported says so instead of printing 0.
 *
 * Sorting stays server-side on the three columns the server orders by; other columns are not sortable here,
 * because sorting one page of a server-paged list would mislead.
 */
export type CampaignColumnKind = 'spend' | 'results' | 'cost' | 'return' | 'budget' | 'linked' | 'last_active' | 'metric'

export interface CampaignColumn {
  key: string
  kind: CampaignColumnKind
  ar: string
  en: string
  /** The server sort key this column orders by, when it has one. */
  sort?: 'spend' | 'results'
  align: 'start' | 'end' | 'center'
}

const metric = (key: string, ar: string, en: string): CampaignColumn => ({ key, kind: 'metric', ar, en, align: 'end' })

export const CAMPAIGN_COLUMNS: readonly CampaignColumn[] = [
  { key: 'spend', kind: 'spend', ar: 'الإنفاق', en: 'Spend', sort: 'spend', align: 'end' },
  { key: 'results', kind: 'results', ar: 'النتائج', en: 'Results', sort: 'results', align: 'end' },
  { key: 'cost', kind: 'cost', ar: 'تكلفة النتيجة', en: 'Cost per result', align: 'center' },
  { key: 'return', kind: 'return', ar: 'العائد', en: 'Return', align: 'center' },
  { key: 'budget', kind: 'budget', ar: 'الميزانية', en: 'Budget', align: 'center' },
  { key: 'linked', kind: 'linked', ar: 'مرتبطة', en: 'Linked', align: 'center' },
  { key: 'last_active', kind: 'last_active', ar: 'آخر نشاط', en: 'Last active', align: 'start' },
  metric('impressions', 'الظهور', 'Impressions'),
  metric('reach', 'الوصول', 'Reach'),
  metric('frequency', 'التكرار', 'Frequency'),
  metric('clicks', 'النقرات', 'Clicks'),
  metric('ctr', 'نسبة النقر', 'CTR'),
  metric('cpc', 'تكلفة النقرة', 'CPC'),
  metric('cpm', 'تكلفة الألف ظهور', 'CPM'),
  metric('landing_page_views', 'زيارات صفحة الهبوط', 'Landing page views'),
  metric('engagements', 'التفاعلات', 'Engagements'),
  metric('video_views', 'مشاهدات الفيديو', 'Video views'),
  metric('leads', 'العملاء المحتملون', 'Leads'),
  metric('purchases', 'المشتريات', 'Purchases'),
  metric('revenue', 'الإيرادات', 'Revenue'),
  metric('roas', 'العائد على الإنفاق', 'ROAS'),
  metric('aov', 'متوسط قيمة الطلب', 'AOV'),
  metric('conversion_rate', 'معدل التحويل', 'Conversion rate'),
]

export type CampaignPreset = 'performance' | 'sales' | 'engagement' | 'custom'

export const CAMPAIGN_PRESETS: Record<Exclude<CampaignPreset, 'custom'>, { ar: string; en: string; columns: string[] }> = {
  performance: { ar: 'الأداء', en: 'Performance', columns: ['spend', 'results', 'cost', 'return', 'budget', 'linked'] },
  sales: { ar: 'المبيعات', en: 'Sales', columns: ['spend', 'purchases', 'revenue', 'roas', 'aov', 'cost'] },
  engagement: { ar: 'التفاعل', en: 'Engagement', columns: ['spend', 'impressions', 'reach', 'clicks', 'ctr', 'cpc', 'cpm'] },
}

export const DEFAULT_CAMPAIGN_COLUMNS = CAMPAIGN_PRESETS.performance.columns

const STORAGE_KEY = 'ch-campaign-columns'

export function readCampaignColumns(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : null
    const known = new Set(CAMPAIGN_COLUMNS.map((c) => c.key))
    if (Array.isArray(parsed)) {
      const keys = parsed.filter((k): k is string => typeof k === 'string' && known.has(k))
      if (keys.length > 0) return keys
    }
  } catch {
    /* a private window or blocked storage keeps the default for this visit */
  }
  return [...DEFAULT_CAMPAIGN_COLUMNS]
}

export function rememberCampaignColumns(keys: string[]): void {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(keys)) } catch { /* same */ }
}

/** Which preset the chosen columns are, exactly — or custom. */
export function presetOf(keys: readonly string[]): CampaignPreset {
  for (const [name, p] of Object.entries(CAMPAIGN_PRESETS) as Array<[Exclude<CampaignPreset, 'custom'>, { columns: string[] }]>) {
    if (p.columns.length === keys.length && p.columns.every((k, i) => keys[i] === k)) return name
  }
  return 'custom'
}

export function columnLabel(c: CampaignColumn, locale: Locale): string {
  return locale === 'ar' ? c.ar : c.en
}
