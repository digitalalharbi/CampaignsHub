import { Num } from '@/components/ui/Num'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Skeleton } from '@/components/ui/States'
import { useSwrQuery } from '@/lib/query/swr'
import { ChartCard, MetricLineChart, RankingBarChart } from './charts'
import { money, num, percent } from './format'
import type { ProductRow, StoreDepthPayload } from './storeAnalyticsApi'
import { StoreInsightsBoard } from './StoreInsightsBoard'
import { StoreLatestJump } from './StoreLatestJump'

/**
 * STORE-ANALYTICS-DEPTH-001 — the store's own reading of the period: cards first, then charts.
 *
 * The store is the authority on orders here. A figure no order states reads «—» and says so, a comparison
 * with nothing before it says that too, and nothing here is added to GA4 or a platform.
 */
const LTV_REASON: Record<string, { ar: string; en: string }> = {
  too_few_customers: { ar: 'عدد العملاء في السجل لا يكفي لرقم موثوق.', en: 'Too few customers in the ledger for a reliable figure.' },
  ledger_too_short: { ar: 'سجل المتجر هنا أقصر من أن يعطي رقمًا موثوقًا.', en: 'The ledger here is too short for a reliable figure.' },
  orders_without_customer: { ar: 'كثير من الطلبات بلا عميل معروف.', en: 'Too many orders have no known customer.' },
  shop_connected_twice: { ar: 'المتجر مربوط مرتين، فلا يُحسب إلى أن يُصحَّح الربط.', en: 'The shop is connected twice, so this waits until that is fixed.' },
}

function ProductList({ title, rows, ar, cur, testid, metric }: { title: string; rows: ProductRow[]; ar: boolean; cur: string; testid: string; metric: 'revenue' | 'orders' }) {
  if (rows.length === 0) return null
  return (
    <ChartCard title={title}>
      <div data-testid={testid} className="min-w-0">
        <RankingBarChart
          horizontal
          height={Math.max(160, rows.length * 34)}
          data={rows.map((r) => ({ label: r.name, value: metric === 'revenue' ? r.revenue : r.orders }))}
          bars={[{ key: 'value', name: metric === 'revenue' ? (ar ? 'الإيراد' : 'Revenue') : (ar ? 'الطلبات' : 'Orders'), kind: metric === 'revenue' ? 'money' : 'num' }]}
          currency={metric === 'revenue' ? cur : undefined}
        />
      </div>
    </ChartCard>
  )
}

export function StoreDepthPanel({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const q = useSwrQuery({
    name: 'store-depth',
    scope: [projectId],
    window: [range.from, range.to],
    queryFn: () => getData<StoreDepthPayload>(`/projects/${projectId}/commerce/depth?from=${range.from}&to=${range.to}`),
  })

  if (q.isLoading) return <Skeleton className="h-40 w-full" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="store-depth-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة تحليلات المتجر' : 'Store analytics could not be read'} />
  }

  const d = q.data
  if (d.state === 'empty') return <StoreLatestJump latest={d.latest_order} ar={ar} testId="store-depth-empty" />
  if (d.state !== 'ready' || !d.sales || !d.orders || !d.averages || !d.conversion || !d.products || !d.customers || !d.abandoned) {
    return (
      <p data-testid={`store-depth-${d.state}`} className="rounded-xl bg-surface-hover px-4 py-3 text-sm text-text-secondary">
        {d.state === 'no_store'
          ? (ar ? 'لا يوجد متجر مربوط بهذا المشروع، فلا تحليلات مبيعات.' : 'No store is connected to this project, so there are no sales analytics.')
          : (ar ? 'لا طلبات في هذه الفترة.' : 'No orders in this period.')}
      </p>
    )
  }

  const cur = d.currency ?? ''
  const m = (n: number | null | undefined) => (n == null ? '—' : money(n, cur))
  const { sales, orders, averages, conversion, products, customers, abandoned } = d

  return (
    <section data-testid="store-depth" className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-xs text-text-secondary">
        <Badge tone="success">{ar ? d.basis?.label_ar : d.basis?.label_en}</Badge>
        <span>{ar ? 'مرجع الطلبات والمبيعات. لا يُجمع مع الموقع أو المنصات.' : 'The authority on orders and sales. Never added to Website or the platforms.'}</span>
        {d.basis && d.basis.kind !== 'live' && (
          <Badge tone="warning">
            <span data-testid="store-depth-demo">
              {ar ? `يتضمن ${num(d.basis.demo_orders)} طلبًا تجريبيًا — لا يصلح دليلًا لتقرير إنتاج` : `Includes ${num(d.basis.demo_orders)} demo order(s): not evidence for a production report`}
            </span>
          </Badge>
        )}
      </div>

      {/* STORE-INSIGHTS-001 (Owner 2026-11-25) — large cards, small cards, circles, the week's heat map, best sellers with pictures. */}
      <StoreInsightsBoard d={{ ...d, sales, orders, averages, conversion, products, customers, abandoned }} ar={ar} />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <ChartCard title={ar ? 'الطلبات وصافي المبيعات يوميًا' : 'Orders and net sales by day'} subtitle={ar ? 'يوم بلا طلب لا يُرسم صفرًا.' : 'A day with no order is not drawn as zero.'}>
          <div data-testid="store-trend" className="min-w-0">
            <MetricLineChart data={d.trend ?? []} height={220} series={[{ key: 'net', name: ar ? 'صافي المبيعات' : 'Net sales', kind: 'num' }, { key: 'orders', name: ar ? 'الطلبات' : 'Orders', kind: 'num' }]} rightAxisFor="orders" />
          </div>
        </ChartCard>
        <ProductList title={ar ? 'الأفضل بعدد الطلبات' : 'Best by orders'} rows={products.best_by_orders} ar={ar} cur={cur} testid="store-best-orders" metric="orders" />
        <ProductList title={ar ? 'المنتجات الأضعف' : 'Weakest products'} rows={products.weak} ar={ar} cur={cur} testid="store-weak" metric="revenue" />
        <ChartCard title={ar ? 'العملاء' : 'Customers'}>
          <dl data-testid="store-customers" className="grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-xs text-text-muted">{ar ? 'جدد' : 'New'}</dt><dd className="font-bold"><Num className="tnum">{num(customers.new)}</Num></dd></div>
            <div><dt className="text-xs text-text-muted">{ar ? 'عائدون' : 'Returning'}</dt><dd className="font-bold"><Num className="tnum">{num(customers.returning)}</Num></dd></div>
            <div><dt className="text-xs text-text-muted">{ar ? 'تكرار الشراء' : 'Repeat purchase'}</dt><dd className="font-bold"><Num className="tnum">{customers.repeat_rate === null ? '—' : percent(customers.repeat_rate, 1)}</Num></dd></div>
            <div><dt className="text-xs text-text-muted">{ar ? 'قيمة العميل' : 'Customer value'}</dt><dd className="font-bold"><Num className="tnum">{m(customers.lifetime_value)}</Num></dd></div>
          </dl>
          <p data-testid="store-ltv-note" className="mt-2 text-[11px] text-text-muted">
            {customers.lifetime_value_reason ? LTV_REASON[customers.lifetime_value_reason]?.[ar ? 'ar' : 'en'] : customers.lifetime_value_basis[ar ? 'ar' : 'en']}
            {customers.without_customer > 0 && ` ${ar ? `${num(customers.without_customer)} طلبًا بلا عميل معروف.` : `${num(customers.without_customer)} order(s) have no known customer.`}`}
          </p>
        </ChartCard>
      </div>

      <div className="grid grid-cols-1 gap-3 text-xs text-text-secondary sm:grid-cols-2">
        <p data-testid="store-abandoned" className="rounded-xl bg-surface-hover px-4 py-3">
          {ar ? `السلات المتروكة: ${num(abandoned.carts)} بقيمة ${m(abandoned.value)}. ` : `Abandoned carts: ${num(abandoned.carts)} worth ${m(abandoned.value)}. `}
          {abandoned.by_product_reason[ar ? 'ar' : 'en']}
        </p>
        <p data-testid="store-unsold" className="rounded-xl bg-surface-hover px-4 py-3">
          {ar ? `منتجات نشطة بلا مبيعات في الفترة: ${num(products.unsold_active.count)}` : `Active products with no sale in the period: ${num(products.unsold_active.count)}`}
          {products.unsold_active.sample.length > 0 && <span className="block truncate" dir="auto" title={products.unsold_active.sample.join('، ')}>{products.unsold_active.sample.join('، ')}</span>}
        </p>
      </div>
      {(d.coverage?.orders_with_money_withheld ?? 0) > 0 && (
        <p data-testid="store-withheld" className="text-xs text-warning">
          {ar
            ? `${num(d.coverage?.orders_with_money_withheld)} طلبًا بعملة (${d.coverage?.money_withheld_currencies.join('، ')}) لا يتوفر لها سعر صرف موثوق، فلم تدخل مبالغها في المجاميع.`
            : `${num(d.coverage?.orders_with_money_withheld)} order(s) in ${d.coverage?.money_withheld_currencies.join(', ')} have no trustworthy rate, so their amounts are not in these totals.`}
        </p>
      )}
    </section>
  )
}
