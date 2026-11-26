import { Banknote, CreditCard, Receipt, Route, ShoppingBag, Store } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { ProviderMark } from '@/components/ProviderMark'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { KpiRowSkeleton } from '@/components/ui/Skeletons'
import { getData } from '@/lib/api/client'
import { useSwrQuery } from '@/lib/query/swr'
import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import { AcquisitionVisual, CompactTiles, type AcquisitionLabels, type TileLayout } from '@/features/reports/WebsiteVisuals'
import type { StoreFunnelStep, StoreKpi, StoreSlice } from './ga4/storeTypes'
import { ChartCard, MetricLineChart } from './charts'
import { money, num, ratio } from './format'
import { PAYMENT, StoreExtras } from './StoreInsightsBoard'
import { StoreLatestJump } from './StoreLatestJump'
import type { StoreDepthPayload, ThreeSourcesPayload } from './storeAnalyticsApi'

/**
 * STORE-MIRROR-001 (Owner 2026-11-25) — «اجعلها بنفس فكرة تحليلات الموقع … المصدر GA4، أحتاج المصدر المتجر. جزء المتجر يوازي جزء
 * الموقع». The store tab is the website tab with the store as its source: the same compact tiles (four with their trend, then
 * two grouped strips), the same orders-and-sales chart, the same channels / second-split / funnel panel, and «المنصة مقابل
 * المتجر» in place of «المنصة مقابل الموقع». Every figure is the store's own (STORE-ANALYTICS-DEPTH-001); nothing is added to a
 * platform's. The store-only readings (when people buy, best sellers, states, cities) follow, smaller.
 */
type L = { ar: string; en: string }

const LABELS: Record<string, L> = {
  net_sales: { ar: 'صافي المبيعات', en: 'Net sales' }, orders: { ar: 'الطلبات', en: 'Orders' }, aov: { ar: 'متوسط الطلب', en: 'Average order' },
  customers: { ar: 'العملاء', en: 'Customers' }, gross: { ar: 'الإجمالي', en: 'Gross' }, discounts: { ar: 'الخصومات', en: 'Discounts' },
  shipping: { ar: 'الشحن', en: 'Shipping' }, tax: { ar: 'الضريبة', en: 'Tax' }, cancel_rate: { ar: 'الإلغاء', en: 'Cancelled' },
  refund_rate: { ar: 'الاسترداد', en: 'Refunded' }, abandoned: { ar: 'سلات متروكة', en: 'Abandoned carts' }, coupon_share: { ar: 'بكوبون', en: 'With coupon' },
}

const LAYOUT: TileLayout = {
  primary: ['net_sales', 'orders', 'aov', 'customers'],
  groups: [
    { key: 'sales', icon: Banknote, color: 'var(--success)', label: { ar: 'المبالغ', en: 'Money' }, keys: ['gross', 'discounts', 'shipping', 'tax'] },
    { key: 'operations', icon: Receipt, color: 'var(--warning)', label: { ar: 'العمليات', en: 'Operations' }, keys: ['cancel_rate', 'refund_rate', 'abandoned', 'coupon_share'] },
  ],
  labels: LABELS,
  lowerIsBetter: ['cancel_rate', 'refund_rate', 'abandoned'],
  testId: 'store-tiles',
  ariaLabel: { ar: 'مؤشرات المتجر', en: 'Store KPIs' },
}

const FUNNEL: Record<string, L> = {
  carts: { ar: 'السلات', en: 'Carts' }, orders: { ar: 'الطلبات', en: 'Orders' }, confirmed: { ar: 'المؤكدة', en: 'Confirmed' }, kept: { ar: 'بلا استرداد', en: 'Not refunded' },
}

type Depth = StoreDepthPayload & Required<Pick<StoreDepthPayload, 'sales' | 'orders' | 'averages' | 'conversion' | 'products' | 'customers' | 'abandoned'>>

/** The store's figures in the website tiles' own shape. */
export function storeKpis(d: Depth): StoreKpi[] {
  const cur = d.currency ?? null
  const trend = d.trend ?? []
  const k = (key: string, kind: StoreKpi['kind'], current: number | null | undefined, extra: Partial<StoreKpi> = {}): StoreKpi =>
    ({ key, kind, current: current ?? null, previous: null, change_pct: null, currency: kind === 'money' ? cur : null, ...extra })
  const ordersChange = d.orders.previous_live ? (d.orders.live - d.orders.previous_live) / d.orders.previous_live : null
  const i = d.insights

  return [
    k('net_sales', 'money', d.sales.net.current, { previous: d.sales.net.previous, change_pct: d.sales.net.change_pct, trend: trend.map((t) => ({ date: t.date, value: t.net })) }),
    k('orders', 'count', d.orders.live, { previous: d.orders.previous_live, change_pct: ordersChange, trend: trend.map((t) => ({ date: t.date, value: t.orders })) }),
    k('aov', 'money', d.averages.aov, { trend: trend.filter((t) => t.orders > 0).map((t) => ({ date: t.date, value: t.net / t.orders })) }),
    k('customers', 'count', d.customers.new + d.customers.returning),
    k('gross', 'money', d.sales.gross.current, { change_pct: d.sales.gross.change_pct }),
    k('discounts', 'money', d.sales.discounts.current),
    k('shipping', 'money', d.sales.shipping.current),
    k('tax', 'money', d.sales.tax.current),
    k('cancel_rate', 'rate', d.averages.cancellation_rate),
    k('refund_rate', 'rate', d.averages.refund_rate),
    k('abandoned', 'count', d.abandoned.carts),
    k('coupon_share', 'rate', i?.coupons.share ?? null),
  ].filter((x) => x.current !== null)
}

/** The store funnel: carts started (orders + abandoned) → orders → confirmed → not refunded. */
export function storeFunnel(d: Depth): StoreFunnelStep[] {
  const carts = d.orders.live + d.abandoned.carts
  const kept = d.orders.live - d.orders.refunded
  const steps: Array<[string, number]> = [['carts', carts], ['orders', d.orders.live], ['confirmed', d.orders.confirmed], ['kept', kept]]

  return steps.map(([stage, count], idx) => {
    const before = idx === 0 ? null : steps[idx - 1][1]
    return { stage, count, step_rate: before ? count / before : null, exceeds_previous: before !== null && count > before }
  })
}

const slice = (key: string, n: number): StoreSlice => ({ key, sessions: n, purchases: null, revenue: null, revenue_usd: null, share: null })

export function StoreMirror({ projectId, range, ar, spend }: { projectId: string; range: { from: string; to: string }; ar: boolean; spend: number | null }) {
  const depth = useSwrQuery({
    name: 'store-depth', scope: [projectId], window: [range.from, range.to],
    queryFn: () => getData<StoreDepthPayload>(`/projects/${projectId}/commerce/depth?from=${range.from}&to=${range.to}`),
  })
  const compare = useSwrQuery({
    name: 'three-sources', scope: [projectId], window: [range.from, range.to],
    queryFn: () => getData<ThreeSourcesPayload>(`/projects/${projectId}/measurement/compare?from=${range.from}&to=${range.to}`),
  })

  if (depth.isLoading) return <KpiRowSkeleton idPrefix="store-tile" columns="grid-cols-2 lg:grid-cols-4" labels={[LABELS.net_sales, LABELS.orders, LABELS.aov, LABELS.customers].map((l) => (ar ? l.ar : l.en))} />
  if (depth.isError || !depth.data) {
    return <QueryFailure error={depth.error} ar={ar} testId="store-mirror-failure" onRetry={() => void depth.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة المتجر' : 'The store could not be read'} />
  }
  const d = depth.data
  if (d.state === 'empty') return <StoreLatestJump latest={d.latest_order} ar={ar} testId="store-mirror-empty" />
  if (d.state !== 'ready' || !d.sales || !d.orders || !d.averages || !d.conversion || !d.products || !d.customers || !d.abandoned) {
    return <Badge tone="info" data-testid={`store-mirror-${d.state}`}>{ar ? 'لا طلبات في هذه الفترة' : 'No orders in this period'}</Badge>
  }
  const full = d as Depth
  const cur = d.currency ?? ''
  const i = d.insights

  // The store's «channels»: where its orders came from, by evidence in the order; the store's «devices»: how people paid.
  const rows = compare.data?.rows ?? []
  const sources = [
    ...rows.filter((r) => (r.store_confirmed?.orders ?? 0) > 0).map((r) => slice(canonicalPlatform(r.platform), r.store_confirmed?.orders ?? 0)),
    ...(compare.data?.store_unattributed && compare.data.store_unattributed.orders > 0 ? [slice('unattributed', compare.data.store_unattributed.orders)] : []),
  ]
  const sourceLabels: Record<string, L> = Object.fromEntries(sources.map((s) => [s.key, s.key === 'unattributed' ? { ar: 'بدون مصدر', en: 'No source' } : { ar: providerLabel(s.key, 'ar'), en: providerLabel(s.key, 'en') }]))
  const payments = i ? [...i.payment_mix.rows.map((r) => slice(r.key, r.orders)), ...(i.payment_mix.other ? [slice('other', i.payment_mix.other.orders)] : [])] : []
  const acquisition: AcquisitionLabels = {
    channels: { title: { ar: 'مصادر الطلبات', en: 'Order sources' }, icon: Route, labels: sourceLabels },
    devices: { title: { ar: 'طرق الدفع', en: 'Payment methods' }, icon: CreditCard, labels: { ...PAYMENT, other: { ar: 'أخرى', en: 'Other' } } },
    funnel: { title: { ar: 'قمع المتجر', en: 'Store funnel' }, labels: FUNNEL },
    unit: ShoppingBag,
  }

  return (
    <div data-testid="store-mirror" className="flex min-w-0 flex-col gap-4">
      <CompactTiles kpis={storeKpis(full)} ar={ar} layout={LAYOUT} />

      {(d.trend?.length ?? 0) > 0 && (
        <ChartCard title={ar ? 'الطلبات والمبيعات' : 'Orders & sales'} action={<Badge tone="neutral">{cur || '—'}</Badge>}>
          <div data-testid="store-series" style={{ height: 240 }}>
            <MetricLineChart data={d.trend ?? []} height={240} currency={cur} rightAxisFor="net"
              series={[{ key: 'orders', name: ar ? 'الطلبات' : 'Orders', kind: 'num' }, { key: 'net', name: ar ? 'صافي المبيعات' : 'Net sales', kind: 'money', color: 'var(--success)' }]} />
          </div>
        </ChartCard>
      )}

      <AcquisitionVisual channels={sources} devices={payments} funnel={storeFunnel(full)} ar={ar} labels={acquisition} />

      <PlatformVsStore data={compare.data} ar={ar} orders={d.orders.live} net={d.sales.net.current} spend={spend} cur={cur} />

      <StoreExtras d={full} ar={ar} />
    </div>
  )
}

/**
 * «المنصة مقابل المتجر» — the website tab's «المنصة مقابل الموقع» with the store as the measured side: per platform, what it claimed
 * beside the orders the store tied to it by evidence, then the real cost per order and return on the store's own orders and sales.
 */
function PlatformVsStore({ data, ar, orders, net, spend, cur }: { data: ThreeSourcesPayload | undefined; ar: boolean; orders: number; net: number | null; spend: number | null; cur: string }) {
  const rows = (data?.rows ?? []).filter((r) => (r.platform_reported?.orders ?? 0) > 0 || (r.store_confirmed?.orders ?? 0) > 0)
  if (rows.length === 0) return null
  const max = Math.max(1, ...rows.flatMap((r) => [r.platform_reported?.orders ?? 0, r.store_confirmed?.orders ?? 0]))
  const w = (v: number | null | undefined) => `${v ? Math.max(2, (v / max) * 100) : 0}%`
  const claimed = rows.reduce((a, r) => a + (r.platform_reported?.orders ?? 0), 0)

  return (
    <section data-testid="store-vs-platforms" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="inline-flex items-center gap-1.5 text-sm font-extrabold text-text-primary"><Store size={15} aria-hidden />{ar ? 'المنصة مقابل المتجر' : 'Platform vs store'}</h3>
        <span className="flex items-center gap-3 text-xs font-semibold text-text-muted">
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--brand-600)' }} />{ar ? 'المنصة' : 'Platform'}</span>
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--success)' }} />{ar ? 'المتجر' : 'Store'}</span>
        </span>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Chip testId="store-vs-claimed" label={ar ? 'ادّعت المنصات' : 'Platforms claimed'} value={num(claimed)} />
        <Chip testId="store-vs-orders" label={ar ? 'طلبات متجرك' : 'Your store orders'} value={num(orders)} strong />
        <Chip testId="store-vs-cpa" label={ar ? 'تكلفة الطلب' : 'Cost per order'} value={spend !== null && orders > 0 ? money(spend / orders, cur) : '—'} />
        <Chip testId="store-vs-roas" label={ar ? 'معدل العائد' : 'Return'} value={spend && net !== null ? ratio(net / spend) : '—'} />
      </div>

      <ul data-testid="store-vs-bars" className="flex flex-col gap-3">
        {rows.map((r) => {
          const p = r.platform_reported?.orders ?? null
          const s = r.store_confirmed?.orders ?? null
          const key = canonicalPlatform(r.platform)

          return (
            <li key={r.platform} data-testid={`store-vs-${key}`} className="grid grid-cols-[7.5rem_1fr_auto] items-center gap-3">
              <span className="inline-flex min-w-0 items-center gap-2 text-xs font-bold text-text-primary"><ProviderMark provider={key} size={18} /><span className="truncate">{providerLabel(key, ar ? 'ar' : 'en')}</span></span>
              <div className="flex min-w-0 flex-col gap-1">
                <div className="flex items-center gap-2"><div className="h-3 rounded-full" style={{ width: w(p), background: 'var(--brand-600)' }} /><span className="tnum text-xs font-bold text-text-primary"><Num>{p === null ? '—' : num(p)}</Num></span></div>
                <div className="flex items-center gap-2"><div className="h-3 rounded-full" style={{ width: w(s), background: 'var(--success)' }} /><span className="tnum text-xs font-bold text-text-primary"><Num>{s === null ? '—' : num(s)}</Num></span></div>
              </div>
              <span className="tnum text-xs text-text-muted"><Num>{p && s !== null ? `×${(p / Math.max(1, s)).toFixed(1)}` : ''}</Num></span>
            </li>
          )
        })}
      </ul>
      {data?.store_unattributed && data.store_unattributed.orders > 0 && (
        <span data-testid="store-vs-unattributed" className="self-start rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-secondary">
          <Num>{ar ? `${num(data.store_unattributed.orders)} طلب بلا مصدر مُثبت` : `${num(data.store_unattributed.orders)} orders with no proven source`}</Num>
        </span>
      )}
    </section>
  )
}

function Chip({ testId, label, value, strong = false }: { testId: string; label: string; value: string; strong?: boolean }) {
  return (
    <div data-testid={testId} className={`flex min-w-0 flex-col gap-0.5 rounded-xl px-3 py-2 ${strong ? 'bg-success/10' : 'bg-surface-secondary/60'}`}>
      <span className="truncate text-xs font-semibold text-text-muted">{label}</span>
      <span className={`tnum truncate text-sm font-extrabold ${strong ? 'text-success' : 'text-text-primary'}`}><Num>{value}</Num></span>
    </div>
  )
}

