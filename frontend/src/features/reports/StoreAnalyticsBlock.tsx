import { Badge } from '@/components/ui/Badge'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { MetricLineChart } from '@/features/analytics/charts'
import { money, num, percent } from '@/features/analytics/format'
import { formatFixed } from '@/lib/numerals'

/**
 * STORE-ANALYTICS-DEPTH-001 §3.1 — «تحليلات المتجر والزيارات», on a live link and a snapshot alike.
 *
 * Draws what the server's allow-list sent and nothing else. The store and GA4 are two blocks with their own label and
 * currency: nothing adds them, and the sentence that says so is drawn every time. An amount the link hides is null and
 * reads «—». A block with no source is not drawn, so a missing store is never a row of zeroes.
 */
interface Compared { current: number | null; previous: number | null; change_pct: number | null }

export interface StoreAnalyticsPayload {
  title_ar: string
  title_en: string
  not_summed: true
  note_ar: string
  note_en: string
  store: null | {
    label_ar: string
    label_en: string
    currency: string | null
    demo_excluded: boolean
    orders: { live: number; confirmed: number; cancelled: number; refunded: number }
    sales: Record<'gross' | 'net' | 'discounts' | 'shipping' | 'tax' | 'refunded', Compared>
    averages: { aov: number | null; items_per_order: number | null }
    customers: { new: number; returning: number; repeat_rate: number | null }
    top_products: Array<{ name: string; quantity: number; orders: number; revenue: number | null }>
    trend: Array<{ date: string; orders: number; net: number | null }>
    money_withheld_orders: number
    evidence: null | Array<{ method: string; label_ar: string; label_en: string; orders: number; share: number; net_revenue: number | null }>
  }
  site: null | {
    label_ar: string
    label_en: string
    currency: string | null
    totals: { sessions: number | null; users: number | null; transactions: number | null; revenue: number | null }
    engagement_rate: number | null
    channels: Array<{ class: string; sessions: number | null; purchases: number | null }>
  }
}

const CHANNEL: Record<string, { ar: string; en: string }> = {
  paid: { ar: 'مدفوع', en: 'Paid' }, organic: { ar: 'عضوي', en: 'Organic' }, direct: { ar: 'مباشر', en: 'Direct' }, referral: { ar: 'إحالة', en: 'Referral' }, other: { ar: 'أخرى', en: 'Other' },
}

export function StoreAnalyticsBlock({ data, ar }: { data: StoreAnalyticsPayload; ar: boolean }) {
  const { store, site } = data
  const m = (n: number | null | undefined, c: string | null | undefined) => (n == null ? '—' : money(n, c ?? ''))
  const change = (c: Compared) => (c.change_pct === null ? undefined : `${c.change_pct >= 0 ? '+' : '−'}${percent(Math.abs(c.change_pct), 1)}`)

  return (
    <section data-testid="store-analytics-section" className="min-w-0 space-y-4 rounded-2xl border border-border bg-surface p-4">
      <div className="min-w-0">
        <h3 className="font-bold text-text-primary">{ar ? data.title_ar : data.title_en}</h3>
        <p data-testid="store-analytics-not-summed" className="mt-1 text-xs leading-relaxed text-text-secondary">{ar ? data.note_ar : data.note_en}</p>
      </div>

      {store && (
        <div data-testid="store-analytics-store" className="min-w-0 space-y-3">
          <Badge tone="success">{ar ? store.label_ar : store.label_en}</Badge>
          <StatGrid columns="grid-cols-2 lg:grid-cols-4">
            <StatCard tone="success" label={ar ? 'صافي المبيعات' : 'Net sales'} value={<span dir="ltr">{m(store.sales.net.current, store.currency)}</span>} hint={change(store.sales.net)} />
            <StatCard tone="brand" label={ar ? 'الطلبات' : 'Orders'} value={<span dir="ltr">{num(store.orders.live)}</span>} hint={ar ? `${num(store.orders.confirmed)} مؤكد · ${num(store.orders.cancelled)} ملغى · ${num(store.orders.refunded)} مسترد` : `${num(store.orders.confirmed)} confirmed · ${num(store.orders.cancelled)} cancelled · ${num(store.orders.refunded)} refunded`} />
            <StatCard tone="info" label={ar ? 'متوسط قيمة الطلب' : 'Average order value'} value={<span dir="ltr">{m(store.averages.aov, store.currency)}</span>} hint={store.averages.items_per_order === null ? undefined : (ar ? `${formatFixed(store.averages.items_per_order, 2)} منتج للطلب` : `${formatFixed(store.averages.items_per_order, 2)} items per order`)} />
            <StatCard tone="neutral" label={ar ? 'عملاء جدد · عائدون' : 'New · Returning'} value={<span dir="ltr">{num(store.customers.new)} · {num(store.customers.returning)}</span>} hint={store.customers.repeat_rate === null ? undefined : (ar ? `تكرار الشراء ${percent(store.customers.repeat_rate, 1)}` : `Repeat purchase ${percent(store.customers.repeat_rate, 1)}`)} />
          </StatGrid>
          {store.trend.length >= 2 && (
            <div data-testid="store-analytics-trend" className="min-w-0">
              <MetricLineChart data={store.trend} height={200} series={[{ key: 'orders', name: ar ? 'الطلبات' : 'Orders', kind: 'num' }]} />
            </div>
          )}
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {store.top_products.length > 0 && (
              <div className="min-w-0">
                <h4 className="mb-1 text-xs font-bold text-text-secondary">{ar ? 'أفضل المنتجات' : 'Best products'}</h4>
                <ul data-testid="store-analytics-products" className="space-y-1 text-sm">
                  {store.top_products.map((p) => (
                    <li key={p.name} className="flex min-w-0 items-baseline justify-between gap-3">
                      <span className="min-w-0 truncate" dir="auto" title={p.name}>{p.name}</span>
                      <span className="tnum shrink-0 text-text-secondary" dir="ltr">{num(p.orders)} · {m(p.revenue, store.currency)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {store.evidence && store.evidence.length > 0 && (
              <div className="min-w-0">
                <h4 className="mb-1 text-xs font-bold text-text-secondary">{ar ? 'كيف رُبطت الطلبات بمصادرها' : 'How orders tie to their sources'}</h4>
                <ul data-testid="store-analytics-evidence" className="space-y-1 text-sm">
                  {store.evidence.map((e) => (
                    <li key={e.method} className="flex min-w-0 items-baseline justify-between gap-3">
                      <span className="min-w-0 truncate">{ar ? e.label_ar : e.label_en}</span>
                      <span className="tnum shrink-0 text-text-secondary" dir="ltr">{num(e.orders)} · {percent(e.share, 0)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
          {store.money_withheld_orders > 0 && (
            <p data-testid="store-analytics-withheld" className="text-xs text-warning">{ar ? `${num(store.money_withheld_orders)} طلبًا لا سعر صرف موثوق لعملته، فلم يدخل مبلغه في المجاميع.` : `${num(store.money_withheld_orders)} order(s) have no trustworthy rate, so their amounts are not in the totals.`}</p>
          )}
        </div>
      )}

      {site && (
        <div data-testid="store-analytics-site" className="min-w-0 space-y-3">
          <Badge tone="info">{ar ? site.label_ar : site.label_en}</Badge>
          <StatGrid columns="grid-cols-2 lg:grid-cols-4">
            <StatCard tone="brand" label={ar ? 'الجلسات' : 'Sessions'} value={<span dir="ltr">{num(site.totals.sessions)}</span>} />
            <StatCard tone="info" label={ar ? 'معدل التفاعل' : 'Engagement rate'} value={<span dir="ltr">{site.engagement_rate == null ? '—' : percent(site.engagement_rate, 1)}</span>} />
            <StatCard tone="success" label={ar ? 'المشتريات' : 'Purchases'} value={<span dir="ltr">{num(site.totals.transactions)}</span>} />
            <StatCard tone="neutral" label={ar ? 'إيراد الموقع' : 'Site revenue'} value={<span dir="ltr">{m(site.totals.revenue, site.currency)}</span>} />
          </StatGrid>
          {site.channels.length > 0 && (
            <ul data-testid="store-analytics-channels" className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
              {site.channels.map((c) => (
                <li key={c.class} className="min-w-0 rounded-lg bg-surface-hover px-2.5 py-2">
                  <span className="block truncate text-xs text-text-muted">{CHANNEL[c.class]?.[ar ? 'ar' : 'en'] ?? c.class}</span>
                  <span className="tnum font-bold" dir="ltr">{num(c.sessions)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </section>
  )
}
