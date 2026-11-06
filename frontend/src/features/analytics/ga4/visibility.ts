/**
 * GA4-VISIBILITY-001 — the catalogue of store / GA4 elements the Owner can show or hide, mirrored from
 * `Ga4Visibility` on the server (which is the one that cuts: this list only names the switches).
 */
export type Ga4Preset = 'all' | 'marketing' | 'sales' | 'custom'
type L = { ar: string; en: string }
export interface ElementGroup { key: string; label: L; items: Array<{ key: string; label: L; sales: boolean }> }

const e = (key: string, ar: string, en: string, sales: boolean) => ({ key, label: { ar, en }, sales })

export const ELEMENT_GROUPS: ElementGroup[] = [
  { key: 'tiles', label: { ar: 'البطاقات', en: 'Tiles' }, items: [
    e('tile.sessions', 'الزيارات', 'Sessions', false), e('tile.users_daily_sum', 'المستخدمون', 'Users', false), e('tile.new_users', 'الجدد', 'New', false),
    e('tile.bounce_rate', 'الارتداد', 'Bounce', false), e('tile.engagement_rate', 'التفاعل', 'Engagement', false), e('tile.avg_session_seconds', 'المدة', 'Duration', false),
    e('tile.add_to_carts', 'الإضافة', 'Carts', false), e('tile.checkouts', 'الدفع', 'Checkouts', false),
    e('tile.transactions', 'المشتريات', 'Purchases', true), e('tile.revenue', 'المبيعات', 'Sales', true), e('tile.aov', 'السلة', 'Basket', true), e('tile.conversion_rate', 'التحويل', 'Conversion', true),
  ] },
  { key: 'charts', label: { ar: 'الرسوم', en: 'Charts' }, items: [
    e('chart.series.visits', 'خط الزيارات', 'Visits line', false), e('chart.series.sales', 'خط المبيعات', 'Sales line', true),
    e('chart.channels', 'القنوات', 'Channels', false), e('chart.devices', 'الأجهزة', 'Devices', false), e('chart.funnel', 'القمع', 'Funnel', false),
    e('stage.sessions', 'مرحلة الزيارة', 'Visit stage', false), e('stage.add_to_cart', 'مرحلة السلة', 'Cart stage', false), e('stage.checkout', 'مرحلة الدفع', 'Checkout stage', false), e('stage.purchase', 'مرحلة الشراء', 'Purchase stage', true),
  ] },
  { key: 'tables', label: { ar: 'الجداول', en: 'Tables' }, items: [
    e('table.sources', 'المصادر', 'Sources', false), e('table.campaigns', 'الحملات', 'Campaigns', false), e('table.landing_pages', 'الصفحات', 'Pages', false), e('table.countries', 'الدول', 'Countries', false), e('table.products', 'المنتجات', 'Products', true),
  ] },
  { key: 'columns', label: { ar: 'الأعمدة', en: 'Columns' }, items: [
    e('col.sessions', 'الزيارات', 'Sessions', false), e('col.engagement_rate', 'التفاعل', 'Engaged', false),
    e('col.purchases', 'الشراء', 'Orders', true), e('col.revenue', 'المبيعات', 'Sales', true), e('col.aov', 'السلة', 'Basket', true), e('col.conversion_rate', 'التحويل', 'CVR', true),
  ] },
  { key: 'match', label: { ar: 'المطابقة', en: 'Match' }, items: [
    e('attr.claimed', 'ما ادّعته المنصة', 'Claimed', false), e('attr.confidence', 'الثقة', 'Confidence', false), e('attr.unattributed', 'بلا إسناد', 'Unattributed', false), e('attr.unmatched', 'بلا أثر', 'Unmatched', false),
    e('attr.sales', 'مبيعات GA4', 'GA4 sales', true), e('attr.gap', 'الفرق', 'Gap', true),
    e('dim.channel', 'تصنيف: قناة', 'By channel', false), e('dim.platform', 'تصنيف: منصة', 'By platform', false), e('dim.landing_page', 'تصنيف: صفحة', 'By page', false),
  ] },
  { key: 'reasons', label: { ar: 'سبب الفرق', en: 'Why' }, items: [
    e('gap.headline', 'الرقم الرئيسي', 'Headline', true), e('gap.reason.no_trace', 'حملات بلا أثر', 'No trace', true), e('gap.reason.attribution_rule', 'قاعدة الإسناد', 'Rule', true),
    e('gap.reason.untagged_channel', 'زيارات بلا حملة', 'Untagged', true), e('gap.reason.no_platform_trace', 'مبيعات بلا منصة', 'No platform', true),
  ] },
]

export const ALL_KEYS = ELEMENT_GROUPS.flatMap((g) => g.items.map((i) => i.key))
export const SALES_KEYS = ELEMENT_GROUPS.flatMap((g) => g.items.filter((i) => i.sales).map((i) => i.key))
export const MARKETING_KEYS = ELEMENT_GROUPS.flatMap((g) => g.items.filter((i) => !i.sales).map((i) => i.key))

export function forPreset(p: Ga4Preset): string[] {
  return p === 'marketing' ? [...SALES_KEYS] : p === 'sales' ? [...MARKETING_KEYS] : []
}

export function presetOf(hidden: string[]): Ga4Preset {
  const h = [...new Set(hidden)].sort().join('|')
  for (const p of ['all', 'marketing', 'sales'] as const) {
    if (forPreset(p).sort().join('|') === h) return p
  }

  return 'custom'
}

/** `hide=` for a request: empty when nothing is hidden, so the default URL is the default reading. */
export function hideParam(hidden: string[]): string {
  return hidden.length === 0 ? '' : `&hide=${encodeURIComponent([...hidden].sort().join(','))}`
}
