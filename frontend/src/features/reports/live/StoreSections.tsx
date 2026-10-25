import { AudienceSection, OrdersSection, ProductsSection, SalesOverview, StoreProvenance } from '@/features/analytics/StoreSalesViews'
import type { LivePayload } from '../api'
import type { ReportSectionKey } from '../reportSections'

const TITLES: Record<string, { ar: string; en: string }> = {
  store_sales: { ar: 'المبيعات والمتجر', en: 'Store & sales' },
  store_products: { ar: 'المنتجات والفئات', en: 'Products, SKUs & categories' },
  store_orders: { ar: 'الطلبات والمرتجعات والخصومات', en: 'Orders, refunds & discounts' },
  store_audience: { ar: 'المدن وشرائح العملاء', en: 'Cities & customer segments' },
}

function Titled({ id, ar, children }: { id: string; ar: boolean; children: React.ReactNode }) {
  return (
    <section data-testid={`live-${id.replace('_', '-')}`} className="space-y-3">
      <h3 className="text-base font-bold text-text-primary">{ar ? TITLES[id]!.ar : TITLES[id]!.en}</h3>
      {children}
    </section>
  )
}

/**
 * STORE-REPORT-SECTIONS-001 — the four store sections on the client's Live report. Each draws only when its own block is present
 * AND the operator left it on; a block the server withheld (no store, no order) is absent, never an empty card. The provenance — which
 * store, which window and currency, what was left out of the money — closes the group once, whichever sections are showing.
 */
export function StoreSections({ payload, ar, on }: { payload: LivePayload; ar: boolean; on: (key: ReportSectionKey) => boolean }) {
  const sales = on('store_sales') ? payload.store_sales : null
  const products = on('store_products') ? payload.store_products : null
  const orders = on('store_orders') ? payload.store_orders : null
  const audience = on('store_audience') ? payload.store_audience : null
  const meta = sales ?? products ?? orders ?? audience

  if (!meta) return null

  return (
    <div data-testid="live-store-sections" className="space-y-6">
      {sales && <Titled id="store_sales" ar={ar}><SalesOverview d={sales} ar={ar} /></Titled>}
      {orders && <Titled id="store_orders" ar={ar}><OrdersSection d={orders} ar={ar} /></Titled>}
      {products && <Titled id="store_products" ar={ar}><ProductsSection d={products} ar={ar} /></Titled>}
      {audience && <Titled id="store_audience" ar={ar}><AudienceSection d={audience} ar={ar} /></Titled>}
      <StoreProvenance d={meta} ar={ar} cancelled={sales?.totals.cancelled_orders ?? orders?.totals.cancelled_orders ?? 0} />
    </div>
  )
}
