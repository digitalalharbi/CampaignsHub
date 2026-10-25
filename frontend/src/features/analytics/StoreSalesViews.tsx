import { useState } from 'react'
import { Info } from 'lucide-react'
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MetricTable } from '@/components/ui/MetricTable'
import { Num } from '@/components/ui/Num'
import { KpiCard, Panel } from './components'
import { compact, moneyExact, num } from './format'

/**
 * STORE-REPORT-SECTIONS-001 — the store's own ledger as presentational blocks, drawn by BOTH the Analytics «Store sales» tab and the
 * client's Live report, so the two cannot disagree about a figure or about how absence is worded.
 *
 * Money may be `null`: a link that hides revenue withholds it, and a withheld figure is drawn as «—» with the block saying it was
 * withheld — never as a zero, which would tell a reader the store sold nothing.
 */

export interface Product { key: string; name: string; sku: string | null; quantity: number; sales: number | null; orders: number }
export interface Totals {
  orders: number; cancelled_orders?: number; orders_priced?: number; gross_sales?: number | null; subtotal?: number | null; discounts?: number | null
  shipping?: number | null; tax?: number | null; refunds?: number | null; net_sales?: number | null; aov?: number | null; refund_rate?: number | null
}
export interface BlockMeta {
  currency: string | null
  window: { from: string; to: string; timezone: string; days?: number; previous?: { from: string; to: string } | null }
  stores: { name: string; provider: string; last_synced_at: string | null }[]
  orders_with_money_withheld: number
  duplicates_collapsed: number
  withheld?: string[]
}
export interface SalesBlock extends BlockMeta { totals: Totals; previous: Totals | null; growth: Record<string, number | null> | null; trend: { date: string; orders: number; net_sales: number | null }[] }
export interface ProductsBlock extends BlockMeta {
  products: { by_product: { count: number; best: Product[]; worst: Product[] }; by_sku: { count: number; best: Product[]; worst: Product[] }; units: number; lines_without_sku: number }
  categories: { rows: { category: string; quantity: number; sales: number | null }[]; uncategorised_share: number | null }
}
export interface OrdersBlock extends BlockMeta {
  totals: Totals
  coupons: { orders_with_coupon: number; share_of_orders: number | null; rows: { code: string; orders: number; discount: number | null; net_sales: number | null }[] }
  abandoned_carts: { supported: boolean; count: number | null; value: number | null; complete?: boolean } | null
}
export interface AudienceBlock extends BlockMeta {
  geography: { basis: string; shipping_destination: string; rows: { city: string; orders: number; net_sales: number | null; share: number }[]; unknown_orders: number }
  customers: { new: { customers: number; orders: number; net_sales: number | null }; returning: { customers: number; orders: number; net_sales: number | null }; orders_without_customer: number; retention: null; retention_reason: string }
}

export const STORE_COPY = {
  ar: {
    net: 'صافي المبيعات', gross: 'إجمالي المبيعات', orders: 'الطلبات', aov: 'متوسط قيمة الطلب', discounts: 'الخصومات', refunds: 'المرتجعات',
    shipping: 'الشحن', tax: 'الضريبة', trend: 'اتجاه المبيعات اليومي', trendDesc: 'صافي المبيعات (أعمدة) وعدد الطلبات (خط) بيوم المتجر نفسه.',
    products: 'المنتجات', best: 'الأفضل', worst: 'الأقل مبيعًا', byProduct: 'حسب المنتج', bySku: 'حسب SKU', name: 'الاسم', qty: 'الكمية', sales: 'المبيعات', ordersCol: 'الطلبات',
    categories: 'الفئات', cities: 'المدن', city: 'المدينة', share: 'الحصة', cityNote: 'المدن مأخوذة من مدينة العميل المسجلة؛ وجهة الشحن غير محفوظة في النظام.', unknown: 'غير معروفة',
    customers: 'العملاء', newC: 'عملاء جدد', retC: 'عملاء عائدون', retention: 'الاحتفاظ والمجموعات (cohorts) غير متاحة: تحتاج سجل مجموعات لا يحتفظ به النظام بعد.',
    coupons: 'الكوبونات', code: 'الكود', discount: 'الخصم', couponShare: 'من الطلبات استخدمت كوبونًا', carts: 'السلات المتروكة',
    cartsNo: 'هذه المنصة لا تُرسل السلات المتروكة، لذلك لا نعرض رقمًا.', cartsPartial: 'العدد جزئي: أحد المتاجر لا يُرسل السلات.',
    source: 'المصدر', synced: 'آخر مزامنة', never: 'لم تتم مزامنة بعد', vs: 'مقارنة بالفترة السابقة', none: 'لا بيانات في هذه الفترة.',
    withheldNote: 'المبالغ المالية مخفية في هذا الرابط؛ تظهر الأعداد فقط.',
    withheld: (n: number) => `${n} طلب خارج المبالغ لأن سعر الصرف غير موثوق.`, dup: (n: number) => `${n} طلب مكرر جُمع في طلب واحد.`,
    cancelled: (n: number) => `${n} طلب ملغى غير محسوب كمبيعات.`,
    notBlended: 'هذه أرقام المتجر نفسه (الطلبات المؤكدة)، ولا تُجمع مع إيراد المنصات الإعلانية أو جوجل أناليتكس — لكلٍّ نموذج إسناد مختلف.',
  },
  en: {
    net: 'Net sales', gross: 'Gross sales', orders: 'Orders', aov: 'Average order value', discounts: 'Discounts', refunds: 'Refunds',
    shipping: 'Shipping', tax: 'Tax', trend: 'Daily sales trend', trendDesc: 'Net sales (bars) and orders (line) on the store’s own day.',
    products: 'Products', best: 'Best sellers', worst: 'Lowest sellers', byProduct: 'By product', bySku: 'By SKU', name: 'Name', qty: 'Quantity', sales: 'Sales', ordersCol: 'Orders',
    categories: 'Categories', cities: 'Cities', city: 'City', share: 'Share', cityNote: 'Cities come from the customer’s recorded city; the shipping destination is not stored.', unknown: 'Unknown',
    customers: 'Customers', newC: 'New customers', retC: 'Returning customers', retention: 'Retention and cohorts are not available: they need cohort history this product does not hold yet.',
    coupons: 'Coupons', code: 'Code', discount: 'Discount', couponShare: 'of orders used a coupon', carts: 'Abandoned carts',
    cartsNo: 'This platform does not report abandoned carts, so no figure is shown.', cartsPartial: 'The count is partial: one store does not report carts.',
    source: 'Source', synced: 'Last sync', never: 'Not synced yet', vs: 'vs the previous period', none: 'No data in this period.',
    withheldNote: 'Money figures are hidden on this link; only counts are shown.',
    withheld: (n: number) => `${n} order(s) left out of the money totals because the exchange rate could not be vouched for.`, dup: (n: number) => `${n} duplicate order(s) collapsed into one.`,
    cancelled: (n: number) => `${n} cancelled order(s) are not counted as sales.`,
    notBlended: 'These are the store’s own figures (confirmed orders). They are not added to the ad platforms’ or Google Analytics revenue — each uses a different attribution model.',
  },
}
type Copy = (typeof STORE_COPY)['ar']

const bare = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : compact(n))
const exact = (n: number | null | undefined, cur: string | null): string | undefined => (n === null || n === undefined ? undefined : moneyExact(n, cur))
const pickCopy = (ar: boolean): Copy => (ar ? STORE_COPY.ar : STORE_COPY.en)

function Share({ value }: { value: number }) {
  return (
    <span className="flex items-center gap-2">
      <span className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full bg-brand-500" style={{ width: `${Math.min(100, Math.max(0, value * 100))}%` }} /></span>
      <Num className="tnum text-xs">{(value * 100).toFixed(1)}%</Num>
    </span>
  )
}

function WithheldNote({ meta, t }: { meta: BlockMeta; t: Copy }) {
  return (meta.withheld ?? []).length > 0 ? <p data-testid="store-withheld-note" className="mb-3 flex items-start gap-1.5 text-xs text-text-muted"><Info size={12} className="mt-0.5 shrink-0" />{t.withheldNote}</p> : null
}

/** Store & Sales Intelligence: the headline figures with their movement, and the daily trend. */
export function SalesOverview({ d, ar }: { d: SalesBlock; ar: boolean }) {
  const t = pickCopy(ar)
  const cur = d.currency
  const g = d.growth
  const spark = d.trend.map((p) => p.net_sales).filter((v): v is number => v !== null)
  const chart = d.trend.map((p) => ({ date: p.date.slice(5), net: p.net_sales, orders: p.orders }))
  const fig = (v: number | null | undefined) => bare(v)

  return (
    <div className="space-y-4" data-testid="store-sales-overview">
      <WithheldNote meta={d} t={t} />
      <section data-testid="sales-kpis" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard label={t.net} value={fig(d.totals.net_sales)} exact={exact(d.totals.net_sales, cur)} delta={g ? g.net_sales : undefined} spark={spark} hint={g ? t.vs : undefined} />
        <KpiCard label={t.orders} value={num(d.totals.orders)} delta={g ? g.orders : undefined} hint={g ? t.vs : undefined} />
        <KpiCard label={t.aov} value={fig(d.totals.aov)} exact={exact(d.totals.aov, cur)} delta={g ? g.aov : undefined} hint={g ? t.vs : undefined} />
        <KpiCard label={t.gross} value={fig(d.totals.gross_sales)} exact={exact(d.totals.gross_sales, cur)} delta={g ? g.gross_sales : undefined} hint={g ? t.vs : undefined} />
      </section>
      <Panel className="min-w-0" title={t.trend} description={t.trendDesc} empty={d.trend.length === 0}>
        <div className="h-64 w-full" data-testid="sales-trend">
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={chart} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="l" tick={{ fontSize: 11 }} tickFormatter={(v: number) => compact(v)} width={48} />
              <YAxis yAxisId="r" orientation={ar ? 'left' : 'right'} tick={{ fontSize: 11 }} allowDecimals={false} width={32} />
              <Tooltip formatter={(v, name) => (name === 'net' ? (v === null || v === undefined ? '—' : moneyExact(Number(v), cur)) : num(Number(v)))} />
              <Bar yAxisId="l" dataKey="net" name="net" fill="var(--brand-500, #14b8a6)" radius={[3, 3, 0, 0]} isAnimationActive={false} />
              <Line yAxisId="r" dataKey="orders" name="orders" type="monotone" stroke="var(--info, #3b82f6)" strokeWidth={2} dot={false} isAnimationActive={false} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      </Panel>
    </div>
  )
}

function ProductsTable({ rows, cur, t }: { rows: Product[]; cur: string | null; t: Copy }) {
  if (rows.length === 0) return <p className="text-sm text-text-muted">{t.none}</p>

  return (
    <MetricTable
      head={[t.name, t.qty, t.sales, t.ordersCol]}
      rows={rows.map((r) => [<span key={r.key} className="font-medium">{r.name}{r.sku && <span className="ms-1 text-[11px] text-text-muted" dir="ltr">{r.sku}</span>}</span>, num(r.quantity), r.sales === null ? '—' : moneyExact(r.sales, cur), num(r.orders)])}
      values={rows.map((r) => [r.name, r.quantity, r.sales, r.orders])}
      initialSort={{ column: r0(rows) ? 1 : 2, dir: 'desc' }}
    />
  )
}
/** When sales are withheld every sales cell is null, so the table sorts by quantity instead of by nothing. */
const r0 = (rows: Product[]): boolean => rows.every((r) => r.sales === null)

/** Products / SKUs / Categories. */
export function ProductsSection({ d, ar }: { d: ProductsBlock; ar: boolean }) {
  const t = pickCopy(ar)
  const [side, setSide] = useState<'best' | 'worst'>('best')
  const [basis, setBasis] = useState<'by_product' | 'by_sku'>('by_product')
  const chip = (on: boolean) => `rounded-lg px-2.5 py-1 text-xs font-semibold ${on ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`

  return (
    <div className="space-y-4" data-testid="store-products-section">
      <WithheldNote meta={d} t={t} />
      <Panel className="min-w-0" title={t.products}>
        <div className="mb-3 flex flex-wrap items-center gap-1.5">
          {(['by_product', 'by_sku'] as const).map((b) => <button key={b} type="button" data-testid={`sales-basis-${b}`} aria-pressed={basis === b} onClick={() => setBasis(b)} className={chip(basis === b)}>{b === 'by_product' ? t.byProduct : t.bySku}</button>)}
          {(['best', 'worst'] as const).map((s) => <button key={s} type="button" data-testid={`sales-side-${s}`} aria-pressed={side === s} onClick={() => setSide(s)} className={chip(side === s)}>{s === 'best' ? t.best : t.worst}</button>)}
        </div>
        <div data-testid="sales-products"><ProductsTable rows={d.products[basis][side]} cur={d.currency} t={t} /></div>
      </Panel>
      <Panel className="min-w-0" title={t.categories} empty={d.categories.rows.length === 0}>
        <MetricTable
          head={[t.categories, t.qty, t.sales]}
          rows={d.categories.rows.map((r) => [r.category === 'uncategorised' ? '—' : r.category, num(r.quantity), r.sales === null ? '—' : moneyExact(r.sales, d.currency)])}
          values={d.categories.rows.map((r) => [r.category, r.quantity, r.sales])}
          initialSort={{ column: d.categories.rows.every((r) => r.sales === null) ? 1 : 2, dir: 'desc' }}
        />
      </Panel>
    </div>
  )
}

/** Orders / Refunds / Discounts (+ coupons and abandoned carts). */
export function OrdersSection({ d, ar }: { d: OrdersBlock; ar: boolean }) {
  const t = pickCopy(ar)
  const cur = d.currency
  const o = d.totals

  return (
    <div className="space-y-4" data-testid="store-orders-section">
      <WithheldNote meta={d} t={t} />
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="orders-kpis">
        <KpiCard label={t.orders} value={num(o.orders)} hint={o.cancelled_orders ? t.cancelled(o.cancelled_orders) : undefined} calm />
        <KpiCard label={t.discounts} value={bare(o.discounts)} exact={exact(o.discounts, cur)} invertGood calm />
        <KpiCard label={t.refunds} value={bare(o.refunds)} exact={exact(o.refunds, cur)} invertGood calm />
        <KpiCard label={t.shipping} value={bare(o.shipping)} exact={exact(o.shipping, cur)} calm />
      </section>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel className="min-w-0" title={t.coupons} empty={d.coupons.rows.length === 0}>
          {d.coupons.share_of_orders !== null && <p className="mb-2 text-xs text-text-secondary"><Num className="tnum font-semibold">{(d.coupons.share_of_orders * 100).toFixed(1)}%</Num> {t.couponShare}</p>}
          <MetricTable
            head={[t.code, t.ordersCol, t.discount, t.sales]}
            rows={d.coupons.rows.map((r) => [<span key={r.code} dir="ltr">{r.code}</span>, num(r.orders), r.discount === null ? '—' : moneyExact(r.discount, cur), r.net_sales === null ? '—' : moneyExact(r.net_sales, cur)])}
            values={d.coupons.rows.map((r) => [r.code, r.orders, r.discount, r.net_sales])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </Panel>
        <Panel className="min-w-0" title={t.carts}>
          {d.abandoned_carts?.supported
            ? <div data-testid="sales-carts"><p className="text-sm"><Num className="tnum font-semibold">{num(d.abandoned_carts.count)}</Num> · {d.abandoned_carts.value === null ? '—' : moneyExact(d.abandoned_carts.value, cur)}</p>{d.abandoned_carts.complete === false && <p className="mt-1 text-xs text-warning">{t.cartsPartial}</p>}</div>
            : <p data-testid="sales-carts-unsupported" className="text-sm text-text-muted">{t.cartsNo}</p>}
        </Panel>
      </div>
    </div>
  )
}

/** Cities / Customer Segments. */
export function AudienceSection({ d, ar }: { d: AudienceBlock; ar: boolean }) {
  const t = pickCopy(ar)
  const cur = d.currency

  return (
    <div className="space-y-4" data-testid="store-audience-section">
      <WithheldNote meta={d} t={t} />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel className="min-w-0" title={t.cities} description={t.cityNote} empty={d.geography.rows.length === 0}>
          <div data-testid="sales-cities">
            <MetricTable
              head={[t.city, t.ordersCol, t.sales, t.share]}
              rows={d.geography.rows.map((r) => [r.city === 'unknown' ? t.unknown : r.city, num(r.orders), r.net_sales === null ? '—' : moneyExact(r.net_sales, cur), <Share key={r.city} value={r.share} />])}
              values={d.geography.rows.map((r) => [r.city, r.orders, r.net_sales, r.share])}
              initialSort={{ column: 1, dir: 'desc' }}
            />
          </div>
        </Panel>
        <Panel className="min-w-0" title={t.customers}>
          <div className="grid gap-3 sm:grid-cols-2" data-testid="sales-customers">
            <KpiCard label={t.newC} value={num(d.customers.new.customers)} hint={`${num(d.customers.new.orders)} ${t.ordersCol}`} calm />
            <KpiCard label={t.retC} value={num(d.customers.returning.customers)} hint={`${num(d.customers.returning.orders)} ${t.ordersCol}`} calm />
          </div>
          <p data-testid="sales-retention-unavailable" className="mt-3 flex items-start gap-1.5 text-xs text-text-muted"><Info size={12} className="mt-0.5 shrink-0" />{t.retention}</p>
        </Panel>
      </div>
    </div>
  )
}

/** Where every figure came from, and what was left out of it. Drawn by every store section that carries a block. */
export function StoreProvenance({ d, ar, cancelled = 0 }: { d: BlockMeta; ar: boolean; cancelled?: number }) {
  const t = pickCopy(ar)

  return (
    <section data-testid="sales-provenance" className="rounded-xl border border-border bg-surface-secondary p-3 text-xs text-text-secondary">
      <p className="mb-1 font-semibold text-text-primary">{t.source}</p>
      <ul className="space-y-0.5">
        {d.stores.map((s) => <li key={`${s.name}-${s.provider}`}>{s.name} · {s.provider} · {t.synced}: {s.last_synced_at ? new Date(s.last_synced_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB') : t.never}</li>)}
        <li><Num className="tnum">{d.window.from} → {d.window.to}</Num> · {d.window.timezone}{d.currency ? ` · ${d.currency}` : ''}</li>
        {cancelled > 0 && <li>{t.cancelled(cancelled)}</li>}
        {d.orders_with_money_withheld > 0 && <li data-testid="sales-withheld" className="text-warning">{t.withheld(d.orders_with_money_withheld)}</li>}
        {d.duplicates_collapsed > 0 && <li>{t.dup(d.duplicates_collapsed)}</li>}
        <li data-testid="store-not-blended">{t.notBlended}</li>
      </ul>
    </section>
  )
}
