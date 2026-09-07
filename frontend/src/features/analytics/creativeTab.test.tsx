import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-CREATIVE-VISIBLE-001 — the last rung of the drill-down.
 *
 * Figures come from `creative_daily_metrics` only. A creative the platform does not break out shows
 * «—», because inventing its share of a campaign total would be a number nobody measured.
 */
const CREATIVE = {
  id: 'cr1',
  name: 'Summer hero',
  campaign_name: 'Always-On',
  objective: 'sales',
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-22T00:00:00Z' },
  metrics: {
    spend: null,
    spend_original: 412.5,
    spend_withheld_rows: 3,
    money_original_currency: 'USD',
    money_original_currencies: 1,
    impressions: 90000,
    clicks: 300,
    ctr: 0.00333,
  },
}

const requested: string[] = []

function route(creatives: unknown[]) {
  requested.length = 0
  vi.mocked(getData).mockImplementation(async (url: string) => {
    requested.push(url)
    if (url.includes('/creatives')) return { creatives, currency: 'SAR', page: 1, per_page: 24, total: creatives.length, period: { from: '', to: '' }, filters: {} } as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 1, demo_rows: 0 } } as never
    if (url.includes('disclaimer')) return null as never
    return [] as never
  })
}

async function openCreative() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  /*
   * ADS-TERMINOLOGY-001 — this tab is «Content performance» now, not «Ad».
   *
   * It was named for the wrong entity: it is the last rung of campaign → ad set → ad → CONTENT, and
   * calling it «Ad» put it beside «Ads» as an apparent duplicate. The surface and everything it
   * asserts are unchanged; only the word a reader clicks is.
   */
  fireEvent.click(await screen.findByRole('tab', { name: /Content/ }))
}

/**
 * ANALYTICS-CREATIVE-SCOPE-001 — the tab took only `projectId` and `range`.
 *
 * So selecting TikTok left it listing META creatives with Meta's figures, under a filter bar that
 * said TikTok. The filter was not weak here, it was decorative — and a table that contradicts the
 * control above it is worse than an empty one, because the reader cannot tell which is lying.
 *
 * Asserted on the REQUEST rather than on the rows: what matters is that the choice reaches the
 * server, and a fixture that returns the same rows either way would pass a row assertion.
 */
describe('the content performance tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows a content item with its campaign, objective and figures', async () => {
    route([CREATIVE])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('Summer hero')
    expect(table).toHaveTextContent('Always-On')
    /* NUMBER-PRESENTATION-001 — abbreviated, like every other count in the product. */
    expect(table).toHaveTextContent('90K')
  })

  /** Withheld spend keeps its own currency here too — one money contract across every surface. */
  it('states withheld content spend in its original currency', async () => {
    route([CREATIVE])

    await openCreative()

    expect(await screen.findByTestId('creative-analysis-table')).toHaveTextContent('412.50 USD')
  })

  /** A creative the platform does not break out shows «—», never a share of the campaign. */
  it('prints a dash rather than inventing a content-level figure', async () => {
    route([{ ...CREATIVE, metrics: { ...CREATIVE.metrics, impressions: null, clicks: null, ctr: null } }])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('—')
    expect(table).not.toHaveTextContent('0.00%')
  })
})

describe('the ad tab and the filter bar', () => {
  it('sends the chosen platform to the server instead of ignoring it', async () => {
    route([])
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    /*
   * ADS-TERMINOLOGY-001 — this tab is «Content performance» now, not «Ad».
   *
   * It was named for the wrong entity: it is the last rung of campaign → ad set → ad → CONTENT, and
   * calling it «Ad» put it beside «Ads» as an apparent duplicate. The surface and everything it
   * asserts are unchanged; only the word a reader clicks is.
   */
  fireEvent.click(await screen.findByRole('tab', { name: /Content/ }))

    await waitFor(() => expect(requested.some((u) => u.includes('/creatives'))).toBe(true))

    fireEvent.click(screen.getByTestId('analytics-platform-tiktok'))

    // The library speaks `providers`; the metrics API speaks `provider`. The translation is the
    // point of the fix, so the assertion names the library's spelling.
    await waitFor(() => {
      const creativeCalls = requested.filter((u) => u.includes('/creatives'))
      expect(creativeCalls.join(' | ')).toContain('providers')
    })
  })
})

/**
 * CONTENT-TERMINOLOGY-001 — a surface named for content must be written in content's words.
 *
 * The tab was renamed «أداء المحتويات» / «Content performance» when the ads/content duplication was
 * corrected, and everything INSIDE it kept the old vocabulary: the panel said «أداء الإعلانات», the
 * first column «الإعلان», the description «from ad-level data», and the narrowed empty state that no
 * AD was reported.
 *
 * That is the same defect the rename was for, one level down — and here it is worse, because these
 * figures come from `creative_daily_metrics` at the CONTENT grain. One creative can be carried by
 * several ads, so heading its row «الإعلان» tells a reader they are looking at one ad's numbers when
 * they are looking at a content item's.
 *
 * Both languages, because the copy is written separately in each and can be wrong in one alone.
 * The PARENT is still an ad, and the empty state still says so — that part is not a slip.
 */
describe('the content surface is written in content’s words', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  const open = async (locale: 'ar' | 'en') => {
    renderWithProviders(<AnalyticsPage />, { locale, route: '/agency/analytics?tab=creative' })

    return screen.findByRole('table')
  }

  it.each([
    ['en', 'Content performance', 'Content', /content-level data/i],
    ['ar', 'أداء المحتويات', 'المحتوى', /من بيانات المحتوى/],
  ] as const)('titles the panel and its first column for content — %s', async (locale, title, column, description) => {
    route([CREATIVE])

    const table = await open(locale)

    /*
     * Somewhere other than the tab. Both now read «Content performance», which is the point — so a
     * bare `getByText` finds two and fails on ambiguity rather than on the product.
     */
    const titled = screen.getAllByText(title).filter((el) => el.closest('[role="tab"]') === null)

    expect(titled.length, 'the panel is still titled for ads').toBeGreaterThan(0)
    expect(screen.getByText(description), 'the description still describes ad-level data').toBeInTheDocument()

    const first = table.querySelector('thead th')?.textContent ?? ''

    expect(first, `the first column reads «${first}» over content rows`).toContain(column)
  })

  /**
   * The narrowed empty state names what is missing — content — and what it was looked for under.
   *
   * «No ad was reported» under a content surface is wrong twice: the thing absent is content, and a
   * reader who drilled into an ad already knows the ad exists. It is not «no content for the
   * project» either, which is the distinction this sentence has always been careful about.
   */
  it.each([
    ['en', /No content was reported under the selected ad or ad set/i],
    ['ar', /لا يوجد محتوى مسجَّل تحت الإعلان أو المجموعة المختارة/],
  ] as const)('says no CONTENT was reported under the chosen ad — %s', async (locale, sentence) => {
    route([])

    renderWithProviders(<AnalyticsPage />, {
      locale,
      /* Narrowed: a drill path pinned to an ad is what makes this the «under this parent» state. */
      route: '/agency/analytics?tab=creative&drill=ad%3Aext-a1',
    })

    const empty = await screen.findByTestId('creative-empty-under-parent')

    expect(empty.textContent ?? '', 'the empty state still says no AD was reported').toMatch(sentence)
  })
})
