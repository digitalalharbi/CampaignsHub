import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LiveSharedReport } from './LiveSharedReport'
import { productLabel, productName } from './reportProduct'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchLiveShared: vi.fn(),
}))

import { fetchLiveShared } from './api'

/**
 * REPORT-PRODUCT-MODEL-001 §D §E — the label and the page have to be describing the same document.
 *
 * Production served a live link whose subtitle promised the whole window over the live DASHBOARD: a
 * spend chart, a platform donut and a top-eight bar. A client who counted the bars found eight rows
 * where the sentence promised all of them.
 *
 * A report is two independent facts — its MODE (live or snapshot) and its FORM (summary or detail) —
 * and the label only ever read the FORM. Four products, four sentences, and the composition has to
 * match the one it is given.
 *
 * What «detailed» CONTAINS changed under CLIENT-REPORT-ENTITY-BOUNDARY-001: it was a campaign table
 * and an ad-set table — the agency's campaign names, and below them the targeting plan — and it is
 * now every platform and every objective. So this file holds two claims at once: that the detailed
 * form renders more than the dashboard, and that what it renders more of is not the campaign plan.
 */
/**
 * Eleven platforms — more than the dashboard's chart can draw.
 *
 * The bar chart stops at eight because a chart with eleven bars is unreadable: a ceiling on a
 * drawing, not on the report. The eleventh row exists, and the detailed form is where a client is
 * entitled to find it. The list is long rather than realistic on purpose; the claim is about the
 * absence of a slice.
 */
const PLATFORMS = [
  'snapchat', 'meta', 'google', 'tiktok', 'linkedin',
  'x', 'pinterest', 'reddit', 'quora', 'spotify', 'apple_search_ads',
].map((provider, i) => ({
  provider,
  spend: 3000 - i * 10,
  impressions: 90_000,
  clicks: 700,
  conversions: 12,
}))

/** The axis that replaced the campaign roster: what the money was bought for, and what that cost. */
const OBJECTIVE_PERFORMANCE = {
  paths: [
    {
      path: 'conversion', label_ar: 'التحويل والمبيعات', label_en: 'Conversion & sales',
      headline_metrics: [], spend: 4000, impressions: 200_000, clicks: 5000, orders: 40, revenue: 90_000,
      cpm: null, cpc: null, ctr: null, cpa: 100, roas: 22.5, result_metrics_apply: true, campaigns: [],
    },
    {
      path: 'awareness', label_ar: 'الوعي', label_en: 'Awareness',
      headline_metrics: [], spend: 1000, impressions: 300_000, clicks: 4000, orders: 0, revenue: 0,
      cpm: null, cpc: null, ctr: null, cpa: null, roas: null, result_metrics_apply: false, campaigns: [],
    },
    {
      path: 'traffic', label_ar: 'الاهتمام والزيارات', label_en: 'Interest & traffic',
      headline_metrics: [], spend: 0, impressions: 0, clicks: 0, orders: 0, revenue: 0,
      cpm: null, cpc: null, ctr: null, cpa: null, roas: null, result_metrics_apply: false, campaigns: [],
    },
  ],
  direct: {
    label_ar: '', label_en: '', spend: 4000, orders: 40, revenue: 90_000, cpa: 100, roas: 22.5, aov: 2250,
    formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [],
  },
  blended: {
    label_ar: '', label_en: '', spend: 5000, orders: 40, revenue: 90_000,
    blended_cpa: 125, blended_roas: 18, formula: { blended_cpa: '', blended_roas: '' },
    includes_non_sales_spend: 1000,
  },
}

const PAYLOAD = {
  period: { from: '2026-08-01', to: '2026-08-26', days: 26 },
  currency: 'SAR',
  totals: { spend: 5000, revenue: 0, impressions: 500_000, clicks: 9000, conversions: 120 },
  deltas: {},
  timeseries: [],
  platforms: PLATFORMS,
  campaigns: [],
  objective_performance: OBJECTIVE_PERFORMANCE,
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['snapchat', 'meta'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-26' },
  metrics: [],
  applied: { from: '2026-08-01', to: '2026-08-26', providers: [], campaigns: [] },
  is_demo: false,
}

describe('the four report products', () => {
  it('names each of the four, and never describes a live page as a snapshot', () => {
    expect(productName('live', 'executive_summary', 'en')).toBe('Live dashboard')
    expect(productName('live', 'detailed', 'en')).toBe('Live detailed report')
    expect(productName('snapshot', 'executive_summary', 'en')).toBe('Executive summary')
    expect(productName('snapshot', 'detailed', 'en')).toBe('Detailed report')

    /*
     * The exact sentence production printed over the live dashboard. A live page may not promise a
     * fixed document's contents, because it is not one and does not render one.
     */
    expect(productLabel('live', 'detailed', 'en')).not.toContain('every platform, campaign and creative')

    /*
     * CLIENT-REPORT-ENTITY-BOUNDARY-001 — and no product's sentence promises a campaign any more.
     *
     * A label is a contract with the reader whether or not anybody remembered to update it, and a
     * detailed report that still said «every platform, campaign and creative» would be promising the
     * roster this requirement removed.
     */
    for (const form of ['executive_summary', 'detailed'] as const) {
      for (const mode of ['live', 'snapshot'] as const) {
        expect(productLabel(mode, form, 'en').toLowerCase()).not.toContain('campaign')
        expect(productLabel(mode, form, 'ar')).not.toContain('حمل')
      }
    }
    expect(productLabel('live', 'executive_summary', 'ar')).toContain('لوحة مباشرة')
    expect(productLabel('snapshot', 'detailed', 'ar')).toContain('تقرير تفصيلي')
  })
})

describe('a live link and the form it was shared as', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: PAYLOAD } } as never)
  })
  afterEach(() => vi.clearAllMocks())

  it('renders the dashboard alone for the summary form', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(screen.queryByTestId('live-detail-tables')).not.toBeInTheDocument()
  })

  /**
   * «Every platform» has to mean every platform.
   *
   * The dashboard's bar chart stops at eight because a chart with eleven bars is unreadable — a
   * ceiling on a drawing, not on the report. The eleventh row exists, and the detailed form is where
   * a client is entitled to find it.
   */
  it('renders every platform for the detailed form', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const tables = await screen.findByTestId('live-detail-tables')
    expect(tables).toBeInTheDocument()

    const platforms = within(screen.getByTestId('live-detail-platforms'))
    expect(platforms.getByText('Snapchat')).toBeInTheDocument()
    expect(platforms.getByText('Meta')).toBeInTheDocument()
    // The row the dashboard's top-eight could never show.
    expect(platforms.getAllByRole('row')).toHaveLength(PLATFORMS.length + 1)
  })

  /**
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — «detailed» is more analysis, not more of the campaign plan.
   *
   * The two tables that stood here were the agency's own campaign names and, one rung below them,
   * the targeting plan in plain words. Neither is a fact about the client's advertising; both are
   * facts about how it was arranged. The reader gets the objective split instead, which is the axis
   * their money is actually judged on and one they can act on.
   */
  it('never renders the campaign or ad-set rungs to a client', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')
    await screen.findByTestId('live-detail-tables')

    expect(screen.queryByTestId('live-detail-campaigns')).not.toBeInTheDocument()
    expect(screen.queryByTestId('live-detail-ad-sets')).not.toBeInTheDocument()
    // Nor the picker that offered «all campaigns» over a list of their names.
    expect(screen.queryByTestId('live-campaign')).not.toBeInTheDocument()
  })

  /** What replaces them: every objective, with the cost per result each one is judged on. */
  it('renders every objective the money was spent on', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const objectives = within(await screen.findByTestId('live-detail-objectives'))

    expect(objectives.getByText('Conversion & sales')).toBeInTheDocument()
    expect(objectives.getByText('Awareness')).toBeInTheDocument()
    // A path nothing was spent on is an absence, not a row of zeroes.
    expect(objectives.queryByText('Interest & traffic')).not.toBeInTheDocument()
  })

  /**
   * A cost per result belongs to the path that was bought for one.
   *
   * An awareness path has no cost per order, and printing «—» there is the report declining to rank
   * a brand path on a sales metric. Printing a number would be inventing one.
   */
  it('states a cost per result only where the objective has one', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const objectives = within(await screen.findByTestId('live-detail-objectives'))
    const rows = objectives.getAllByRole('row').slice(1)
    const sales = rows.find((r) => r.textContent?.includes('Conversion & sales'))
    const awareness = rows.find((r) => r.textContent?.includes('Awareness'))

    expect(sales).toHaveTextContent('100')
    expect(awareness).toHaveTextContent('—')
  })

  /** Sortable, like every other analytical table in the product — it IS the same table. */
  it('lets the reader order the detail by any column', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const platforms = within(await screen.findByTestId('live-detail-platforms'))
    expect(platforms.getByTestId('sort-1')).toBeInTheDocument()
  })

  /**
   * A spend in another currency is printed in THAT currency, and does not order the table.
   *
   * The money contract's rule, at a new surface: a withheld amount is shown as the original the
   * platform reported — «500.00 USD» — and never converted into the report's SAR, because no rate
   * was on file to convert it with. It is then sorted as an ABSENCE, since ranking a client's
   * platforms by comparing 500 USD against 900 SAR is an order nobody can check.
   */
  it('prints an unconvertible spend in its own currency and does not sort by it', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...PAYLOAD,
          platforms: [
            { provider: 'snapchat', spend: 900, impressions: 1000, clicks: 10, conversions: 1 },
            {
              provider: 'meta', spend: null, impressions: 1000, clicks: 10, conversions: 1,
              spend_original: 500, spend_withheld_rows: 3, money_original_currency: 'USD', money_original_currencies: 1,
            },
          ],
        },
      },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const platforms = within(await screen.findByTestId('live-detail-platforms'))
    const rows = platforms.getAllByRole('row').slice(1)

    // Descending by spend: the comparable figure first, the incomparable one last — never the reverse.
    expect(rows[0]).toHaveTextContent('Snapchat')
    expect(rows[1]).toHaveTextContent('Meta')
    expect(rows[1]).toHaveTextContent('500.00 USD')
    // And never that figure wearing this report's currency.
    expect(rows[1]).not.toHaveTextContent('500.00 SAR')
  })

  /** An empty window says so rather than showing a heading over nothing. */
  it('says when there is nothing in the window instead of drawing an empty table', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: { data: { ...PAYLOAD, campaigns: [], platforms: [] } },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(within(await screen.findByTestId('live-detail-platforms')).getByText(/No rows in this period/)).toBeInTheDocument()
  })

  /** And a window where nothing was bought for anything says THAT, not «no rows». */
  it('says nothing was spent on any objective rather than drawing an empty table', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: { data: { ...PAYLOAD, objective_performance: { ...OBJECTIVE_PERFORMANCE, paths: [] } } },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(within(await screen.findByTestId('live-detail-objectives')).getByText(/Nothing was spent on any objective/))
      .toBeInTheDocument()
  })

  /**
   * NUMBER-PRESENTATION-001 at the surface a CLIENT reads.
   *
   * The impressions column prints «90K» because the column is sixty pixels wide. A client deciding
   * where to keep spending is entitled to the figure that abbreviation came from, and on a shared
   * link there is nobody to ask for it.
   */
  it('lets a client reach the exact figure behind an abbreviated one', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const platforms = within(await screen.findByTestId('live-detail-platforms'))
    const cell = platforms.getAllByText('90K')[0]?.closest('td')

    expect(cell).toHaveAttribute('title', '90,000')
  })


  /**
   * The boundary holds on the DASHBOARD too, which is the form most links are shared as.
   *
   * The campaign ranking chart lived here rather than in the detail tables, so a fix applied only to
   * the detailed form would have left the roster on screen for every summary link in the field.
   */
  it('shows a client no campaign roster on the dashboard either', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(screen.queryByTestId('live-campaigns')).not.toBeInTheDocument()
    expect(screen.queryByTestId('live-campaign')).not.toBeInTheDocument()
    expect(screen.queryByTestId('live-detail-ad-sets')).not.toBeInTheDocument()
  })

})
