import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { money } from '@/features/analytics/format'
import { ChartCard, PlatformDonutChart, RankingBarChart } from '@/features/analytics/charts'
import { useUi } from '@/stores/ui'
import { DataState } from './DataState'

/** The part of the store funnel payload this widget reads. The funnel itself is not redrawn: it has its own tab. */
export interface StoreOutcomes {
  totals: {
    reporting_currency: string
    revenue: number
    refunded: number
    cancelled_orders: number
    orders: number
    attributed_orders: number
    unattributed_orders: number
  }
  derived: { aov: number | null }
  comparisons: { products: Array<{ name: string; quantity: number; revenue: number }> }
  coverage: { stores: number; orders_with_money_withheld: number; orders_in_window: number }
}

/**
 * WIDGET-REGISTRY-001 — what the connected store confirmed: orders, net revenue and average order value, how many of
 * those orders the platforms can be tied to, and the products that earned most.
 *
 * Read from the store ledger through the SAME endpoint as the Store tab, so the two cannot disagree. Orders the store
 * confirmed but no platform can claim are drawn as their own slice, never folded into a channel. Orders whose money
 * could not be converted are counted and said, because a short total looks exactly like a complete one. With no store
 * connected it says so instead of drawing zeros.
 */
export function CommerceOutcomesWidget({ projectId, range }: { projectId: string; range: { from: string; to: string } }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const title = ar ? 'نتائج المتجر' : 'Store outcomes'
  const q = useQuery({
    queryKey: ['store-funnel', projectId, range.from, range.to],
    queryFn: () => getData<StoreOutcomes>(`/projects/${projectId}/commerce/funnel?from=${range.from}&to=${range.to}`),
    retry: false,
  })

  if (q.isLoading) return <ChartCard title={title}><Skeleton className="h-48" /></ChartCard>
  if (q.isError || !q.data) return <ChartCard title={title}><EmptyState title={ar ? 'تعذّر تحميل نتائج المتجر' : 'Store outcomes could not be loaded'} /></ChartCard>

  const { totals: t, derived, comparisons, coverage } = q.data

  if (coverage.stores === 0) {
    return (
      <ChartCard title={title} action={<DataState kind="missing" testId="commerce-outcomes-state" />}>
        <EmptyState
          title={ar ? 'لا يوجد متجر مربوط بهذا المشروع' : 'No store is connected to this project'}
          description={ar ? 'اربط سلة أو زد من صفحة التكاملات لتظهر الطلبات والإيرادات.' : 'Connect Salla or Zid from the integrations page to see orders and revenue.'}
        />
      </ChartCard>
    )
  }

  const cur = t.reporting_currency
  const slices = [
    { key: 'attributed', name: ar ? 'طلبات منسوبة لمنصة' : 'Tied to a platform', value: t.attributed_orders },
    { key: 'unattributed', name: ar ? 'غير منسوبة' : 'Unattributed', value: t.unattributed_orders },
  ].filter((d) => d.value > 0)

  return (
    <ChartCard
      title={title}
      subtitle={ar ? `ما أكّده المتجر، بعملة التقرير ${cur}` : `What the store confirmed, in the reporting currency ${cur}`}
      action={<DataState kind={coverage.orders_in_window === 0 ? 'missing' : 'measured'} testId="commerce-outcomes-state" />}
    >
      <div className="space-y-4" data-testid="commerce-outcomes">
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{ar ? 'الطلبات' : 'Orders'}</dt>
            <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="commerce-orders"><Num>{String(t.orders)}</Num></dd>
          </div>
          <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{ar ? 'صافي الإيراد' : 'Net revenue'}</dt>
            <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="commerce-revenue"><Num>{money(t.revenue, cur)}</Num></dd>
          </div>
          <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{ar ? 'متوسط الطلب' : 'Average order'}</dt>
            <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="commerce-aov"><Num>{derived.aov === null ? '—' : money(derived.aov, cur)}</Num></dd>
          </div>
          <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{ar ? 'ملغاة' : 'Cancelled'}</dt>
            <dd className="mt-0.5 text-lg font-bold text-text-primary"><Num>{String(t.cancelled_orders)}</Num></dd>
          </div>
        </dl>

        <div className="grid gap-4 lg:grid-cols-[1fr_2fr]">
          <div data-testid="commerce-attribution-split">
            <PlatformDonutChart height={200} colorBy="series" centerValue={String(t.orders)} centerLabel={ar ? 'طلب' : 'orders'} data={slices} />
          </div>
          <div data-testid="commerce-products">
            {comparisons.products.length === 0
              ? <EmptyState title={ar ? 'لا منتجات في هذه الفترة' : 'No products in this period'} />
              : (
                <RankingBarChart
                  height={200}
                  horizontal
                  currency={cur}
                  data={comparisons.products.slice(0, 5).map((p) => ({ label: p.name, revenue: p.revenue }))}
                  bars={[{ key: 'revenue', name: ar ? 'الإيراد' : 'Revenue', kind: 'money' }]}
                />
              )}
          </div>
        </div>

        {coverage.orders_with_money_withheld > 0 && (
          <p data-testid="commerce-withheld" className="text-xs text-text-muted">
            {ar
              ? `طلبات إيرادها محجوب لغياب سعر صرف موثوق، وغير مشمولة في الإجمالي: ${coverage.orders_with_money_withheld}`
              : `Orders with revenue withheld (no trustworthy exchange rate), missing from the total: ${coverage.orders_with_money_withheld}`}
          </p>
        )}
      </div>
    </ChartCard>
  )
}
