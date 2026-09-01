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
 * Production served a live link whose subtitle read «تقرير تفصيلي — كل المنصات والحملات والإعلانات»
 * over the live DASHBOARD: a spend chart, a platform donut and a top-eight campaign bar. A client
 * who counted the bars found eight campaigns where the sentence promised all of them.
 *
 * A report is two independent facts — its MODE (live or snapshot) and its FORM (summary or detail) —
 * and the label only ever read the FORM. Four products, four sentences, and the composition has to
 * match the one it is given.
 */
const CAMPAIGNS = Array.from({ length: 11 }, (_, i) => ({
  campaign_name: `Campaign ${i + 1}`,
  provider: 'snapchat',
  spend: 1000 - i * 10,
  impressions: 90_000,
  clicks: 700,
  conversions: 12,
}))

const PAYLOAD = {
  period: { from: '2026-08-01', to: '2026-08-26', days: 26 },
  currency: 'SAR',
  totals: { spend: 5000, revenue: 0, impressions: 500_000, clicks: 9000, conversions: 120 },
  deltas: {},
  timeseries: [],
  platforms: [
    { provider: 'snapchat', spend: 3000, impressions: 300_000, clicks: 5000, conversions: 80 },
    { provider: 'meta', spend: 2000, impressions: 200_000, clicks: 4000, conversions: 40 },
  ],
  campaigns: CAMPAIGNS,
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
   * «Every campaign» has to mean every campaign.
   *
   * The dashboard's bar chart stops at eight because a chart with eleven bars is unreadable — a
   * ceiling on a drawing, not on the report. The eleventh campaign exists, and the detailed form is
   * where a client is entitled to find it.
   */
  it('renders every campaign and every platform for the detailed form', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const tables = await screen.findByTestId('live-detail-tables')
    expect(tables).toBeInTheDocument()

    const campaigns = within(screen.getByTestId('live-detail-campaigns'))
    expect(campaigns.getByText('Campaign 1')).toBeInTheDocument()
    // The row the dashboard's top-eight could never show.
    expect(campaigns.getByText('Campaign 11')).toBeInTheDocument()

    const platforms = within(screen.getByTestId('live-detail-platforms'))
    expect(platforms.getByText('Snapchat')).toBeInTheDocument()
    expect(platforms.getByText('Meta')).toBeInTheDocument()
  })

  /** Sortable, like every other analytical table in the product — it IS the same table. */
  it('lets the reader order the detail by any column', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const campaigns = within(await screen.findByTestId('live-detail-campaigns'))
    expect(campaigns.getByTestId('sort-1')).toBeInTheDocument()
  })

  /**
   * A spend in another currency is printed in THAT currency, and does not order the table.
   *
   * The money contract's rule, at a new surface: a withheld amount is shown as the original the
   * platform reported — «500.00 USD» — and never converted into the report's SAR, because no rate
   * was on file to convert it with. It is then sorted as an ABSENCE, since ranking a client's
   * campaigns by comparing 500 USD against 900 SAR is an order nobody can check.
   */
  it('prints an unconvertible spend in its own currency and does not sort by it', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...PAYLOAD,
          campaigns: [
            { campaign_name: 'Readable', provider: 'snapchat', spend: 900, impressions: 1000, clicks: 10, conversions: 1 },
            {
              campaign_name: 'Withheld', provider: 'meta', spend: null, impressions: 1000, clicks: 10, conversions: 1,
              spend_original: 500, spend_withheld_rows: 3, money_original_currency: 'USD', money_original_currencies: 1,
            },
          ],
        },
      },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const campaigns = within(await screen.findByTestId('live-detail-campaigns'))
    const rows = campaigns.getAllByRole('row').slice(1)

    // Descending by spend: the comparable figure first, the incomparable one last — never the reverse.
    expect(rows[0]).toHaveTextContent('Readable')
    expect(rows[1]).toHaveTextContent('Withheld')
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

    expect(within(await screen.findByTestId('live-detail-campaigns')).getByText(/No rows in this period/)).toBeInTheDocument()
  })

  /**
   * NUMBER-PRESENTATION-001 at the surface a CLIENT reads.
   *
   * The impressions column prints «90K» because the column is sixty pixels wide. A client deciding
   * which campaign to keep is entitled to the figure that abbreviation came from, and on a shared
   * link there is nobody to ask for it.
   */
  it('lets a client reach the exact figure behind an abbreviated one', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const campaigns = within(await screen.findByTestId('live-detail-campaigns'))
    const cell = campaigns.getAllByText('90K')[0]?.closest('td')

    expect(cell).toHaveAttribute('title', '90,000')
  })


  /**
   * REPORT-DETAIL-PARITY-001 — the rung between the campaign and the ad.
   *
   * A «detailed report» that stops at the campaign is a summary with a longer label. The ad set is
   * where the media buyer's decisions live: an audience, a placement, a budget split.
   */
  it('renders the ad-set rung for the detailed form', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...PAYLOAD,
          ad_sets: [
            { external_entity_id: 'as-1', name: 'Riyadh 25-44', spend: 2000, impressions: 90_000, clicks: 700, conversions: 12 },
            { external_entity_id: 'as-2', name: 'Jeddah 18-24', spend: 800, impressions: 40_000, clicks: 300, conversions: 4 },
          ],
        },
      },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const adSets = within(await screen.findByTestId('live-detail-ad-sets'))

    expect(adSets.getByText('Riyadh 25-44')).toBeInTheDocument()
    expect(adSets.getByText('Jeddah 18-24')).toBeInTheDocument()
  })

  /**
   * «We did not ask» and «they did not send» are different sentences, and this is the second.
   *
   * A heading over an empty table reads as a section that failed to load, which sends a client to
   * ask their agency about a sync problem that does not exist.
   */
  it('says the platforms reported no ad-set level rather than drawing an empty heading', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(within(await screen.findByTestId('live-detail-ad-sets')).getByText(/reported no ad-set level/))
      .toBeInTheDocument()
  })

  /** And the summary form shows none of it — the rung belongs to the detailed product. */
  it('keeps the ad-set rung out of the dashboard', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(screen.queryByTestId('live-detail-ad-sets')).not.toBeInTheDocument()
  })

})
