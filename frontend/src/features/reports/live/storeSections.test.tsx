import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { StoreSections } from './StoreSections'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from '../api'
import type { ReportSectionKey } from '../reportSections'

vi.mock('recharts', async (orig) => {
  const actual = await orig<typeof import('recharts')>()
  return { ...actual, ResponsiveContainer: ({ children }: { children: React.ReactElement }) => <div style={{ width: 600, height: 240 }}>{children}</div> }
})

/**
 * STORE-REPORT-SECTIONS-001 — the four store sections on the client's Live report: each shows on its own, a hidden or absent one draws
 * nothing, the provenance and the «not added to other revenue» sentence close the group, and withheld money is «—», never a zero.
 */
const meta = { currency: 'SAR', window: { from: '2026-10-01', to: '2026-10-07', timezone: 'Asia/Riyadh' }, stores: [{ name: 'Demo store', provider: 'salla', last_synced_at: null }], orders_with_money_withheld: 0, duplicates_collapsed: 0 }
const totals = { orders: 4, cancelled_orders: 1, gross_sales: 600, discounts: 50, shipping: 15, tax: 30, refunds: 40, net_sales: 560, aov: 186.67, refund_rate: 0.0667 }

function payload(over: Partial<LivePayload> = {}): LivePayload {
  return {
    store_sales: { ...meta, totals, previous: null, growth: null, trend: [{ date: '2026-10-02', orders: 1, net_sales: 200 }] },
    store_products: { ...meta, products: { by_product: { count: 1, best: [{ key: 'SH-1', name: 'Shoes', sku: 'SH-1', quantity: 5, sales: 460, orders: 2 }], worst: [] }, by_sku: { count: 0, best: [], worst: [] }, units: 5, lines_without_sku: 0 }, categories: { rows: [], uncategorised_share: null } },
    store_orders: { ...meta, totals, coupons: { orders_with_coupon: 0, share_of_orders: null, rows: [] }, abandoned_carts: { supported: false, count: null, value: null } },
    store_audience: { ...meta, geography: { basis: 'customer_city', shipping_destination: 'not_available', rows: [{ city: 'Riyadh', orders: 2, net_sales: 300, share: 0.5 }], unknown_orders: 0 }, customers: { new: { customers: 1, orders: 1, net_sales: 200 }, returning: { customers: 2, orders: 2, net_sales: 360 }, orders_without_customer: 0, retention: null, retention_reason: 'needs_cohort_history' } },
    ...over,
  } as LivePayload
}
const all = (_k: ReportSectionKey) => true
const mount = (p: LivePayload, on: (k: ReportSectionKey) => boolean = all) => renderWithProviders(<StoreSections payload={p} ar={false} on={on} />, { locale: 'en' })

describe('Live report store sections', () => {
  it('draws all four sections and the provenance once, with the not-blended sentence', () => {
    mount(payload())
    for (const id of ['live-store-sales', 'live-store-orders', 'live-store-products', 'live-store-audience']) expect(screen.getByTestId(id)).toBeInTheDocument()
    expect(screen.getAllByTestId('sales-provenance')).toHaveLength(1)
    expect(screen.getByTestId('store-not-blended').textContent).toMatch(/not added to the ad platforms/)
    expect(screen.getByTestId('sales-kpis').textContent).toContain('560')
    expect(screen.getByTestId('sales-cities').textContent).toContain('Riyadh')
  })

  it('each section is shown or hidden on its own', () => {
    mount(payload(), (k) => k === 'store_products')
    expect(screen.getByTestId('live-store-products')).toBeInTheDocument()
    for (const id of ['live-store-sales', 'live-store-orders', 'live-store-audience']) expect(screen.queryByTestId(id)).toBeNull()
  })

  it('draws nothing at all when every store section is off or absent — no empty card', () => {
    const { container } = mount(payload(), () => false)
    expect(container.innerHTML).toBe('')
    const none = mount(payload({ store_sales: null, store_products: null, store_orders: null, store_audience: null }))
    expect(none.container.querySelector('[data-testid="live-store-sections"]')).toBeNull()
  })

  it('a section whose block the server withheld is absent while the others still draw', () => {
    mount(payload({ store_products: null }))
    expect(screen.queryByTestId('live-store-products')).toBeNull()
    expect(screen.getByTestId('live-store-sales')).toBeInTheDocument()
  })

  it('withheld money is «—» with a note, counts remain, and no zero stands in for a hidden figure', () => {
    const w = { ...meta, currency: null, withheld: ['money'] }
    const hidden = { orders: 4, cancelled_orders: 1, gross_sales: null, discounts: null, shipping: null, tax: null, refunds: null, net_sales: null, aov: null, refund_rate: null }
    mount(payload({
      store_sales: { ...w, totals: hidden, previous: null, growth: { orders: 300, net_sales: null }, trend: [{ date: '2026-10-02', orders: 1, net_sales: null }] },
      store_orders: { ...w, totals: hidden, coupons: { orders_with_coupon: 2, share_of_orders: 0.5, rows: [{ code: 'SAVE10', orders: 2, discount: null, net_sales: null }] }, abandoned_carts: { supported: true, count: 1, value: null } },
    }))
    const kpis = screen.getByTestId('sales-kpis').textContent ?? ''
    expect(kpis).toContain('—')
    expect(kpis).not.toMatch(/560|600/)
    expect(screen.getAllByTestId('store-withheld-note').length).toBeGreaterThan(0)
    expect(screen.getByTestId('orders-kpis').textContent).toContain('4')
    expect(screen.getByTestId('sales-carts').textContent).toContain('—')
  })
})
