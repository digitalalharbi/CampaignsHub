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
      spend: 1000, revenue: 5000, gross_revenue: 5200, refunded: 200, cancelled_orders: 1,
      orders: 25, new_customers: 10, attributed_orders: 15, attributed_revenue: 3000,
      unattributed_orders: 10,
    },
    derived: { cpa: 40, cac: 100, aov: 200, roas: 5, attributed_roas: 3, conversion_rate: 5 },
    comparisons: { platforms: [], campaigns: [], products: [] },
    coverage: {
      stores: 1, stores_without_cart_data: [], store_last_synced_at: null,
      orders_in_window: 25, orders_without_attribution: 10,
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
      },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-note-add_to_cart')).toBeInTheDocument()
    expect(screen.getByTestId('funnel-coverage').textContent).toMatch(/متجر زد/)
  })

  /** With no store connected, the section says why the order stages are empty. */
  it('says there is no store rather than showing an empty funnel', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      coverage: { stores: 0, stores_without_cart_data: [], store_last_synced_at: null, orders_in_window: 0, orders_without_attribution: 0 },
    }))

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByTestId('funnel-no-store')).toBeInTheDocument()
  })

  /** CAC and CPA are different questions, and the card says which is which. */
  it('distinguishes CAC from CPA on the face of the card', async () => {
    vi.mocked(getData).mockResolvedValue(payload())

    renderWithProviders(<StoreFunnelTab projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByText(/الإنفاق ÷ العملاء الجدد/)).toBeInTheDocument()
    expect(screen.getByText(/الإنفاق ÷ الطلبات/)).toBeInTheDocument()
  })
})
