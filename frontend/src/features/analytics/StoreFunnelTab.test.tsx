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
   * The claim the whole section exists to make: a stage nothing measures says so, and shows the
   * reason — never a zero, which would be a measurement saying nobody looked at anything.
   */
  it('shows an unmeasured stage as not measured, with the reason, instead of zero', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-unmeasured-product_views')).toBeInTheDocument()
    expect(screen.getByText(/لا تُتيح سلة ولا زد/)).toBeInTheDocument()

    const row = screen.getByTestId('funnel-stage-product_views')
    expect(row.textContent).not.toMatch(/\b0\b/)
  })

  it('labels every measured stage with the system that produced it', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    const orders = await screen.findByTestId('funnel-stage-orders')
    expect(orders.textContent).toMatch(/المتجر/)

    const impressions = screen.getByTestId('funnel-stage-impressions')
    expect(impressions.textContent).toMatch(/بكسل المنصات/)
  })

  /** A rate that jumps over stages nobody measured says that it does. */
  it('marks a conversion rate that spans unmeasured stages', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-span-orders')).toBeInTheDocument()
  })

  /**
   * The API states rates as PERCENTAGES, and the page must not multiply them again.
   *
   * It did: the shared `percent()` helper takes a ratio, so a 5% conversion rendered as «500.0%» and a
   * 95% drop-off as «9500.0%». Found by reading the rendered page rather than by any assertion, which
   * is why this one exists.
   */
  it('renders a rate as the percentage the API sent, not a hundred times it', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    const clicks = await screen.findByTestId('funnel-stage-clicks')
    expect(clicks.textContent).toContain('5.0%')
    expect(clicks.textContent).toContain('95.0%')
    expect(clicks.textContent).not.toContain('500.0%')
    expect(clicks.textContent).not.toContain('9500.0%')
  })

  /**
   * Untraceable orders are shown, not folded in.
   *
   * A high share of them is a link-tagging problem worth more than any figure on the page, and a
   * funnel that spread them across the campaigns would hide exactly that.
   */
  it('shows how many orders could not be traced to a campaign', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    const block = await screen.findByTestId('funnel-attribution')
    expect(block.textContent).toMatch(/طلبات بلا إسناد/)
    expect(block.textContent).toMatch(/10/)
  })

  it('says a store platform cannot report abandoned carts instead of leaving the undercount silent', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      stages: [stage('add_to_cart', {
        value: 30,
        state: 'partial',
        source: { kind: 'stores', ar: 'من المتجر المرتبط', en: 'From the store' },
        note_ar: 'أحد المتاجر على منصة لا تُتيح السلات المتروكة، لذلك هذا الرقم أقل من الحقيقي.',
      })],
      coverage: {
        stores: 2,
        stores_without_cart_data: [{ id: 'z1', name: 'متجر زد', provider: 'zid' }],
        store_last_synced_at: null, orders_in_window: 30, orders_without_attribution: 0,
        reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-note-add_to_cart')).toBeInTheDocument()
    expect(screen.getByTestId('funnel-coverage').textContent).toMatch(/متجر زد/)
  })

  /** With no store connected, the section says why the order stages are empty. */
  it('says there is no store rather than showing an empty funnel', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      coverage: {
        stores: 0, stores_without_cart_data: [], store_last_synced_at: null,
        orders_in_window: 0, orders_without_attribution: 0,
        reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-no-store')).toBeInTheDocument()
  })

  /**
   * COMMERCE-FX-001 — a revenue figure that is missing an order says so.
   *
   * An order in a currency with no dated rate is withheld rather than added unconverted, so the
   * total above is genuinely short. Printing it silently would be the page claiming a complete
   * number, which is the one thing this whole unit exists to stop.
   */
  it('warns that the revenue is short when an order could not be converted', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      coverage: {
        stores: 1, stores_without_cart_data: [], store_last_synced_at: null,
        orders_in_window: 25, orders_without_attribution: 10,
        reporting_currency: 'SAR', orders_with_money_withheld: 2, money_withheld_currencies: ['KWD'],
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    const warning = await screen.findByTestId('funnel-money-withheld')
    expect(warning.textContent).toMatch(/KWD/)
    expect(warning.textContent).toMatch(/لم تُحتسب/)
  })

  /**
   * COMMERCE-TZ-001 — the window names the clock it was measured on.
   *
   * «5 August» is a different sixty thousand seconds in every timezone. A report that does not say
   * which one it used leaves the reader to assume theirs, and a boundary order to look like an error.
   */
  it('names the timezone its days were measured in', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      coverage: {
        stores: 1, stores_without_cart_data: [], store_last_synced_at: null,
        orders_in_window: 25, orders_without_attribution: 10,
        reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
        reporting_timezone: 'Asia/Riyadh', orders_with_assumed_timezone: 0,
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect((await screen.findByTestId('funnel-reporting-timezone')).textContent).toMatch(/Asia\/Riyadh/)
    expect(screen.queryByTestId('funnel-assumed-timezone')).toBeNull()
  })

  /** And when a store never stated its zone, the assumption is on the page rather than in the code. */
  it('warns when an order had its timezone assumed', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      coverage: {
        stores: 1, stores_without_cart_data: [], store_last_synced_at: null,
        orders_in_window: 25, orders_without_attribution: 10,
        reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
        reporting_timezone: 'Asia/Riyadh', orders_with_assumed_timezone: 3,
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    const warning = await screen.findByTestId('funnel-assumed-timezone')
    expect(warning.textContent).toMatch(/3/)
    expect(warning.textContent).toMatch(/UTC/)
  })

  /** Every amount is labelled with the currency the SERVER reports, not a hard-coded riyal. */
  it('states the reporting currency the payload names', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      totals: {
        reporting_currency: 'AED',
        spend: 1000, revenue: 5000, gross_revenue: 5200, refunded: 200, cancelled_orders: 1,
        orders: 25, new_customers: 10, attributed_orders: 15, attributed_revenue: 3000,
        unattributed_orders: 10,
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect((await screen.findByTestId('funnel-coverage')).textContent).toMatch(/AED/)
    expect(screen.getByTestId('funnel-attribution').textContent).not.toMatch(/SAR|ر\.س/)
  })

  /** CAC and CPA are different questions, and the card says which is which. */
  it('distinguishes CAC from CPA on the face of the card', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByText(/الإنفاق ÷ العملاء الجدد/)).toBeInTheDocument()
    expect(screen.getByText(/الإنفاق ÷ الطلبات/)).toBeInTheDocument()
  })
})

/**
 * COMMERCE — the subtraction this page already performs, made visible.
 *
 * Every money figure on the store funnel is NET: the ROAS card's own hint says «on net revenue,
 * after refunds». So the page tells a merchant a subtraction happened and never says how big it was
 * — 500 refunded and 50,000 refunded produce an identical screen, and they are not the same month.
 * `gross_revenue`, `refunded` and `cancelled_orders` have been in the payload since the funnel
 * shipped, typed on the client, and rendered nowhere.
 */
describe('what did not stick', () => {
  it('shows gross, refunded and cancelled beside the net figures', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'en' })

    const block = await screen.findByTestId('funnel-refunds')

    /* The page's own money formatting, abbreviated exactly as every other figure on it. */
    expect(block).toHaveTextContent('5.2K SAR')
    expect(block).toHaveTextContent('200 SAR')
    expect(block).toHaveTextContent('Cancelled orders')
  })

  /* Zero refunds is the ordinary answer, and dressing it as a warning trains the reader to ignore it. */
  it('does not flag a month with nothing refunded', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      totals: {
        reporting_currency: 'SAR',
        spend: 1000, revenue: 5000, gross_revenue: 5000, refunded: 0, cancelled_orders: 0,
        orders: 25, new_customers: 10, attributed_orders: 15, attributed_revenue: 3000,
        unattributed_orders: 10,
      },
    }))
    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'en' })

    const block = await screen.findByTestId('funnel-refunds')

    expect(block.querySelectorAll('.text-warning')).toHaveLength(0)
  })

  /* A cancelled order was never counted as revenue, so calling it a refund would double-count it. */
  it('says a cancelled order is not a refund', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'en' })

    expect(await screen.findByText(/not a refund|ليس استردادًا/)).toBeInTheDocument()
  })
})
