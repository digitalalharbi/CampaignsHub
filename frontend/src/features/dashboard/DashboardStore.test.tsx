import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import type { CommerceSummary, FreshnessRow } from '../analytics/api'

/**
 * UNIFIED-001 — the connected store's own figures, on the dashboard.
 *
 * The KPI cards carry `revenue` as the ad platforms report it: a pixel's estimate of what it believes
 * its clicks caused. The store block carries the merchant's ledger. They are different numbers, and
 * the whole point of putting both on one page is that the page says which is which — so these tests
 * are about labelling and refusal, not about arithmetic.
 */

const EMPTY = { data: undefined, isLoading: false, isError: false }

/*
 * Mocked at `../analytics/api`, not `../analytics/hooks`.
 *
 * `hooks.ts` is a re-export of `api.ts`, and the overview composition imports from `api` directly.
 * Mocking the re-export replaced a module the code under test never loads, so every hook ran for
 * real, every query stayed pending, and the assertions below failed against a page that was
 * genuinely still loading. Mock what the code imports.
 */
vi.mock('../analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics/api')>()
  return {
    ...actual,
    useSummary: vi.fn(),
    useTimeseries: vi.fn(() => EMPTY),
    usePlatforms: vi.fn(() => EMPTY),
    useCampaigns: vi.fn(() => EMPTY),
    useFunnel: vi.fn(() => EMPTY),
    useBudget: vi.fn(() => EMPTY),
    useFreshness: vi.fn(() => EMPTY),
  }
})

vi.mock('./savedViews', () => ({
  useSavedViews: () => ({ data: [], isLoading: false, isError: false }),
}))

vi.mock('./SavedViewsBar', () => ({ SavedViewsBar: () => null }))

import { useFreshness, useSummary } from '../analytics/api'

const TOTALS = {
  impressions: 1000, clicks: 50, conversions: 2, spend: 500, revenue: 200,
  reach: 0, video_views: 0, video_completions: 0, landing_page_views: 0, leads: 0,
  qualified_leads: 0, purchases: 0, installs: 0, registrations: 0, in_app_events: 0,
  engagements: 0, roas: 0.4, cpa: 250, ctr: 0.05, cpc: 10, cpm: 500, frequency: null,
  cpl: null, cpi: null, cpe: null, aov: null, conversion_rate: 0.04,
  engagement_rate: null, video_completion_rate: null,
}

const STORE: CommerceSummary = {
  available: true, filtered_view: false,
  unfiltered_note_ar: 'أرقام المتجر لكامل المتجر ولا تتأثر بفلتر المنصة أو الهدف، لأن جزءًا من الطلبات يصل بلا إسناد.',
  unfiltered_note_en: 'Store figures cover the whole shop and are not narrowed by the filters.',
  orders: 25, revenue: 5000, aov: 200, roas: 10, cac: 50,
  attributed_orders: 15, attributed_revenue: 3000, unattributed_orders: 10,
  stores: 1, store_last_synced_at: null,
  // COMMERCE-FX-001 — the currency the figures above are in, and the orders missing from them.
  reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [],
  // COMMERCE-TZ-001 — the clock the window was measured on, and any order whose zone was assumed.
  reporting_timezone: 'Asia/Riyadh', orders_with_assumed_timezone: 0,
}

function summary(commerce: CommerceSummary | null) {
  return { data: { current: TOTALS, previous: TOTALS, delta: {}, commerce }, isLoading: false, isError: false }
}

/*
 * Mounted as `<AnalyticsPage surface="dashboard" />` — the component `/app/dashboard` actually
 * renders (router.tsx). These cases were written against `features/dashboard/DashboardPage.tsx`,
 * which stopped being routed and was imported by nothing but these four files: coverage aimed at a
 * page no user could open, while the real Dashboard was covered only by inference.
 *
 * The assertions are unchanged. They already addressed the surface by its testids — `dashboard-intro`,
 * `dashboard-metrics` — and those come from the `surface` prop, so they read the routed page as
 * literally as they read the retired one.
 */
describe('the dashboard store strip', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(useFreshness).mockReturnValue(EMPTY as never)
  })
  afterEach(() => signOut())

  /**
   * The two revenues are both on the page, and each says whose it is.
   *
   * The platforms' `revenue` and the shop's ledger are different numbers. Showing one while the
   * analytics tab shows the other gave two answers to «كم بعنا؟» with nothing to say which was which.
   */
  it('shows the store ledger beside the platforms figures, labelled as the stores', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(STORE) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    const block = await screen.findByTestId('dashboard-store')
    expect(block.textContent).toMatch(/سجل التاجر/)
    expect(screen.getByTestId('store-kpi-orders').textContent).toContain('25')
    expect(screen.getByTestId('store-kpi-roas').textContent).toContain('10')
  })

  /**
   * Untraceable orders are stated, not folded into the campaigns.
   *
   * A high share of them is a link-tagging problem worth more than any figure on the strip, and a
   * dashboard that spread those orders across the campaigns would hide exactly that.
   */
  it('says how many orders arrived with no campaign attribution', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(STORE) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    const note = await screen.findByTestId('dashboard-store-unattributed')
    expect(note.textContent).toMatch(/10/)
    expect(note.textContent).toMatch(/بلا إسناد/)
  })

  /**
   * When the page is filtered and this block is not, the block says so — and still shows its figures.
   *
   * Spend narrows to the chosen platform; an order does not. Suppressing the numbers was the first
   * attempt and was worse than useless — the dashboard opens on an objective filter, so the store's
   * figures would have been replaced by a refusal permanently and shown never. The sentence carries
   * the same warning without withholding the answer.
   */
  it('states that the store block is not narrowed by the pages filters, and still shows them', async () => {
    vi.mocked(useSummary).mockReturnValue(summary({
      ...STORE, filtered_view: true,
    }) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    const note = await screen.findByTestId('dashboard-store-unfiltered')
    expect(note.textContent).toMatch(/لكامل المتجر/)
    // The figures are still there — the warning replaces nothing.
    expect(screen.getByTestId('store-kpi-revenue')).toBeInTheDocument()
  })

  /** With no filter on, there is no warning to give. */
  it('shows no unfiltered warning when nothing is filtered', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(STORE) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    await screen.findByTestId('dashboard-store')
    expect(screen.queryByTestId('dashboard-store-unfiltered')).toBeNull()
  })

  /** No store at all → no strip. An empty store panel reads as a store that sold nothing. */
  it('shows no store strip when the project has no store', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(null) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    // Waits on the page's own header rather than on the applied-filters row: after UX-DASH-001 that
    // row is absent whenever nothing is narrowed, which on a freshly opened dashboard is always.
    await screen.findByTestId('dashboard-intro')
    expect(screen.queryByTestId('dashboard-store')).toBeNull()
  })

  /**
   * COMMERCE-TZ-001 — an assumed timezone is stated on the dashboard too.
   *
   * An order from a store that never said which clock it runs on may belong to the day either side
   * of where it is counted. That is a small error and an invisible one, which is the combination
   * worth naming.
   */
  it('says when an order had its timezone assumed', async () => {
    vi.mocked(useSummary).mockReturnValue(summary({ ...STORE, orders_with_assumed_timezone: 4 }) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    const note = await screen.findByTestId('dashboard-store-assumed-tz')
    expect(note.textContent).toMatch(/4/)
    expect(note.textContent).toMatch(/UTC/)
  })

  /** With every store stating its zone, there is nothing to warn about. */
  it('shows no timezone warning when every store stated its zone', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(STORE) as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    await screen.findByTestId('dashboard-store')
    expect(screen.queryByTestId('dashboard-store-assumed-tz')).toBeNull()
  })

  /**
   * A failing STORE is named by its own name in the alert.
   *
   * «فشل مزامنة salla» names a platform where the operator connected «متجر العميل» — with two shops
   * on one platform, the operator cannot tell which one to go and fix.
   */
  it('names the store rather than its platform when a store sync fails', async () => {
    vi.mocked(useSummary).mockReturnValue(summary(null) as never)
    const row: FreshnessRow = {
      kind: 'store', provider: 'salla', account_id: 's1', name: 'متجر العميل',
      latest_metric_date: null, data_freshness_at: null, days_with_data: null, missing_days: null,
      last_sync_status: 'failed', last_sync_at: null, last_sync_error: 'boom',
    }
    // `useFreshness` now yields the envelope's two halves: the rows, and the scope the endpoint declined.
    vi.mocked(useFreshness).mockReturnValue({ data: { rows: [row], scope: undefined }, isLoading: false, isError: false } as never)

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    expect(await screen.findByText(/متجر العميل/)).toBeInTheDocument()
  })
})
