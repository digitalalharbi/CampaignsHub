import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { getData } from '@/lib/api/client'
import { StoreMirror, storeFunnel, storeKpis } from './StoreMirror'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))

/**
 * STORE-MIRROR-001 (Owner 2026-11-25) — «جزء المتجر يوازي جزء الموقع». The store tab draws the website tab's own components with the
 * store as the source: the same tiles, the same channels / second split / funnel panel, and «المنصة مقابل المتجر».
 */
const range = { from: '2026-11-01', to: '2026-11-25' }
const c = (current: number | null, previous: number | null = null, change_pct: number | null = null) => ({ current, previous, change_pct })
const depth = {
  state: 'ready', period: { ...range, timezone: 'Asia/Riyadh' }, previous_period: range, currency: 'SAR',
  basis: { source: 'store_confirmed', label_ar: 'ما أكّده المتجر', label_en: 'Store-confirmed', demo_orders: 0, live_orders: 40, demo_excluded: false, kind: 'live' },
  sales: { gross: c(12000), net: c(10000, 8000, 0.25), discounts: c(500), shipping: c(800), tax: c(1500), refunded: c(200) },
  orders: { total: 44, live: 40, confirmed: 36, pending: 4, cancelled: 4, refunded: 2, previous_live: 32 },
  averages: { aov: 250, items_per_order: 1.4, cancellation_rate: 0.09, refund_rate: 0.05, discount_share: 0.04 },
  conversion: { value: 0.02, confirmed_orders: 36, visits: 1800, visits_source: 'ga4', label_ar: null, label_en: null },
  products: { best_by_revenue: [{ name: 'Ring', image_url: null, quantity: 5, revenue: 3000, orders: 5 }], best_by_orders: [], weak: [], unsold_active: { count: 0, sample: [] }, categories: [], categories_note: null },
  abandoned: { carts: 10, value: 2000, by_product: null, by_product_reason: { ar: '', en: '' } },
  customers: { new: 30, returning: 8, without_customer: 2, repeat_rate: 0.2, customers_in_ledger: 38, ledger_since: '2026-01-01', lifetime_value: null, lifetime_value_reason: 'too_few_customers', lifetime_value_basis: { ar: '', en: '' } },
  trend: [{ date: '2026-11-01', orders: 20, net: 5000 }, { date: '2026-11-02', orders: 20, net: 5000 }],
  insights: {
    status_mix: [{ key: 'completed', orders: 40, share: 0.9 }, { key: 'cancelled', orders: 4, share: 0.1 }],
    payment_mix: { rows: [{ key: 'mada', orders: 30, net: 7000, share: 0.75 }, { key: 'cod', orders: 10, net: 3000, share: 0.25 }], other: null, unstated: 0, stated: 40 },
    city_mix: { rows: [{ key: 'Riyadh', orders: 25, net: 6000, share: 0.66 }], other: null, unstated: 2, stated: 38 },
    heatmap: { cells: Array.from({ length: 7 }, () => Array(24).fill(1)), max: 1, peak: { day: 0, hour: 0, orders: 1 }, by_day: Array(7).fill(24), by_hour: Array(24).fill(7), timezone: 'Asia/Riyadh' },
    basket: { bands: [], median: 250 }, coupons: { rows: [], orders_with_coupon: 4, share: 0.1 },
    composition: { products: 9500, shipping: 800, tax: 1500, discounts: 500, refunded: 200 },
  },
  coverage: { orders_with_money_withheld: 0, money_withheld_currencies: [], duplicate_orders_collapsed: 0 },
}
const compare = {
  period: { ...range, timezone: 'Asia/Riyadh' }, summed: false, available: { platform_reported: true, store_confirmed: true, ga4_measured: false }, sources: [],
  rows: [
    { platform: 'snapchat', platform_reported: { orders: 90, revenue: null }, store_confirmed: { orders: 18, revenue: 4500 }, ga4_measured: null, differences: { platform_minus_store_orders: 72, material: true }, explanation: { ar: '', en: '' } },
    { platform: 'tiktok', platform_reported: { orders: 30, revenue: null }, store_confirmed: { orders: 10, revenue: 2500 }, ga4_measured: null, differences: { platform_minus_store_orders: 20, material: true }, explanation: { ar: '', en: '' } },
  ],
  store_unattributed: { orders: 12, revenue: 3000 }, store_currency: 'SAR',
}

describe('the store tab mirrors the website tab', () => {
  it('puts the store figures in the website tiles: four primary with their trend, then money and operations', () => {
    const k = storeKpis(depth as never)
    expect(k.slice(0, 4).map((x) => x.key)).toEqual(['net_sales', 'orders', 'aov', 'customers'])
    expect(k.find((x) => x.key === 'orders')?.change_pct).toBe(0.25)
    expect(k.find((x) => x.key === 'net_sales')?.trend).toHaveLength(2)
  })

  it('builds the store funnel: carts → orders → confirmed → not refunded', () => {
    expect(storeFunnel(depth as never).map((s) => [s.stage, s.count])).toEqual([['carts', 50], ['orders', 40], ['confirmed', 36], ['kept', 38]])
  })

  it('draws tiles, the orders-and-sales chart, sources / payment / funnel, and platform vs store with the real cost per order', async () => {
    vi.mocked(getData).mockImplementation(async (url: string) => (url.includes('/measurement/compare') ? compare : depth))
    renderWithProviders(<StoreMirror projectId="p1" range={range} ar={false} spend={2000} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('store-tiles')).toHaveAttribute('data-layout', 'compact')
    expect(screen.getByTestId('ga4-tile-net_sales')).toHaveAttribute('data-tier', 'primary')
    expect(screen.getByTestId('ga4-tiles-operations')).toBeInTheDocument()
    expect(screen.getByTestId('store-series')).toBeInTheDocument()
    expect(await screen.findByTestId('ga4-channel-snapchat')).toBeInTheDocument()
    expect(screen.getByTestId('ga4-channel-unattributed')).toBeInTheDocument()
    expect(screen.getByTestId('ga4-device-mada')).toBeInTheDocument()
    expect(screen.getByTestId('ga4-funnel')).toBeInTheDocument()
    expect(screen.getByTestId('store-vs-orders')).toHaveTextContent('40')
    expect(screen.getByTestId('store-vs-cpa')).toHaveTextContent('50')
    expect(screen.getByTestId('store-vs-snapchat')).toHaveTextContent('90')
    expect(screen.getByTestId('store-extras')).toBeInTheDocument()
  })

  it('adds the deeper reading: only the figures that exist, the money split and the product lists (STORE-ANALYTICS-PLUS-001)', async () => {
    const rich = {
      ...depth,
      products: { ...depth.products, best_by_orders: [{ name: 'Ring', image_url: null, quantity: 5, revenue: 3000, orders: 5 }], weak: [{ name: 'Pen', image_url: null, quantity: 1, revenue: 90, orders: 1 }], unsold_active: { count: 3, sample: ['Box'] } },
      insights: { ...depth.insights, coupons: { ...depth.insights.coupons, aov_with: 210, aov_without: 260 } },
    }
    vi.mocked(getData).mockImplementation(async (url: string) => (url.includes('/measurement/compare') ? compare : rich))
    renderWithProviders(<StoreMirror projectId="p1" range={range} ar={false} spend={2000} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('store-deeper')).toBeInTheDocument()
    expect(screen.getByTestId('store-deeper-conversion')).toHaveTextContent('2.00%')
    expect(screen.getByTestId('store-deeper-repeat')).toHaveTextContent('20%')
    expect(screen.getByTestId('store-deeper-aov-coupon')).toHaveTextContent('without')
    expect(screen.getByTestId('store-deeper-unsold')).toHaveTextContent('3')
    expect(screen.queryByTestId('store-deeper-ltv')).toBeNull()
    expect(screen.getByTestId('store-deeper-asked')).toHaveTextContent('Ring')
    expect(screen.getByTestId('store-deeper-weak')).toHaveTextContent('Pen')
    expect(screen.getByTestId('store-deeper-unsold-list')).toHaveTextContent('Box')
  })
})
