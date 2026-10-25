import { useQuery } from '@tanstack/react-query'
import { Store } from 'lucide-react'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { getData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { Panel } from './components'
import {
  AudienceSection, OrdersSection, ProductsSection, SalesOverview, StoreProvenance, STORE_COPY,
  type AudienceBlock, type BlockMeta, type OrdersBlock, type ProductsBlock, type SalesBlock, type Totals,
} from './StoreSalesViews'

/**
 * COMMERCE-INSIGHTS-001 — «مبيعات المتجر»: what the STORE says, with the source and the limits of every figure stated.
 *
 * Read from the merchant's own ledger only. Nothing here is an advertising figure and nothing is added to one; where the store
 * cannot answer (cost, shipping destination, retention, carts on a platform that has none) the panel says so in words rather than
 * drawing a zero. The blocks are the SAME components the client's Live report draws (`StoreSalesViews`).
 */
export interface InsightsPayload {
  window: BlockMeta['window']
  provenance: {
    currency: string; has_store: boolean; duplicates_collapsed: number; orders_with_money_withheld: number; orders_with_assumed_timezone: number
    stores: { id: string; name: string; provider: string; last_synced_at: string | null }[]
  }
  totals: Totals
  previous: Totals | null
  growth: Record<string, number | null> | null
  trend: SalesBlock['trend']
  products: ProductsBlock['products']
  categories: ProductsBlock['categories']
  geography: AudienceBlock['geography']
  customers: AudienceBlock['customers']
  coupons: OrdersBlock['coupons']
  abandoned_carts: OrdersBlock['abandoned_carts']
  profitability: { available: boolean; reason?: string }
}

export function StoreSalesTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar
    ? { ...STORE_COPY.ar, pick: 'اختر مشروعًا', pickDesc: 'مبيعات المتجر تُقرأ على مستوى المشروع.', fail: 'تعذّر تحميل مبيعات المتجر.', noStore: 'لا يوجد متجر مربوط بهذا المشروع، أو لم تصل منه طلبات بعد. اربط سلة أو زد من صفحة التكاملات.', profit: 'الربحية', profitNo: 'الربحية غير متاحة: لا توجد بيانات تكلفة للمنتجات.' }
    : { ...STORE_COPY.en, pick: 'Pick a project', pickDesc: 'Store sales are read per project.', fail: 'Store sales could not be loaded.', noStore: 'No store is connected to this project, or none of its orders have arrived. Connect Salla or Zid from the integrations page.', profit: 'Profitability', profitNo: 'Profitability is unavailable: no product cost data.' }

  const q = useQuery({
    queryKey: ['commerce-insights', projectId, range.from, range.to],
    queryFn: () => getData<InsightsPayload>(`/projects/${projectId}/commerce/insights?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (!projectId) return <EmptyState title={t.pick} description={t.pickDesc} />
  if (q.isLoading) return <Skeleton className="h-96" />
  if (q.isError || !q.data) return <QueryFailure error={q.error} ar={ar} testId="store-sales-failure" onRetry={() => void q.refetch()} fallbackTitle={t.fail} />

  const d = q.data
  const meta: BlockMeta = {
    currency: d.provenance.currency, window: d.window, stores: d.provenance.stores,
    orders_with_money_withheld: d.provenance.orders_with_money_withheld, duplicates_collapsed: d.provenance.duplicates_collapsed,
  }
  const empty = !d.provenance.has_store && d.totals.orders === 0

  return (
    <div className="space-y-4" data-testid="store-sales">
      {empty && (
        <p data-testid="sales-no-store" className="flex items-start gap-2 rounded-xl bg-[var(--warning-background)] px-4 py-3 text-sm text-warning"><Store size={15} className="mt-0.5 shrink-0" />{t.noStore}</p>
      )}
      <SalesOverview ar={ar} d={{ ...meta, totals: d.totals, previous: d.previous, growth: d.growth, trend: d.trend }} />
      <OrdersSection ar={ar} d={{ ...meta, totals: d.totals, coupons: d.coupons, abandoned_carts: d.abandoned_carts }} />
      <ProductsSection ar={ar} d={{ ...meta, products: d.products, categories: d.categories }} />
      <AudienceSection ar={ar} d={{ ...meta, geography: d.geography, customers: d.customers }} />
      <Panel className="min-w-0" title={t.profit}>
        <p data-testid="sales-profit-unavailable" className="text-sm text-text-muted">{d.profitability.available ? '' : t.profitNo}</p>
      </Panel>
      <StoreProvenance d={meta} ar={ar} cancelled={d.totals.cancelled_orders ?? 0} />
    </div>
  )
}
