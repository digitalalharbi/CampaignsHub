import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { StoreFunnelTab } from './StoreFunnelTab'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const RANGE = { from: '2026-07-07', to: '2026-08-05' }

function stage(key: string, over: Record<string, unknown> = {}) {
  return {
    key,
    label_ar: key,
    label_en: key,
    value: 100,
    state: 'measured',
    source: { kind: 'ad_platforms', ar: 'من تقارير المنصات', en: 'From the platforms' },
    note_ar: null,
    note_en: null,
    ...over,
  }
}

function payload(over: Record<string, unknown> = {}) {
  return {
    window: RANGE,
    stages: [
      stage('impressions', { value: 10000 }),
      stage('clicks', { value: 500 }),
      stage('product_views', {
        value: null,
        state: 'unavailable',
        source: { kind: 'none', ar: 'لا تُتيح سلة ولا زد عدد مشاهدات المنتج عبر الـ API.', en: 'Neither platform exposes product views.' },
      }),
      stage('orders', { value: 25, source: { kind: 'stores', ar: 'من المتجر المرتبط', en: 'From the store' } }),
    ],
    steps: [
      { from: 'impressions', to: 'clicks', conversion_rate: 5, drop_off: 95, spans_unmeasured_stages: false },
      { from: 'clicks', to: 'orders', conversion_rate: 5, drop_off: 95, spans_unmeasured_stages: true },
    ],
    totals: {
      reporting_currency: 'SAR',
      spend: 1000, revenue: 5000, gross_revenue: 5200, refunded: 200, cancelled_orders: 1,
      orders: 25, new_customers: 10, attributed_orders: 15, attributed_revenue: 3000,
      unattributed_orders: 10,
    },
    derived: { cpa: 40, cac: 100, aov: 200, roas: 5, attributed_roas: 3, conversion_rate: 5 },
    comparisons: { platforms: [], campaigns: [], products: [] },
    coverage: {
      stores: 1, stores_without_cart_data: [], store_last_synced_at: null,
      orders_in_window: 25, orders_without_attribution: 10,
      reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
    },
    ...over,
  }
}

describe('StoreFunnelTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  /**
   * STORE-TAB-STORE-ONLY-001 (Owner 2026-11-26) — «قمع المنصات الإعلانية مازال موجود وكبير الحجم وغير مناسب». The store tab's source is
   * the store, as the website tab's is GA4: with no store it is one chip and nothing else — no platform funnel, folded or open.
   */
  it('with no store, shows one chip and no ad-platform funnel at all', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ coverage: { ...payload().coverage, stores: 0, orders_in_window: 0 } }))
    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-no-store')).toHaveTextContent('المتجر غير مربوط')
    expect(screen.queryByTestId('funnel-stages')).toBeNull()
    expect(screen.queryByTestId('store-technical-details')).toBeNull()
    expect(screen.queryByText(/بكسل المنصات/)).toBeNull()
    expect(screen.queryByTestId('store-mirror')).toBeNull()
  })

  it('with a store, opens on the store view and folds only store-sourced details', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('store-technical-details')).toBeInTheDocument()
    expect(screen.queryByTestId('funnel-stages')).toBeNull()
    expect(screen.queryByTestId('funnel-no-store')).toBeNull()
    expect(screen.getByTestId('funnel-coverage')).toHaveTextContent('SAR')
  })

  /**
   * COMMERCE-FX-001 — a revenue figure that is missing an order says so.
   *
   * An order in a currency with no dated rate is withheld rather than added unconverted, so the
   * total above is genuinely short. Printing it silently would be the page claiming a complete
   * number, which is the one thing this whole unit exists to stop.
   */
  /**
   * COMMERCE-TZ-001 — the window names the clock it was measured on.
   *
   * «5 August» is a different sixty thousand seconds in every timezone. A report that does not say
   * which one it used leaves the reader to assume theirs, and a boundary order to look like an error.
   */
  /** And when a store never stated its zone, the assumption is on the page rather than in the code. */
})
