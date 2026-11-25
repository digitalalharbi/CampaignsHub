import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { OrderSourcesPanel } from './OrderSourcesPanel'
import { StoreDepthPanel } from './StoreDepthPanel'
import { ThreeSourcesPanel } from './ThreeSourcesPanel'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/** STORE-ANALYTICS-DEPTH-001, GA4-PLATFORM-COMPARE-001 — what the operator reads; no real store or property behind it. */
const range = { from: '2026-10-01', to: '2026-10-31' }
const compared = (current: number | null, previous: number | null = null, change_pct: number | null = null) => ({ current, previous, change_pct })
const depth = {
  state: 'ready', period: { ...range, timezone: 'Asia/Riyadh' }, previous_period: range, currency: 'USD',
  basis: { source: 'store_confirmed', label_ar: 'ما أكّده المتجر', label_en: 'Store-confirmed', demo_orders: 0, live_orders: 3, demo_excluded: false, kind: 'live' },
  sales: { gross: compared(350), net: compared(300, 100, 2), discounts: compared(null), shipping: compared(null), tax: compared(null), refunded: compared(50) },
  orders: { total: 4, live: 3, confirmed: 2, pending: 1, cancelled: 1, refunded: 1, previous_live: 1 },
  averages: { aov: 100, items_per_order: null, cancellation_rate: 0.25, refund_rate: 0.33, discount_share: null },
  conversion: { value: null, confirmed_orders: 2, visits: null, visits_source: null, label_ar: null, label_en: null },
  products: { best_by_revenue: [], best_by_orders: [], weak: [], unsold_active: { count: 2, sample: ['Hat'] }, categories: [], categories_note: { ar: 'لا تصنيف', en: 'No category' } },
  abandoned: { carts: 1, value: 80, by_product: null, by_product_reason: { ar: 'لا أصناف', en: 'No lines stored' } },
  customers: { new: 1, returning: 1, without_customer: 1, repeat_rate: 0.5, customers_in_ledger: 2, ledger_since: '2026-02-01', lifetime_value: null, lifetime_value_reason: 'too_few_customers', lifetime_value_basis: { ar: 'أساس', en: 'Basis' } },
  trend: [], coverage: { orders_with_money_withheld: 1, money_withheld_currencies: ['EUR'], duplicate_orders_collapsed: 0 },
}

describe('the store panels', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('STORE-INSIGHTS-001 — draws large and small cards, the week heat map, circles, best sellers with pictures, basket and coupons', async () => {
    const cells = Array.from({ length: 7 }, () => Array(24).fill(0))
    cells[4][21] = 9
    cells[1][13] = 3
    vi.mocked(getData).mockResolvedValue({
      ...depth,
      trend: [{ date: '2026-10-01', orders: 2, net: 150 }, { date: '2026-10-02', orders: 1, net: 150 }],
      products: { ...depth.products, best_by_revenue: [{ name: 'Gold ring', image_url: 'https://cdn.salla.test/ring.jpg', quantity: 3, revenue: 900, orders: 3 }, { name: 'Tasbih', image_url: null, quantity: 1, revenue: 100, orders: 1 }] },
      insights: {
        status_mix: [{ key: 'completed', orders: 3, share: 0.75 }, { key: 'cancelled', orders: 1, share: 0.25 }],
        payment_mix: { rows: [{ key: 'mada', orders: 2, net: 200, share: 0.67 }, { key: 'cod', orders: 1, net: 100, share: 0.33 }], other: null, unstated: 0, stated: 3 },
        city_mix: { rows: [{ key: 'Riyadh', orders: 2, net: 200, share: 1 }], other: null, unstated: 1, stated: 2 },
        heatmap: { cells, max: 9, peak: { day: 4, hour: 21, orders: 9 }, by_day: [0, 3, 0, 0, 9, 0, 0], by_hour: Array.from({ length: 24 }, (_, h) => (h === 21 ? 9 : h === 13 ? 3 : 0)), timezone: 'Asia/Riyadh' },
        basket: { bands: [{ from: 0, to: 50, orders: 1, net: 40 }, { from: 50, to: 100, orders: 2, net: 160 }, { from: 100, to: null, orders: 2, net: 400 }], median: 80 },
        coupons: { rows: [{ code: 'RAMADAN', orders: 2, discount: 30, net: 200 }], orders_with_coupon: 2, share: 0.4 },
        composition: { products: 280, shipping: 40, tax: 30, discounts: 30, refunded: 50 },
      },
    })
    renderWithProviders(<StoreDepthPanel projectId="p1" range={range} ar={false} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('store-insights')).toBeInTheDocument()
    expect(screen.getByTestId('store-hero-net')).toHaveTextContent('$')
    expect(screen.getByTestId('store-delta-orders')).toHaveTextContent('200%')
    expect(screen.getByTestId('store-mini-cards').children.length).toBeGreaterThanOrEqual(10)
    // The week: Thursday 9 pm is named as the peak, in the merchant's own zone.
    expect(screen.getByTestId('store-heatmap-peak')).toHaveTextContent('Thu 9pm')
    expect(screen.getByTestId('store-heat-4-21')).toHaveAttribute('title', expect.stringContaining('9'))
    // Best sellers with their own pictures; a product with none keeps its place with a placeholder.
    expect(screen.getByTestId('store-gallery-0').querySelector('img')).toHaveAttribute('src', 'https://cdn.salla.test/ring.jpg')
    expect(screen.getByTestId('store-gallery-1').querySelector('img')).toBeNull()
    // The circles, and what each field leaves unstated said, not spread.
    for (const id of ['store-donut-status', 'store-donut-payment', 'store-donut-city', 'store-donut-customers', 'store-donut-money']) expect(screen.getByTestId(id)).toBeInTheDocument()
    expect(screen.getByTestId('store-donut-city')).toHaveTextContent('1 order(s) did not state this')
    expect(screen.getByTestId('store-basket')).toHaveTextContent('2')
    expect(screen.getByTestId('store-coupons')).toHaveTextContent('RAMADAN')
  })

  it('labels the store as the authority, states growth, absent figures and the withheld currency', async () => {
    vi.mocked(getData).mockResolvedValue(depth)
    renderWithProviders(<StoreDepthPanel projectId="p1" range={range} ar={false} />, { route: '/agency/analytics', locale: 'en' })
    // STORE-INSIGHTS-001 — the large card carries the growth as its own chip.
    expect(await screen.findByTestId('store-delta-net')).toHaveTextContent('200%')
    expect(screen.getByTestId('store-depth')).toHaveTextContent('Store-confirmed')
    expect(screen.getByTestId('store-mini-discounts')).toHaveTextContent('—')
    expect(screen.getByTestId('store-mini-conversion')).toHaveTextContent('Nothing measures visits')
    expect(screen.getByTestId('store-ltv-note')).toHaveTextContent('Too few customers')
    expect(screen.getByTestId('store-withheld')).toHaveTextContent('EUR')
    expect(screen.getByTestId('store-abandoned')).toHaveTextContent('No lines stored')
    expect(screen.queryByTestId('store-depth-demo')).toBeNull()
  })

  it('warns that demo rows are no evidence for a production report', async () => {
    vi.mocked(getData).mockResolvedValue({ ...depth, basis: { ...depth.basis, demo_orders: 2, kind: 'mixed' } })
    renderWithProviders(<StoreDepthPanel projectId="p1" range={range} ar={false} />, { route: '/agency/analytics', locale: 'en' })
    expect(await screen.findByTestId('store-depth-demo')).toHaveTextContent('not evidence for a production report')
  })

  it('says there is no store instead of drawing zeroes', async () => {
    vi.mocked(getData).mockResolvedValue({ state: 'no_store', period: { ...range, timezone: 'UTC' }, previous_period: range })
    renderWithProviders(<StoreDepthPanel projectId="p1" range={range} ar />, { route: '/agency/analytics', locale: 'ar' })
    expect(await screen.findByTestId('store-depth-no_store')).toHaveTextContent('لا يوجد متجر')
  })

  const sources = {
    state: 'ready', total: 2, currency: 'USD', ga4_available: true,
    totals: { orders: 5, net_revenue: 500, cancelled_excluded: 1, money_withheld_orders: 0, money_withheld_currencies: [] },
    rows: [
      { dimension_1: 'newsletter', dimension_2: 'email', kind: 'value', orders: 3, confirmed: 2, net_revenue: 300, share: 0.6, money_withheld_orders: 0, methods: { utm_source: 3 }, ga4_sessions: 50, ga4_add_to_carts: 4, ga4_purchases: 3, ga4_revenue: 900, ga4_currency: 'SAR', confirmed_per_session: 0.04 },
      { dimension_1: 'unattributed', dimension_2: null, kind: 'unattributed', orders: 2, confirmed: 1, net_revenue: 200, share: 0.4, money_withheld_orders: 0, methods: { unattributed: 2 }, ga4_sessions: null, ga4_add_to_carts: null, ga4_purchases: null, ga4_revenue: null, ga4_currency: null, confirmed_per_session: null },
    ],
  }

  it('reads sources from the server, names each row and opens its method on demand', async () => {
    vi.mocked(getData).mockResolvedValue(sources)
    renderWithProviders(<OrderSourcesPanel projectId="p1" range={range} ar />, { route: '/agency/analytics', locale: 'ar' })
    expect(await screen.findByText('newsletter / email')).toBeInTheDocument()
    expect(screen.getByText('غير منسوب')).toBeInTheDocument()
    expect(vi.mocked(getData).mock.calls[0][0]).toContain('group=source_medium')
    expect(vi.mocked(getData).mock.calls[0][0]).toContain('per_page=10')
    fireEvent.click(screen.getAllByTestId('table-expand')[0])
    expect(await screen.findByTestId('source-methods')).toHaveTextContent('مصدر UTM')
    expect(screen.getByTestId('order-sources-footer')).toHaveTextContent('لا تُجمع')
  })

  it('shows each order with its original currency and method, and never a customer column', async () => {
    vi.mocked(getData).mockImplementation(async (url: string) => (url.includes('/sources') ? sources : {
      state: 'ready', total: 1, currency: 'USD',
      rows: [{ id: 'o1', reference: '1001', placed_at: '2026-10-05T10:00:00+03:00', status: 'completed', confirmed: true, cancelled: false, refunded: false, original_total: 375, original_currency: 'SAR', net: 100, money_withheld: false, method: 'click_id', method_label: { ar: 'معرّف نقرة المنصة', en: 'Platform click id' }, source: 'meta', medium: null, campaign: null, content: null, term: null, coupon: null, click_id_platform: 'meta', landing_path: '/p', is_demo: false }],
    }) as never)
    renderWithProviders(<OrderSourcesPanel projectId="p1" range={range} ar={false} />, { route: '/agency/analytics', locale: 'en' })
    fireEvent.click(await screen.findByTestId('order-sources-tab-orders'))
    expect(await screen.findByText(/375/)).toHaveTextContent('ر.س')
    expect(document.querySelector('tbody')).toHaveTextContent('Platform click id')
    expect(screen.queryByRole('columnheader', { name: /customer|email|phone/i })).toBeNull()
  })

  it('sets the three sources side by side and names an absent one as absent', async () => {
    vi.mocked(getData).mockResolvedValue({
      period: { ...range, timezone: 'Asia/Riyadh' }, summed: false, available: { platform_reported: true, store_confirmed: true, ga4_measured: false },
      sources: [
        { key: 'platform_reported', label_ar: 'ما أبلغت به المنصات', label_en: 'Platform-reported', orders: 10, note_ar: '', note_en: '' },
        { key: 'store_confirmed', label_ar: 'ما أكّده المتجر', label_en: 'Store-confirmed', orders: 2, note_ar: '', note_en: '' },
        { key: 'ga4_measured', label_ar: 'ما قاسه الموقع (GA4)', label_en: 'GA4-measured', orders: null, note_ar: '', note_en: '' },
      ],
      rows: [{ platform: 'meta', platform_reported: { orders: 10, revenue: 1000, currency: 'USD' }, store_confirmed: { orders: 1, revenue: 100 }, ga4_measured: null, differences: { platform_minus_store_orders: 9, material: true }, explanation: { ar: 'تفسير', en: 'The platform credits itself with more' } }],
      store_unattributed: { orders: 1, revenue: 40 }, store_currency: 'USD',
    })
    renderWithProviders(<ThreeSourcesPanel projectId="p1" range={range} ar={false} />, { route: '/agency/analytics', locale: 'en' })
    await waitFor(() => expect(screen.getByTestId('three-sources')).toBeInTheDocument())
    expect(screen.getByTestId('three-sources-ga4_measured')).toHaveTextContent('No data from this source')
    expect(screen.getByTestId('three-sources-gaps')).toHaveTextContent('credits itself')
    expect(screen.getByTestId('three-sources-unattributed')).toHaveTextContent('No attribution model removes overlap')
  })
})
