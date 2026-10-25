import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { StoreSalesTab, type InsightsPayload } from './StoreSalesTab'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
vi.mock('recharts', async (orig) => {
  const actual = await orig<typeof import('recharts')>()
  // jsdom has no layout: give the responsive container a size so the chart draws its children.
  return { ...actual, ResponsiveContainer: ({ children }: { children: React.ReactElement }) => <div style={{ width: 600, height: 240 }}>{children}</div> }
})
import { getData } from '@/lib/api/client'

/**
 * COMMERCE-INSIGHTS-001 — the Store sales tab states what the store says, with its provenance, and states absence in words.
 */
const totals = { orders: 4, cancelled_orders: 1, orders_priced: 3, gross_sales: 600, subtotal: 575, discounts: 50, shipping: 15, tax: 30, refunds: 40, net_sales: 560, aov: 186.67, refund_rate: 0.0667 }
const product = (key: string, sales: number, qty: number) => ({ key, name: `Item ${key}`, sku: key, quantity: qty, sales, orders: 1 })

function payload(over: Partial<InsightsPayload> = {}): InsightsPayload {
  return {
    window: { from: '2026-10-01', to: '2026-10-07', timezone: 'Asia/Riyadh', days: 7, previous: { from: '2026-09-24', to: '2026-09-30' } },
    provenance: { currency: 'SAR', has_store: true, duplicates_collapsed: 2, orders_with_money_withheld: 1, orders_with_assumed_timezone: 0, stores: [{ id: 's1', name: 'Demo store', provider: 'salla', last_synced_at: null }] },
    totals, previous: { ...totals, net_sales: 400 }, growth: { orders: 300, gross_sales: 50, discounts: 0, refunds: 0, net_sales: 40, aov: -10 },
    trend: [{ date: '2026-10-02', orders: 1, net_sales: 200 }, { date: '2026-10-03', orders: 1, net_sales: 60 }],
    products: {
      by_product: { count: 2, best: [product('SH-1', 460, 5), product('BG-1', 140, 2)], worst: [product('BG-1', 140, 2), product('SH-1', 460, 5)] },
      by_sku: { count: 2, best: [product('SH-1', 460, 5)], worst: [product('SH-1', 460, 5)] }, units: 7, lines_without_sku: 0,
    },
    categories: { rows: [{ category: 'Footwear', quantity: 5, sales: 460 }, { category: 'uncategorised', quantity: 2, sales: 140 }], uncategorised_share: 0.23 },
    geography: { basis: 'customer_city', shipping_destination: 'not_available', rows: [{ city: 'Riyadh', orders: 2, net_sales: 300, share: 0.5 }, { city: 'unknown', orders: 1, net_sales: 60, share: 0.25 }], unknown_orders: 1 },
    customers: { new: { customers: 1, orders: 1, net_sales: 200 }, returning: { customers: 2, orders: 2, net_sales: 360 }, orders_without_customer: 0, retention: null, retention_reason: 'needs_cohort_history' },
    coupons: { orders_with_coupon: 2, share_of_orders: 0.5, rows: [{ code: 'SAVE10', orders: 2, discount: 50, net_sales: 500 }] },
    abandoned_carts: { supported: true, count: 1, value: 80, complete: true },
    profitability: { available: false, reason: 'no_cost_data' },
    ...over,
  }
}
const mount = () => renderWithProviders(<StoreSalesTab projectId="p1" range={{ from: '2026-10-01', to: '2026-10-07' }} />, { locale: 'en' })

beforeEach(() => { vi.clearAllMocks(); vi.mocked(getData).mockResolvedValue(payload()) })

describe('StoreSalesTab', () => {
  it('asks the insights endpoint for the chosen window of THIS project', async () => {
    mount()
    await screen.findByTestId('store-sales')
    expect(getData).toHaveBeenCalledWith('/projects/p1/commerce/insights?from=2026-10-01&to=2026-10-07')
  })

  it('shows the headline figures with their growth and the exact money behind the compact ones', async () => {
    mount()
    const kpis = await screen.findByTestId('sales-kpis')
    expect(kpis.textContent).toContain('Net sales')
    expect(kpis.textContent).toContain('560')
    expect(kpis.textContent).toMatch(/Refunds/)
    expect(kpis.textContent).toMatch(/Average order value/)
  })

  it('lists best sellers, switches to the lowest, and to SKUs', async () => {
    mount()
    const products = await screen.findByTestId('sales-products')
    expect(within(products).getAllByRole('row')[1].textContent).toContain('Item SH-1')
    fireEvent.click(screen.getByTestId('sales-side-worst'))
    await waitFor(() => expect(within(screen.getByTestId('sales-products')).getAllByRole('row')[1].textContent).toContain('Item SH-1'))
    fireEvent.click(screen.getByTestId('sales-basis-by_sku'))
    expect(screen.getByTestId('sales-basis-by_sku').getAttribute('aria-pressed')).toBe('true')
  })

  it('says what the cities are based on and shows unknown as unknown', async () => {
    mount()
    const cities = await screen.findByTestId('sales-cities')
    expect(screen.getByText(/shipping destination is not stored/)).toBeInTheDocument()
    expect(cities.textContent).toContain('Riyadh')
    expect(cities.textContent).toContain('Unknown')
  })

  it('states retention, profitability and unsupported carts in words, never as zero', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ abandoned_carts: { supported: false, count: null, value: null } }))
    mount()
    expect((await screen.findByTestId('sales-retention-unavailable')).textContent).toMatch(/not available/)
    expect(screen.getByTestId('sales-profit-unavailable').textContent).toMatch(/no product cost data/)
    expect(screen.getByTestId('sales-carts-unsupported').textContent).toMatch(/does not report abandoned carts/)
    expect(screen.queryByTestId('sales-carts')).toBeNull()
  })

  it('names the source and says how many orders were left out of the money and collapsed', async () => {
    mount()
    const src = await screen.findByTestId('sales-provenance')
    expect(src.textContent).toContain('Demo store')
    expect(src.textContent).toContain('Asia/Riyadh')
    expect(screen.getByTestId('sales-withheld').textContent).toMatch(/1 order\(s\) left out of the money totals/)
    expect(src.textContent).toMatch(/2 duplicate order\(s\) collapsed/)
  })

  it('says there is no store instead of drawing an empty sales page', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ provenance: { ...payload().provenance, has_store: false, stores: [] }, totals: { ...totals, orders: 0 } }))
    mount()
    expect(await screen.findByTestId('sales-no-store')).toBeInTheDocument()
  })

  it('asks for a project when none is chosen and does not call the API', async () => {
    renderWithProviders(<StoreSalesTab projectId={null} range={{ from: '2026-10-01', to: '2026-10-07' }} />, { locale: 'en' })
    expect(await screen.findByText('Pick a project')).toBeInTheDocument()
    expect(getData).not.toHaveBeenCalled()
  })
})
