import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Info, Store } from 'lucide-react'
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MetricTable } from '@/components/ui/MetricTable'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Num } from '@/components/ui/Num'
import { getData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { KpiCard, Panel } from './components'
import { compact, moneyExact, num } from './format'

/**
 * COMMERCE-INSIGHTS-001 — «مبيعات المتجر»: what the STORE says, with the source and the limits of every figure stated.
 *
 * Read from the merchant's own ledger only. Nothing here is an advertising figure and nothing is added to one; where the store
 * cannot answer (cost, shipping destination, retention, carts on a platform that has none) the panel says so in words rather than
 * drawing a zero.
 */

interface Product { key: string; name: string; sku: string | null; quantity: number; sales: number; orders: number }
interface Totals {
  orders: number; cancelled_orders: number; orders_priced: number; gross_sales: number; subtotal: number; discounts: number
  shipping: number; tax: number; refunds: number; net_sales: number; aov: number | null; refund_rate: number | null
}
export interface InsightsPayload {
  window: { from: string; to: string; timezone: string; days: number; previous: { from: string; to: string } | null }
  provenance: {
    currency: string; has_store: boolean; duplicates_collapsed: number; orders_with_money_withheld: number; orders_with_assumed_timezone: number
    stores: { id: string; name: string; provider: string; last_synced_at: string | null }[]
  }
  totals: Totals
  previous: Totals | null
  growth: Record<string, number | null> | null
  trend: { date: string; orders: number; net_sales: number }[]
  products: {
    by_product: { count: number; best: Product[]; worst: Product[] }
    by_sku: { count: number; best: Product[]; worst: Product[] }
    units: number
    lines_without_sku: number
  }
  categories: { rows: { category: string; quantity: number; sales: number }[]; uncategorised_share: number | null }
  geography: { basis: string; shipping_destination: string; rows: { city: string; orders: number; net_sales: number; share: number }[]; unknown_orders: number }
  customers: {
    new: { customers: number; orders: number; net_sales: number }
    returning: { customers: number; orders: number; net_sales: number }
    orders_without_customer: number
    retention: null
    retention_reason: string
  }
  coupons: { orders_with_coupon: number; share_of_orders: number | null; rows: { code: string; orders: number; discount: number; net_sales: number }[] }
  abandoned_carts: { supported: boolean; count: number | null; value: number | null; complete?: boolean }
  profitability: { available: boolean; reason?: string }
}

const COPY = {
  ar: {
    pick: 'اختر مشروعًا', pickDesc: 'مبيعات المتجر تُقرأ على مستوى المشروع.', fail: 'تعذّر تحميل مبيعات المتجر.',
    noStore: 'لا يوجد متجر مربوط بهذا المشروع، أو لم تصل منه طلبات بعد. اربط سلة أو زد من صفحة التكاملات.',
    net: 'صافي المبيعات', gross: 'إجمالي المبيعات', orders: 'الطلبات', aov: 'متوسط قيمة الطلب', discounts: 'الخصومات', refunds: 'المرتجعات',
    shipping: 'الشحن', tax: 'الضريبة', trend: 'اتجاه المبيعات اليومي', trendDesc: 'صافي المبيعات (أعمدة) وعدد الطلبات (خط) بيوم المتجر نفسه.',
    products: 'المنتجات', best: 'الأفضل', worst: 'الأقل مبيعًا', byProduct: 'حسب المنتج', bySku: 'حسب SKU', name: 'الاسم', qty: 'الكمية', sales: 'المبيعات', ordersCol: 'الطلبات',
    categories: 'الفئات', cities: 'المدن', city: 'المدينة', share: 'الحصة', cityNote: 'المدن مأخوذة من مدينة العميل المسجلة؛ وجهة الشحن غير محفوظة في النظام.', unknown: 'غير معروفة',
    customers: 'العملاء', newC: 'عملاء جدد', retC: 'عملاء عائدون', retention: 'الاحتفاظ والمجموعات (cohorts) غير متاحة: تحتاج سجل مجموعات لا يحتفظ به النظام بعد.',
    coupons: 'الكوبونات', code: 'الكود', discount: 'الخصم', couponShare: 'من الطلبات استخدمت كوبونًا', carts: 'السلات المتروكة',
    cartsNo: 'هذه المنصة لا تُرسل السلات المتروكة، لذلك لا نعرض رقمًا.', cartsPartial: 'العدد جزئي: أحد المتاجر لا يُرسل السلات.',
    profit: 'الربحية', profitNo: 'الربحية غير متاحة: لا توجد بيانات تكلفة للمنتجات.',
    source: 'المصدر', synced: 'آخر مزامنة', never: 'لم تتم مزامنة بعد', vs: 'مقارنة بالفترة السابقة',
    withheld: (n: number) => `${n} طلب خارج المبالغ لأن سعر الصرف غير موثوق.`, dup: (n: number) => `${n} طلب مكرر جُمع في طلب واحد.`,
    cancelled: (n: number) => `${n} طلب ملغى غير محسوب كمبيعات.`, none: 'لا بيانات في هذه الفترة.',
  },
  en: {
    pick: 'Pick a project', pickDesc: 'Store sales are read per project.', fail: 'Store sales could not be loaded.',
    noStore: 'No store is connected to this project, or none of its orders have arrived. Connect Salla or Zid from the integrations page.',
    net: 'Net sales', gross: 'Gross sales', orders: 'Orders', aov: 'Average order value', discounts: 'Discounts', refunds: 'Refunds',
    shipping: 'Shipping', tax: 'Tax', trend: 'Daily sales trend', trendDesc: 'Net sales (bars) and orders (line) on the store’s own day.',
    products: 'Products', best: 'Best sellers', worst: 'Lowest sellers', byProduct: 'By product', bySku: 'By SKU', name: 'Name', qty: 'Quantity', sales: 'Sales', ordersCol: 'Orders',
    categories: 'Categories', cities: 'Cities', city: 'City', share: 'Share', cityNote: 'Cities come from the customer’s recorded city; the shipping destination is not stored.', unknown: 'Unknown',
    customers: 'Customers', newC: 'New customers', retC: 'Returning customers', retention: 'Retention and cohorts are not available: they need cohort history this product does not hold yet.',
    coupons: 'Coupons', code: 'Code', discount: 'Discount', couponShare: 'of orders used a coupon', carts: 'Abandoned carts',
    cartsNo: 'This platform does not report abandoned carts, so no figure is shown.', cartsPartial: 'The count is partial: one store does not report carts.',
    profit: 'Profitability', profitNo: 'Profitability is unavailable: no product cost data.',
    source: 'Source', synced: 'Last sync', never: 'Not synced yet', vs: 'vs the previous period',
    withheld: (n: number) => `${n} order(s) left out of the money totals because the exchange rate could not be vouched for.`, dup: (n: number) => `${n} duplicate order(s) collapsed into one.`,
    cancelled: (n: number) => `${n} cancelled order(s) are not counted as sales.`, none: 'No data in this period.',
  },
}

function Share({ value }: { value: number }) {
  return (
    <span className="flex items-center gap-2">
      <span className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full bg-brand-500" style={{ width: `${Math.min(100, Math.max(0, value * 100))}%` }} /></span>
      <Num className="tnum text-xs">{(value * 100).toFixed(1)}%</Num>
    </span>
  )
}

function ProductsTable({ rows, cur, t }: { rows: Product[]; cur: string; t: (typeof COPY)['ar'] }) {
  if (rows.length === 0) return <p className="text-sm text-text-muted">{t.none}</p>

  return (
    <MetricTable
      head={[t.name, t.qty, t.sales, t.ordersCol]}
      rows={rows.map((r) => [<span key={r.key} className="font-medium">{r.name}{r.sku && <span className="ms-1 text-[11px] text-text-muted" dir="ltr">{r.sku}</span>}</span>, num(r.quantity), moneyExact(r.sales, cur), num(r.orders)])}
      values={rows.map((r) => [r.name, r.quantity, r.sales, r.orders])}
      initialSort={{ column: 2, dir: 'desc' }}
    />
  )
}

export function StoreSalesTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const [side, setSide] = useState<'best' | 'worst'>('best')
  const [basis, setBasis] = useState<'by_product' | 'by_sku'>('by_product')

  const q = useQuery({
    queryKey: ['commerce-insights', projectId, range.from, range.to],
    queryFn: () => getData<InsightsPayload>(`/projects/${projectId}/commerce/insights?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (!projectId) return <EmptyState title={t.pick} description={t.pickDesc} />
  if (q.isLoading) return <Skeleton className="h-96" />
  if (q.isError || !q.data) return <QueryFailure error={q.error} ar={ar} testId="store-sales-failure" onRetry={() => void q.refetch()} fallbackTitle={t.fail} />

  const d = q.data
  const cur = d.provenance.currency
  const g = d.growth
  const empty = !d.provenance.has_store && d.totals.orders === 0
  const spark = d.trend.map((p) => p.net_sales)
  const chart = d.trend.map((p) => ({ date: p.date.slice(5), net: p.net_sales, orders: p.orders }))

  return (
    <div className="space-y-4" data-testid="store-sales">
      {empty && (
        <p data-testid="sales-no-store" className="flex items-start gap-2 rounded-xl bg-[var(--warning-background)] px-4 py-3 text-sm text-warning"><Store size={15} className="mt-0.5 shrink-0" />{t.noStore}</p>
      )}

      <section data-testid="sales-kpis" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard label={t.net} value={compact(d.totals.net_sales)} exact={moneyExact(d.totals.net_sales, cur)} delta={g ? g.net_sales : undefined} spark={spark} hint={g ? t.vs : undefined} />
        <KpiCard label={t.orders} value={num(d.totals.orders)} delta={g ? g.orders : undefined} hint={g ? t.vs : undefined} />
        <KpiCard label={t.aov} value={d.totals.aov === null ? '—' : compact(d.totals.aov)} exact={d.totals.aov === null ? undefined : moneyExact(d.totals.aov, cur)} delta={g ? g.aov : undefined} hint={g ? t.vs : undefined} />
        <KpiCard label={t.gross} value={compact(d.totals.gross_sales)} exact={moneyExact(d.totals.gross_sales, cur)} delta={g ? g.gross_sales : undefined} hint={g ? t.vs : undefined} />
        <KpiCard label={t.discounts} value={compact(d.totals.discounts)} exact={moneyExact(d.totals.discounts, cur)} delta={g ? g.discounts : undefined} invertGood calm />
        <KpiCard label={t.refunds} value={compact(d.totals.refunds)} exact={moneyExact(d.totals.refunds, cur)} delta={g ? g.refunds : undefined} invertGood calm />
        <KpiCard label={t.shipping} value={compact(d.totals.shipping)} exact={moneyExact(d.totals.shipping, cur)} calm />
        <KpiCard label={t.tax} value={compact(d.totals.tax)} exact={moneyExact(d.totals.tax, cur)} calm />
      </section>

      <Panel className="min-w-0" title={t.trend} description={t.trendDesc} empty={d.trend.length === 0}>
        <div className="h-64 w-full" data-testid="sales-trend">
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={chart} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="l" tick={{ fontSize: 11 }} tickFormatter={(v: number) => compact(v)} width={48} />
              <YAxis yAxisId="r" orientation={ar ? 'left' : 'right'} tick={{ fontSize: 11 }} allowDecimals={false} width={32} />
              <Tooltip formatter={(v, name) => (name === 'net' ? moneyExact(Number(v), cur) : num(Number(v)))} />
              <Bar yAxisId="l" dataKey="net" name="net" fill="var(--brand-500, #14b8a6)" radius={[3, 3, 0, 0]} isAnimationActive={false} />
              <Line yAxisId="r" dataKey="orders" name="orders" type="monotone" stroke="var(--info, #3b82f6)" strokeWidth={2} dot={false} isAnimationActive={false} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      </Panel>

      <Panel className="min-w-0" title={t.products}>
        <div className="mb-3 flex flex-wrap items-center gap-1.5">
            {(['by_product', 'by_sku'] as const).map((b) => <button key={b} type="button" data-testid={`sales-basis-${b}`} aria-pressed={basis === b} onClick={() => setBasis(b)} className={`rounded-lg px-2.5 py-1 text-xs font-semibold ${basis === b ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`}>{b === 'by_product' ? t.byProduct : t.bySku}</button>)}
            {(['best', 'worst'] as const).map((s) => <button key={s} type="button" data-testid={`sales-side-${s}`} aria-pressed={side === s} onClick={() => setSide(s)} className={`rounded-lg px-2.5 py-1 text-xs font-semibold ${side === s ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`}>{s === 'best' ? t.best : t.worst}</button>)}
          </div>
        <div data-testid="sales-products"><ProductsTable rows={d.products[basis][side]} cur={cur} t={t} /></div>
      </Panel>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel className="min-w-0" title={t.categories} empty={d.categories.rows.length === 0}>
          <MetricTable
            head={[t.categories, t.qty, t.sales]}
            rows={d.categories.rows.map((r) => [r.category === 'uncategorised' ? '—' : r.category, num(r.quantity), moneyExact(r.sales, cur)])}
            values={d.categories.rows.map((r) => [r.category, r.quantity, r.sales])}
            initialSort={{ column: 2, dir: 'desc' }}
          />
        </Panel>
        <Panel className="min-w-0" title={t.cities} description={t.cityNote} empty={d.geography.rows.length === 0}>
          <div data-testid="sales-cities">
            <MetricTable
              head={[t.city, t.ordersCol, t.sales, t.share]}
              rows={d.geography.rows.map((r) => [r.city === 'unknown' ? t.unknown : r.city, num(r.orders), moneyExact(r.net_sales, cur), <Share key={r.city} value={r.share} />])}
              values={d.geography.rows.map((r) => [r.city, r.orders, r.net_sales, r.share])}
              initialSort={{ column: 1, dir: 'desc' }}
            />
          </div>
        </Panel>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel className="min-w-0" title={t.customers}>
          <div className="grid gap-3 sm:grid-cols-2" data-testid="sales-customers">
            <KpiCard label={t.newC} value={num(d.customers.new.customers)} hint={`${num(d.customers.new.orders)} ${t.ordersCol}`} calm />
            <KpiCard label={t.retC} value={num(d.customers.returning.customers)} hint={`${num(d.customers.returning.orders)} ${t.ordersCol}`} calm />
          </div>
          <p data-testid="sales-retention-unavailable" className="mt-3 flex items-start gap-1.5 text-xs text-text-muted"><Info size={12} className="mt-0.5 shrink-0" />{t.retention}</p>
        </Panel>
        <Panel className="min-w-0" title={t.coupons} empty={d.coupons.rows.length === 0}>
          {d.coupons.share_of_orders !== null && <p className="mb-2 text-xs text-text-secondary"><Num className="tnum font-semibold">{(d.coupons.share_of_orders * 100).toFixed(1)}%</Num> {t.couponShare}</p>}
          <MetricTable
            head={[t.code, t.ordersCol, t.discount, t.sales]}
            rows={d.coupons.rows.map((r) => [<span key={r.code} dir="ltr">{r.code}</span>, num(r.orders), moneyExact(r.discount, cur), moneyExact(r.net_sales, cur)])}
            values={d.coupons.rows.map((r) => [r.code, r.orders, r.discount, r.net_sales])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </Panel>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel className="min-w-0" title={t.carts}>
          {d.abandoned_carts.supported
            ? <div data-testid="sales-carts"><p className="text-sm"><Num className="tnum font-semibold">{num(d.abandoned_carts.count)}</Num> · {moneyExact(d.abandoned_carts.value, cur)}</p>{d.abandoned_carts.complete === false && <p className="mt-1 text-xs text-warning">{t.cartsPartial}</p>}</div>
            : <p data-testid="sales-carts-unsupported" className="text-sm text-text-muted">{t.cartsNo}</p>}
        </Panel>
        <Panel className="min-w-0" title={t.profit}>
          <p data-testid="sales-profit-unavailable" className="text-sm text-text-muted">{d.profitability.available ? '' : t.profitNo}</p>
        </Panel>
      </div>

      <section data-testid="sales-provenance" className="rounded-xl border border-border bg-surface-secondary p-3 text-xs text-text-secondary">
        <p className="mb-1 font-semibold text-text-primary">{t.source}</p>
        <ul className="space-y-0.5">
          {d.provenance.stores.map((s) => <li key={s.id}>{s.name} · {s.provider} · {t.synced}: {s.last_synced_at ? new Date(s.last_synced_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB') : t.never}</li>)}
          <li><Num className="tnum">{d.window.from} → {d.window.to}</Num> · {d.window.timezone} · {cur}</li>
          {d.totals.cancelled_orders > 0 && <li>{t.cancelled(d.totals.cancelled_orders)}</li>}
          {d.provenance.orders_with_money_withheld > 0 && <li data-testid="sales-withheld" className="text-warning">{t.withheld(d.provenance.orders_with_money_withheld)}</li>}
          {d.provenance.duplicates_collapsed > 0 && <li>{t.dup(d.provenance.duplicates_collapsed)}</li>}
        </ul>
      </section>
    </div>
  )
}
