import type { ReactNode } from 'react'
import { ArrowDownRight, ArrowUpRight, Banknote, CalendarClock, Clock, CreditCard, MapPin, Package, Percent, Receipt, RotateCcw,  ShoppingCart,  Tag, Truck, Users } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { localStill } from '@/features/content/providerMedia'
import { mediaFitClass } from '@/features/content/adPreview'
import { ChartCard, KpiSparkline, PlatformDonutChart } from './charts'
import { money, num, percent } from './format'
import { formatFixed } from '@/lib/numerals'
import type { ProductRow, StoreDepthPayload, StoreInsightsPayload, StoreMix } from './storeAnalyticsApi'

/**
 * STORE-INSIGHTS-001 (Owner 2026-11-25) — «جانب إبداعي وتحليلي عميق جدًا لتقارير المتجر … بطاقات كبيرة وصغيرة … دائرية».
 *
 * The store page read the way a Salla or Zid merchant reads their own dashboard, but deeper: four large cards with their
 * trend, a strip of small cards, donuts for how orders are made up (state, payment, city, new vs returning, what the money
 * is), the week as a heat map of when people buy, the basket sizes, the best sellers with their pictures and the coupons
 * that carried sales. Every figure is the store's own (STORE-ANALYTICS-DEPTH-001); a part with nothing to show is not drawn.
 */
type L = { ar: string; en: string }
type Depth = Required<Pick<StoreDepthPayload, 'sales' | 'orders' | 'averages' | 'conversion' | 'products' | 'customers' | 'abandoned'>> & StoreDepthPayload

export const STATUS: Record<string, L> = {
  completed: { ar: 'مكتمل', en: 'Completed' }, delivered: { ar: 'تم التوصيل', en: 'Delivered' }, delivering: { ar: 'جاري التوصيل', en: 'Delivering' },
  shipped: { ar: 'تم الشحن', en: 'Shipped' }, in_progress: { ar: 'قيد التنفيذ', en: 'In progress' }, processing: { ar: 'قيد التجهيز', en: 'Processing' },
  under_review: { ar: 'بانتظار المراجعة', en: 'Under review' }, payment_pending: { ar: 'بانتظار الدفع', en: 'Awaiting payment' }, pending: { ar: 'معلّق', en: 'Pending' },
  restored: { ar: 'مسترجع', en: 'Returned' }, restoring: { ar: 'قيد الاسترجاع', en: 'Being returned' }, cancelled: { ar: 'ملغي', en: 'Cancelled' }, canceled: { ar: 'ملغي', en: 'Cancelled' },
  paid: { ar: 'مدفوع', en: 'Paid' }, ready: { ar: 'جاهز', en: 'Ready' }, new: { ar: 'جديد', en: 'New' }, unknown: { ar: 'غير محدد', en: 'Not stated' },
}
export const PAYMENT: Record<string, L> = {
  mada: { ar: 'مدى', en: 'mada' }, credit_card: { ar: 'بطاقة ائتمانية', en: 'Credit card' }, creditcard: { ar: 'بطاقة ائتمانية', en: 'Credit card' },
  apple_pay: { ar: 'Apple Pay', en: 'Apple Pay' }, stc_pay: { ar: 'STC Pay', en: 'STC Pay' }, cod: { ar: 'الدفع عند الاستلام', en: 'Cash on delivery' },
  bank: { ar: 'تحويل بنكي', en: 'Bank transfer' }, tabby: { ar: 'تابي', en: 'Tabby' }, tamara: { ar: 'تمارا', en: 'Tamara' }, paypal: { ar: 'PayPal', en: 'PayPal' },
  wallet: { ar: 'رصيد العميل', en: 'Store credit' }, free: { ar: 'مجاني', en: 'Free' },
}
const DAYS: L[] = [
  { ar: 'الأحد', en: 'Sun' }, { ar: 'الاثنين', en: 'Mon' }, { ar: 'الثلاثاء', en: 'Tue' }, { ar: 'الأربعاء', en: 'Wed' },
  { ar: 'الخميس', en: 'Thu' }, { ar: 'الجمعة', en: 'Fri' }, { ar: 'السبت', en: 'Sat' },
]
const tx = (l: L, ar: boolean) => (ar ? l.ar : l.en)
export const label = (map: Record<string, L>, key: string, ar: boolean) => (map[key.toLowerCase()] ? tx(map[key.toLowerCase()], ar) : key)
const hourText = (h: number, ar: boolean) => (ar ? `${h % 12 === 0 ? 12 : h % 12} ${h < 12 ? 'ص' : 'م'}` : `${h % 12 === 0 ? 12 : h % 12}${h < 12 ? 'am' : 'pm'}`)

/* ───────────────────────────── cards ───────────────────────────── */

function Delta({ pct, testId }: { pct: number | null | undefined; testId: string }) {
  if (pct === null || pct === undefined || Math.abs(pct) < 0.0005) return null
  const up = pct > 0
  const Icon = up ? ArrowUpRight : ArrowDownRight

  return (
    <span data-testid={testId} className={`tnum inline-flex items-center gap-0.5 rounded-full px-1.5 text-xs font-bold ${up ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>
      <Icon size={11} aria-hidden /><Num>{percent(Math.abs(pct), 0)}</Num>
    </span>
  )
}

function HeroCard({ testId, icon, label: l, value, delta, spark, color, foot }: { testId: string; icon: ReactNode; label: string; value: string; delta?: ReactNode; spark?: number[]; color?: string; foot?: string }) {
  // The shared card (KPI consolidation): this surface brings the icon, the trend and the chip, never its own type scale.
  return (
    <StatCard testid={testId} label={<span className="inline-flex items-center gap-1.5">{icon}{l}</span>} value={<Num>{value}</Num>} hint={foot} trailing={delta}
      spark={spark && spark.length >= 2 ? <div className="h-9" aria-hidden><KpiSparkline points={spark} color={color} height={36} /></div> : undefined} />
  )
}

export function MiniCard({ testId, icon, label: l, value, sub, tone = 'neutral' }: { testId: string; icon: ReactNode; label: string; value: string; sub?: string; tone?: 'neutral' | 'warning' | 'success' }) {
  const ring = tone === 'warning' ? 'text-warning' : tone === 'success' ? 'text-success' : 'text-brand-600'

  return (
    <div data-testid={testId} className="flex min-w-0 items-center gap-2.5 rounded-xl border border-border bg-surface px-3 py-2.5">
      <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-full bg-surface-secondary ${ring}`}>{icon}</span>
      <span className="flex min-w-0 flex-col">
        <span className="truncate text-xs font-semibold text-text-muted">{l}</span>
        <span className="tnum truncate text-sm font-extrabold text-text-primary"><Num>{value}</Num></span>
        {sub && <span className="truncate text-xs text-text-muted"><Num>{sub}</Num></span>}
      </span>
    </div>
  )
}

/* ───────────────────────────── donuts ───────────────────────────── */

function Donut({ testId, title, icon, rows, center, centerLabel, foot, kind = 'num', cur = '', height = 230 }: { testId: string; title: string; icon: ReactNode; rows: Array<{ name: string; value: number }>; center?: string; centerLabel?: string; foot?: string; kind?: 'num' | 'money'; cur?: string; height?: number }) {
  const data = rows.filter((r) => r.value > 0)
  if (data.length === 0) return null

  return (
    <ChartCard title={title}>
      <div data-testid={testId} className="min-w-0">
        <span className="mb-1 inline-flex text-text-muted" aria-hidden>{icon}</span>
        <PlatformDonutChart colorBy="series" height={height} data={data} valueKind={kind} currency={cur} centerValue={center} centerLabel={centerLabel} />
        {foot && <p className="mt-1 text-center text-xs text-text-muted"><Num>{foot}</Num></p>}
      </div>
    </ChartCard>
  )
}

function mixRows(mix: StoreMix, map: Record<string, L> | null, ar: boolean) {
  const rows = mix.rows.map((r) => ({ name: map ? label(map, r.key, ar) : r.key, value: r.orders }))
  if (mix.other) rows.push({ name: ar ? `أخرى (${num(mix.other.keys)})` : `Other (${num(mix.other.keys)})`, value: mix.other.orders })

  return rows
}

function unstatedFoot(mix: StoreMix, ar: boolean): string | undefined {
  return mix.unstated > 0 ? (ar ? `${num(mix.unstated)} طلبًا لم يذكر هذا الحقل` : `${num(mix.unstated)} order(s) did not state this`) : undefined
}

/* ───────────────────────────── heat map ───────────────────────────── */

function Heatmap({ h, ar }: { h: StoreInsightsPayload['heatmap']; ar: boolean }) {
  if (h.max === 0) return null
  const bestDay = h.by_day.indexOf(Math.max(...h.by_day))
  const bestHour = h.by_hour.indexOf(Math.max(...h.by_hour))

  return (
    <ChartCard title={ar ? 'متى يشتري عملاؤك' : 'When your customers buy'}
      action={h.peak && <span data-testid="store-heatmap-peak" className="rounded-full bg-brand-600/10 px-2.5 py-1 text-xs font-bold text-brand-700"><Num>{ar ? `الذروة: ${tx(DAYS[h.peak.day], ar)} ${hourText(h.peak.hour, ar)} · ${num(h.peak.orders)} طلب` : `Peak: ${tx(DAYS[h.peak.day], ar)} ${hourText(h.peak.hour, ar)} · ${num(h.peak.orders)} orders`}</Num></span>}>
      <div data-testid="store-heatmap" className="min-w-0 overflow-x-auto">
        <div className="grid min-w-[560px] gap-[3px]" style={{ gridTemplateColumns: '4rem repeat(24, minmax(0, 1fr))' }} dir="ltr">
          <span />
          {Array.from({ length: 24 }, (_, i) => <span key={i} className="text-center text-xs text-text-muted">{i % 3 === 0 ? hourText(i, ar) : ''}</span>)}
          {h.cells.map((hours, d) => (
            <div key={d} className="contents">
              <span className={`truncate pe-1 text-end text-xs font-semibold ${d === bestDay ? 'text-brand-700' : 'text-text-secondary'}`} dir="auto">{tx(DAYS[d], ar)}</span>
              {hours.map((n, hr) => (
                <span key={hr} data-testid={`store-heat-${d}-${hr}`} title={`${tx(DAYS[d], ar)} ${hourText(hr, ar)} · ${num(n)}`}
                  className="h-3.5 rounded-sm" style={{ background: n === 0 ? 'var(--surface-secondary)' : `color-mix(in srgb, var(--brand-600) ${Math.round(18 + (n / h.max) * 82)}%, transparent)` }} />
              ))}
            </div>
          ))}
        </div>
        <div className="mt-3 flex flex-wrap gap-2 text-xs">
          <span className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary"><CalendarClock size={12} aria-hidden />{ar ? 'أقوى يوم' : 'Best day'}: {tx(DAYS[bestDay], ar)}</span>
          <span className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 font-semibold text-text-secondary"><Clock size={12} aria-hidden />{ar ? 'أقوى ساعة' : 'Best hour'}: {hourText(bestHour, ar)}</span>
          <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-text-muted" dir="ltr">{h.timezone}</span>
        </div>
      </div>
    </ChartCard>
  )
}

/* ───────────────────────────── bars ───────────────────────────── */

function Basket({ b, ar, cur }: { b: StoreInsightsPayload['basket']; ar: boolean; cur: string }) {
  if (b.bands.length === 0) return null
  const max = Math.max(1, ...b.bands.map((x) => x.orders))

  return (
    <ChartCard title={ar ? 'حجم السلة' : 'Basket size'}
      action={b.median !== null && <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-secondary"><Num>{ar ? `الوسيط ${money(b.median, cur)} · ${cur}` : `Median ${money(b.median, cur)} · ${cur}`}</Num></span>}>
      <div data-testid="store-basket" className="flex h-36 items-end gap-2" dir="ltr">
        {b.bands.map((band) => (
          <div key={band.from} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
            <span className="tnum text-xs font-bold text-text-primary"><Num>{num(band.orders)}</Num></span>
            <div className="w-full rounded-t-lg bg-brand-600/80" style={{ height: `${Math.max(3, (band.orders / max) * 100)}%` }} />
            <span className="tnum truncate text-xs text-text-muted" dir="ltr"><Num>{band.to === null ? `${num(band.from)}+` : `${num(band.from)}–${num(band.to)}`}</Num></span>
          </div>
        ))}
      </div>
    </ChartCard>
  )
}

function Coupons({ c, ar, cur }: { c: StoreInsightsPayload['coupons']; ar: boolean; cur: string }) {
  if (c.rows.length === 0) return null
  const max = Math.max(1, ...c.rows.map((r) => r.orders))

  return (
    <ChartCard title={ar ? 'الكوبونات' : 'Coupons'}
      action={c.share !== null && <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-secondary"><Num>{ar ? `${percent(c.share, 0)} من الطلبات` : `${percent(c.share, 0)} of orders`}</Num></span>}>
      <ul data-testid="store-coupons" className="space-y-2.5">
        {c.rows.map((r) => (
          <li key={r.code}>
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="truncate font-mono text-xs font-bold text-text-primary" dir="ltr">{r.code}</span>
              <span className="flex shrink-0 items-center gap-1.5 text-xs text-text-secondary">
                <span className="tnum rounded-full bg-surface-secondary px-1.5"><Num>{num(r.orders)}</Num> {ar ? 'طلب' : 'orders'}</span>
                {r.net !== null && <span className="tnum font-bold text-text-primary"><Num>{money(r.net, cur)}</Num></span>}
                {r.discount ? <span className="tnum text-warning"><Num>{`−${money(r.discount, cur)}`}</Num></span> : null}
              </span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-warning" style={{ width: `${(r.orders / max) * 100}%` }} /></div>
          </li>
        ))}
      </ul>
    </ChartCard>
  )
}

function ProductGallery({ rows, ar, cur, compact = false }: { rows: ProductRow[]; ar: boolean; cur: string; compact?: boolean }) {
  if (rows.length === 0) return null
  const total = rows.reduce((a, r) => a + r.revenue, 0) || 1

  return (
    <ChartCard title={ar ? 'الأكثر مبيعًا' : 'Best sellers'}>
      <ol data-testid="store-gallery" className={`grid gap-2.5 ${compact ? 'grid-cols-3 sm:grid-cols-4 lg:grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5'}`}>
        {rows.slice(0, compact ? 6 : 10).map((r, i) => (
          <li key={`${r.name}-${i}`} data-testid={`store-gallery-${i}`} className="group relative flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-surface">
            <div className={`relative bg-surface-secondary ${compact ? 'aspect-[4/3]' : 'aspect-square'}`}>
              {localStill(r.image_url)
                ? <img src={localStill(r.image_url) ?? undefined} alt="" loading="lazy" className={`h-full w-full ${mediaFitClass(null, null, 'thumb')}`} />
                : <span className="grid h-full w-full place-items-center text-text-muted"><Package size={28} aria-hidden /></span>}
              <span className="absolute start-2 top-2 grid h-6 min-w-6 place-items-center rounded-full bg-brand-600 px-1.5 text-xs font-extrabold text-white"><Num>{num(i + 1)}</Num></span>
            </div>
            <div className="flex min-w-0 flex-col gap-1 p-2.5">
              <span className="truncate text-xs font-bold text-text-primary" dir="auto" title={r.name}>{r.name}</span>
              <span className="tnum text-sm font-extrabold text-text-primary"><Num>{money(r.revenue, cur)}</Num></span>
              <span className="tnum text-xs text-text-muted"><Num>{ar ? `${num(r.quantity)} قطعة · ${num(r.orders)} طلب` : `${num(r.quantity)} units · ${num(r.orders)} orders`}</Num></span>
              <div className="h-1.5 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-success" style={{ width: `${(r.revenue / total) * 100}%` }} /></div>
            </div>
          </li>
        ))}
      </ol>
    </ChartCard>
  )
}

/* ───────────────────────────── the board ───────────────────────────── */

export function StoreInsightsBoard({ d, ar }: { d: Depth; ar: boolean }) {
  const cur = d.currency ?? ''
  const m = (n: number | null | undefined) => (n == null ? '—' : money(n, cur))
  const { sales, orders, averages, conversion, products, customers, abandoned } = d
  const i = d.insights
  const trend = d.trend ?? []
  const ordersDelta = orders.previous_live ? (orders.live - orders.previous_live) / orders.previous_live : null
  const known = customers.new + customers.returning
  const comp = i?.composition

  return (
    <div data-testid="store-insights" className="flex min-w-0 flex-col gap-4">
      {/* Four large cards, each with its trend over the period. */}
      <StatGrid columns="grid-cols-2 lg:grid-cols-4" sparks>
        <HeroCard testId="store-hero-net" icon={<Banknote size={14} aria-hidden />} label={ar ? 'صافي المبيعات' : 'Net sales'} value={m(sales.net.current)} delta={<Delta pct={sales.net.change_pct} testId="store-delta-net" />} spark={trend.map((t) => t.net)} color="var(--success)" foot={ar ? `الإجمالي ${m(sales.gross.current)}` : `Gross ${m(sales.gross.current)}`} />
        <HeroCard testId="store-hero-orders" icon={<Receipt size={14} aria-hidden />} label={ar ? 'الطلبات' : 'Orders'} value={num(orders.live)} delta={<Delta pct={ordersDelta} testId="store-delta-orders" />} spark={trend.map((t) => t.orders)} foot={ar ? `${num(orders.confirmed)} مؤكد · ${num(orders.pending)} قيد التنفيذ` : `${num(orders.confirmed)} confirmed · ${num(orders.pending)} pending`} />
        <HeroCard testId="store-hero-aov" icon={<ShoppingCart size={14} aria-hidden />} label={ar ? 'متوسط قيمة الطلب' : 'Average order'} value={m(averages.aov)} color="var(--info)" spark={trend.filter((t) => t.orders > 0).map((t) => t.net / t.orders)} foot={averages.items_per_order === null ? undefined : (ar ? `${formatFixed(averages.items_per_order, 2)} منتج للطلب` : `${formatFixed(averages.items_per_order, 2)} items per order`)} />
        <HeroCard testId="store-hero-customers" icon={<Users size={14} aria-hidden />} label={ar ? 'العملاء' : 'Customers'} value={num(known)} foot={ar ? `${num(customers.new)} جديد · ${num(customers.returning)} عائد` : `${num(customers.new)} new · ${num(customers.returning)} returning`}
          delta={customers.repeat_rate !== null ? <span className="tnum rounded-full bg-surface-secondary px-1.5 text-xs font-bold text-text-secondary"><Num>{ar ? `تكرار ${percent(customers.repeat_rate, 0)}` : `Repeat ${percent(customers.repeat_rate, 0)}`}</Num></span> : undefined} />
      </StatGrid>

      {/* The small cards: everything else a merchant checks at a glance. */}
      <div data-testid="store-mini-cards" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <MiniCard testId="store-mini-discounts" icon={<Percent size={14} aria-hidden />} label={ar ? 'الخصومات' : 'Discounts'} value={m(sales.discounts.current)} sub={averages.discount_share === null ? undefined : (ar ? `${percent(averages.discount_share, 1)} من المبيعات` : `${percent(averages.discount_share, 1)} of sales`)} />
        <MiniCard testId="store-mini-shipping" icon={<Truck size={14} aria-hidden />} label={ar ? 'الشحن' : 'Shipping'} value={m(sales.shipping.current)} />
        <MiniCard testId="store-mini-tax" icon={<Receipt size={14} aria-hidden />} label={ar ? 'الضريبة' : 'Tax'} value={m(sales.tax.current)} />
        <MiniCard testId="store-mini-cancel" tone={averages.cancellation_rate > 0.1 ? 'warning' : 'neutral'} icon={<RotateCcw size={14} aria-hidden />} label={ar ? 'الإلغاء' : 'Cancellation'} value={percent(averages.cancellation_rate, 1)} sub={ar ? `${num(orders.cancelled)} طلب` : `${num(orders.cancelled)} orders`} />
        <MiniCard testId="store-mini-refund" tone={(averages.refund_rate ?? 0) > 0.05 ? 'warning' : 'neutral'} icon={<RotateCcw size={14} aria-hidden />} label={ar ? 'الاسترداد' : 'Refunds'} value={averages.refund_rate === null ? '—' : percent(averages.refund_rate, 1)} sub={m(sales.refunded.current)} />
        <MiniCard testId="store-mini-conversion" tone="success" icon={<ArrowUpRight size={14} aria-hidden />} label={ar ? 'من زيارة إلى شراء' : 'Visit to purchase'} value={conversion.value === null ? '—' : percent(conversion.value, 2)} sub={conversion.value === null ? (ar ? 'لا مصدر يقيس الزيارات' : 'Nothing measures visits') : (conversion.visits_source === 'ga4' ? (ar ? 'زيارات الموقع' : 'Website visits') : (ar ? 'زيارات المنصات' : 'Platform visits'))} />
        <MiniCard testId="store-mini-abandoned" tone="warning" icon={<ShoppingCart size={14} aria-hidden />} label={ar ? 'سلات متروكة' : 'Abandoned carts'} value={num(abandoned.carts)} sub={m(abandoned.value)} />
        <MiniCard testId="store-mini-coupons" icon={<Tag size={14} aria-hidden />} label={ar ? 'طلبات بكوبون' : 'Orders with coupon'} value={i?.coupons.share == null ? '—' : percent(i.coupons.share, 0)} sub={i ? (ar ? `${num(i.coupons.orders_with_coupon)} طلب` : `${num(i.coupons.orders_with_coupon)} orders`) : undefined} />
        <MiniCard testId="store-mini-ltv" icon={<Users size={14} aria-hidden />} label={ar ? 'قيمة العميل' : 'Customer value'} value={m(customers.lifetime_value)} />
        <MiniCard testId="store-mini-unsold" tone={products.unsold_active.count > 0 ? 'warning' : 'neutral'} icon={<Package size={14} aria-hidden />} label={ar ? 'منتجات بلا مبيعات' : 'Products not sold'} value={num(products.unsold_active.count)} />
      </div>

      {i && <Heatmap h={i.heatmap} ar={ar} />}

      <ProductGallery rows={products.best_by_revenue} ar={ar} cur={cur} />

      {/* The circles: how the period's orders are made up. */}
      <div data-testid="store-donuts" className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {i && <Donut testId="store-donut-status" title={ar ? 'حالات الطلبات' : 'Order states'} icon={<Receipt size={15} aria-hidden />} rows={i.status_mix.map((r) => ({ name: label(STATUS, r.key, ar), value: r.orders }))} center={num(orders.total)} centerLabel={ar ? 'طلب' : 'orders'} />}
        {i && <Donut testId="store-donut-payment" title={ar ? 'طرق الدفع' : 'Payment methods'} icon={<CreditCard size={15} aria-hidden />} rows={mixRows(i.payment_mix, PAYMENT, ar)} center={i.payment_mix.rows[0] ? label(PAYMENT, i.payment_mix.rows[0].key, ar) : undefined} centerLabel={ar ? 'الأكثر' : 'Top'} foot={unstatedFoot(i.payment_mix, ar)} />}
        {i && <Donut testId="store-donut-city" title={ar ? 'المدن' : 'Cities'} icon={<MapPin size={15} aria-hidden />} rows={mixRows(i.city_mix, null, ar)} center={i.city_mix.rows[0]?.key} centerLabel={ar ? 'الأكثر' : 'Top'} foot={unstatedFoot(i.city_mix, ar)} />}
        <Donut testId="store-donut-customers" title={ar ? 'جديد مقابل عائد' : 'New vs returning'} icon={<Users size={15} aria-hidden />} rows={[{ name: ar ? 'جديد' : 'New', value: customers.new }, { name: ar ? 'عائد' : 'Returning', value: customers.returning }]} center={known > 0 ? percent(customers.returning / known, 0) : undefined} centerLabel={ar ? 'عائدون' : 'returning'} />
        {comp && <Donut testId="store-donut-money" kind="money" cur={cur} title={ar ? 'مكوّنات المبالغ' : 'What the money is'} icon={<Banknote size={15} aria-hidden />}
          rows={[{ name: ar ? 'المنتجات' : 'Products', value: comp.products ?? 0 }, { name: ar ? 'الشحن' : 'Shipping', value: comp.shipping ?? 0 }, { name: ar ? 'الضريبة' : 'Tax', value: comp.tax ?? 0 }, { name: ar ? 'الخصومات' : 'Discounts', value: comp.discounts ?? 0 }, { name: ar ? 'المسترد' : 'Refunded', value: comp.refunded ?? 0 }]}
          center={m(sales.net.current)} centerLabel={ar ? 'الصافي' : 'Net'} />}
        {products.categories.length > 0 && <Donut testId="store-donut-categories" kind="money" cur={cur} title={ar ? 'التصنيفات' : 'Categories'} icon={<Package size={15} aria-hidden />} rows={products.categories.map((c) => ({ name: c.category, value: c.revenue }))} />}
      </div>

      {i && (i.basket.bands.length > 0 || i.coupons.rows.length > 0) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Basket b={i.basket} ar={ar} cur={cur} />
          <Coupons c={i.coupons} ar={ar} cur={cur} />
        </div>
      )}
    </div>
  )
}

/**
 * STORE-MIRROR-001 — what only a store can tell, after the website-shaped part: when people buy, the best sellers with their
 * pictures, and how the orders are made up. Smaller than before (Owner: «قلّل الأبعاد»): one row of four circles, a six-wide gallery.
 */
export function StoreExtras({ d, ar }: { d: Depth; ar: boolean }) {
  const cur = d.currency ?? ''
  const i = d.insights
  const known = d.customers.new + d.customers.returning

  return (
    <div data-testid="store-extras" className="flex min-w-0 flex-col gap-4">
      {i && <Heatmap h={i.heatmap} ar={ar} />}
      <ProductGallery rows={d.products.best_by_revenue} ar={ar} cur={cur} compact />
      <div data-testid="store-donuts" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {i && <Donut height={170} testId="store-donut-status" title={ar ? 'حالات الطلبات' : 'Order states'} icon={<Receipt size={14} aria-hidden />} rows={i.status_mix.map((r) => ({ name: label(STATUS, r.key, ar), value: r.orders }))} center={num(d.orders.total)} />}
        {i && <Donut height={170} testId="store-donut-city" title={ar ? 'المدن' : 'Cities'} icon={<MapPin size={14} aria-hidden />} rows={mixRows(i.city_mix, null, ar)} foot={unstatedFoot(i.city_mix, ar)} />}
        <Donut height={170} testId="store-donut-customers" title={ar ? 'جديد مقابل عائد' : 'New vs returning'} icon={<Users size={14} aria-hidden />} rows={[{ name: ar ? 'جديد' : 'New', value: d.customers.new }, { name: ar ? 'عائد' : 'Returning', value: d.customers.returning }]} center={known > 0 ? percent(d.customers.returning / known, 0) : undefined} />
        {d.products.categories.length > 0 && <Donut height={170} testId="store-donut-categories" kind="money" cur={cur} title={ar ? 'التصنيفات' : 'Categories'} icon={<Package size={14} aria-hidden />} rows={d.products.categories.map((c) => ({ name: c.category, value: c.revenue }))} />}
      </div>
      {i && (i.basket.bands.length > 0 || i.coupons.rows.length > 0) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Basket b={i.basket} ar={ar} cur={cur} />
          <Coupons c={i.coupons} ar={ar} cur={cur} />
        </div>
      )}
      <StoreDeeper d={d} ar={ar} />
    </div>
  )
}

/**
 * STORE-ANALYTICS-PLUS-001 (Owner 2026-11-26: «اكثر تحليلات ممكنة من المتجر») — what the store's own orders can still tell after
 * the design's sections: how visits turn into orders, what a customer is worth and how often they come back, how full a basket
 * is, whether a coupon moves it, what the money is made of, which products are asked for most, which lag and which never sold.
 * Each card is drawn only when its figure exists («اعرض المتاح فقط»); with none of them the section is not drawn.
 */
function StoreDeeper({ d, ar }: { d: Depth; ar: boolean }) {
  const cur = d.currency ?? ''
  const m = (n: number | null | undefined) => (n == null ? null : money(n, cur))
  const i = d.insights
  const comp = i?.composition
  const { conversion, customers, averages, products } = d

  const cards: Array<{ key: string; icon: ReactNode; label: string; value: string | null; sub?: string; tone?: 'neutral' | 'warning' | 'success' }> = [
    { key: 'conversion', icon: <ArrowUpRight size={14} aria-hidden />, tone: 'success', label: ar ? 'من زيارة إلى شراء' : 'Visit to purchase',
      value: conversion.value === null ? null : percent(conversion.value, 2),
      sub: conversion.visits_source === 'ga4' ? (ar ? 'من زيارات الموقع' : 'of website visits') : (ar ? 'من زيارات المنصات' : 'of platform visits') },
    { key: 'ltv', icon: <Users size={14} aria-hidden />, label: ar ? 'قيمة العميل' : 'Customer value', value: m(customers.lifetime_value) },
    { key: 'repeat', icon: <RotateCcw size={14} aria-hidden />, label: ar ? 'نسبة العملاء العائدين' : 'Repeat customers', value: customers.repeat_rate === null ? null : percent(customers.repeat_rate, 0) },
    { key: 'items', icon: <ShoppingCart size={14} aria-hidden />, label: ar ? 'منتجات في الطلب' : 'Items per order', value: averages.items_per_order === null ? null : formatFixed(averages.items_per_order, 2) },
    { key: 'discount-share', icon: <Percent size={14} aria-hidden />, label: ar ? 'الخصم من المبيعات' : 'Discount of sales', value: averages.discount_share === null ? null : percent(averages.discount_share, 1) },
    { key: 'aov-coupon', icon: <Tag size={14} aria-hidden />, label: ar ? 'متوسط الطلب بكوبون' : 'Average order, coupon', value: m(i?.coupons.aov_with),
      sub: i?.coupons.aov_without == null ? undefined : (ar ? `بدون كوبون ${money(i.coupons.aov_without, cur)}` : `without ${money(i.coupons.aov_without, cur)}`) },
    { key: 'unsold', icon: <Package size={14} aria-hidden />, tone: products.unsold_active.count > 0 ? 'warning' : 'neutral', label: ar ? 'منتجات بلا مبيعات' : 'Products not sold',
      value: products.unsold_active.count > 0 ? num(products.unsold_active.count) : null },
  ]
  const shown = cards.filter((c) => c.value !== null)
  const money5 = comp ? [
    { name: ar ? 'المنتجات' : 'Products', value: comp.products ?? 0 }, { name: ar ? 'الشحن' : 'Shipping', value: comp.shipping ?? 0 },
    { name: ar ? 'الضريبة' : 'Tax', value: comp.tax ?? 0 }, { name: ar ? 'الخصومات' : 'Discounts', value: comp.discounts ?? 0 },
    { name: ar ? 'المسترد' : 'Refunded', value: comp.refunded ?? 0 },
  ] : []
  const hasMoney = money5.some((r) => r.value > 0)
  const asked = products.best_by_orders.slice(0, 5)
  const weak = products.weak.slice(0, 5)
  const unsold = products.unsold_active.sample.slice(0, 5)
  const hasLists = asked.length > 0 || weak.length > 0 || unsold.length > 0
  if (shown.length === 0 && !hasMoney && !hasLists) return null

  return (
    <section data-testid="store-deeper" className="flex min-w-0 flex-col gap-3">
      <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'تحليلات أعمق' : 'Deeper analytics'}</h3>
      {shown.length > 0 && (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
          {shown.map((c) => <MiniCard key={c.key} testId={`store-deeper-${c.key}`} icon={c.icon} label={c.label} value={c.value ?? '—'} sub={c.sub} tone={c.tone} />)}
        </div>
      )}
      {(hasMoney || hasLists) && (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
          {hasMoney && <Donut height={170} testId="store-deeper-money" kind="money" cur={cur} title={ar ? 'مكوّنات المبالغ' : 'What the money is'} icon={<Banknote size={14} aria-hidden />} rows={money5} center={m(d.sales.net.current) ?? undefined} centerLabel={ar ? 'الصافي' : 'Net'} />}
          <RankList testId="store-deeper-asked" title={ar ? 'الأكثر طلبًا' : 'Most ordered'} rows={asked.map((r) => ({ name: r.name, value: ar ? `${num(r.orders)} طلب` : `${num(r.orders)} orders` }))} />
          <RankList testId="store-deeper-weak" title={ar ? 'الأضعف مبيعًا' : 'Weakest sellers'} rows={weak.map((r) => ({ name: r.name, value: money(r.revenue, cur) }))} />
          <RankList testId="store-deeper-unsold-list" title={ar ? 'لم تُبع في الفترة' : 'Not sold this period'} rows={unsold.map((n) => ({ name: n, value: '' }))} />
        </div>
      )}
    </section>
  )
}

function RankList({ testId, title, rows }: { testId: string; title: string; rows: Array<{ name: string; value: string }> }) {
  if (rows.length === 0) return null

  return (
    <ChartCard title={title}>
      <ol data-testid={testId} className="flex flex-col gap-1.5">
        {rows.map((r, idx) => (
          <li key={`${r.name}-${idx}`} className="flex min-w-0 items-center gap-2 text-xs">
            <span className="tnum grid h-5 min-w-5 place-items-center rounded-full bg-surface-secondary px-1 font-bold text-text-secondary"><Num>{num(idx + 1)}</Num></span>
            <span className="min-w-0 flex-1 truncate font-semibold text-text-primary" dir="auto" title={r.name}>{r.name}</span>
            {r.value && <span className="tnum shrink-0 text-text-muted"><Num>{r.value}</Num></span>}
          </li>
        ))}
      </ol>
    </ChartCard>
  )
}
