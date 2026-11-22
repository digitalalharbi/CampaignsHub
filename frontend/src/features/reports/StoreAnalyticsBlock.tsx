import { Globe, Link2, Store as StoreIcon, Unlink } from 'lucide-react'
import { ShareTiles } from './ShareTiles'
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

const ORDER_COLOR: Record<string, string> = { confirmed: 'var(--success)', cancelled: 'var(--danger)', refunded: 'var(--warning)' }

const CHANNEL: Record<string, { ar: string; en: string }> = {
  paid: { ar: 'مدفوع', en: 'Paid' }, organic: { ar: 'عضوي', en: 'Organic' }, direct: { ar: 'مباشر', en: 'Direct' }, referral: { ar: 'إحالة', en: 'Referral' }, other: { ar: 'أخرى', en: 'Other' },
}

/** A small figure tile: label, value, an optional muted line — the report's compact tile (WEBSITE-VISUAL-001). */
function Tile({ label, value, hint, accent }: { label: string; value: string; hint?: string; accent?: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 rounded-xl border border-border bg-surface px-3 py-2.5">
      <span className="flex items-center gap-1.5 truncate text-[11px] font-semibold text-text-muted">{accent && <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: accent }} />}{label}</span>
      <span className="tnum truncate text-lg font-extrabold leading-tight text-text-primary" dir="ltr">{value}</span>
      {hint && <span className="tnum truncate text-[10px] text-text-muted">{hint}</span>}
    </div>
  )
}

export function StoreAnalyticsBlock({ data, ar }: { data: StoreAnalyticsPayload; ar: boolean }) {
  const { store, site } = data
  const m = (n: number | null | undefined, c: string | null | undefined) => (n == null ? '—' : money(n, c ?? ''))
  const change = (c: Compared) => (c.change_pct === null ? undefined : `${c.change_pct >= 0 ? '+' : '−'}${percent(Math.abs(c.change_pct), 1)}`)

  return (
    <section data-testid="store-analytics-section" className="min-w-0 space-y-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex min-w-0 flex-wrap items-center justify-between gap-2">
        <h3 className="font-bold text-text-primary">{ar ? data.title_ar : data.title_en}</h3>
        {/* WEBSITE-VISUAL-001 — the «not added» rule as one chip, the sentence behind it on hover. */}
        <span data-testid="store-analytics-not-summed" title={ar ? data.note_ar : data.note_en} className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
          <Unlink size={12} aria-hidden />{ar ? 'مصدران مستقلان · لا يُجمعان' : 'Two separate sources · not added'}
        </span>
      </div>

      {store && (
        <div data-testid="store-analytics-store" className="min-w-0 space-y-3 rounded-xl bg-surface-secondary/50 p-3">
          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-success"><StoreIcon size={14} aria-hidden />{ar ? store.label_ar : store.label_en}</span>
          <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <Tile accent="var(--success)" label={ar ? 'صافي المبيعات' : 'Net sales'} value={m(store.sales.net.current, store.currency)} hint={change(store.sales.net)} />
            <Tile accent="var(--brand-600)" label={ar ? 'الطلبات' : 'Orders'} value={num(store.orders.live)} />
            <Tile accent="var(--info)" label={ar ? 'متوسط قيمة الطلب' : 'Average order value'} value={m(store.averages.aov, store.currency)} hint={store.averages.items_per_order === null ? undefined : (ar ? `${formatFixed(store.averages.items_per_order, 2)} منتج للطلب` : `${formatFixed(store.averages.items_per_order, 2)} items per order`)} />
            <Tile accent="var(--purple)" label={ar ? 'عملاء جدد · عائدون' : 'New · Returning'} value={`${num(store.customers.new)} · ${num(store.customers.returning)}`} hint={store.customers.repeat_rate === null ? undefined : (ar ? `تكرار الشراء ${percent(store.customers.repeat_rate, 1)}` : `Repeat purchase ${percent(store.customers.repeat_rate, 1)}`)} />
          </div>
          <ShareTiles testId="store-analytics-orders" cols="grid-cols-3" colorOf={(k) => ORDER_COLOR[k] ?? 'var(--text-muted)'} parts={[
            { key: 'confirmed', label: ar ? 'مؤكد' : 'confirmed', value: store.orders.confirmed },
            { key: 'cancelled', label: ar ? 'ملغى' : 'cancelled', value: store.orders.cancelled },
            { key: 'refunded', label: ar ? 'مسترد' : 'refunded', value: store.orders.refunded },
          ]} />
          {store.trend.length >= 2 && (
            <div data-testid="store-analytics-trend" className="min-w-0">
              <MetricLineChart data={store.trend} height={160} series={[{ key: 'orders', name: ar ? 'الطلبات' : 'Orders', kind: 'num' }]} />
            </div>
          )}
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {store.top_products.length > 0 && (() => {
              const peak = Math.max(...store.top_products.map((p) => p.orders), 1)

              return (
                <div className="min-w-0">
                  <h4 className="mb-1.5 text-xs font-bold text-text-secondary">{ar ? 'أفضل المنتجات' : 'Best products'}</h4>
                  <ul data-testid="store-analytics-products" className="space-y-1.5 text-xs">
                    {store.top_products.map((p) => (
                      <li key={p.name} className="flex min-w-0 flex-col gap-0.5">
                        <span className="flex items-baseline justify-between gap-3">
                          <span className="min-w-0 truncate font-semibold" dir="auto" title={p.name}>{p.name}</span>
                          <span className="tnum shrink-0 text-text-secondary" dir="ltr">{num(p.orders)} · {m(p.revenue, store.currency)}</span>
                        </span>
                        <span className="h-1.5 rounded-full bg-success/70" style={{ width: `${Math.max(4, (p.orders / peak) * 100)}%` }} />
                      </li>
                    ))}
                  </ul>
                </div>
              )
            })()}
            {store.evidence && store.evidence.length > 0 && (
              <div className="min-w-0">
                <h4 className="mb-1.5 text-xs font-bold text-text-secondary">{ar ? 'كيف رُبطت الطلبات بمصادرها' : 'How orders tie to their sources'}</h4>
                <ShareTiles testId="store-analytics-evidence" cols="grid-cols-2" parts={store.evidence.map((e) => ({ key: e.method, label: ar ? e.label_ar : e.label_en, value: e.orders, icon: Link2 }))} />
              </div>
            )}
          </div>
          {store.money_withheld_orders > 0 && (
            <p data-testid="store-analytics-withheld" className="text-xs text-warning">{ar ? `${num(store.money_withheld_orders)} طلبًا لا سعر صرف موثوق لعملته، فلم يدخل مبلغه في المجاميع.` : `${num(store.money_withheld_orders)} order(s) have no trustworthy rate, so their amounts are not in the totals.`}</p>
          )}
        </div>
      )}

      {site && (
        <div data-testid="store-analytics-site" className="min-w-0 space-y-3 rounded-xl bg-surface-secondary/50 p-3">
          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-info"><Globe size={14} aria-hidden />{ar ? site.label_ar : site.label_en}</span>
          <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <Tile accent="var(--brand-600)" label={ar ? 'الجلسات' : 'Sessions'} value={num(site.totals.sessions)} />
            <Tile accent="var(--info)" label={ar ? 'معدل التفاعل' : 'Engagement rate'} value={site.engagement_rate == null ? '—' : percent(site.engagement_rate, 1)} />
            <Tile accent="var(--success)" label={ar ? 'الطلبات' : 'Purchases'} value={num(site.totals.transactions)} />
            <Tile accent="var(--teal)" label={ar ? 'إيراد الموقع' : 'Site revenue'} value={m(site.totals.revenue, site.currency)} />
          </div>
          {site.channels.length > 0 && (
            <ShareTiles testId="store-analytics-channels" parts={site.channels.map((c) => ({ key: c.class, label: CHANNEL[c.class]?.[ar ? 'ar' : 'en'] ?? c.class, value: c.sessions ?? 0 }))} />
          )}
        </div>
      )}
    </section>
  )
}
