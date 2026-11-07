import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveSharedReport } from '../LiveSharedReport'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchLiveShared: vi.fn(),
  fetchLiveContent: vi.fn(),
}))

import { fetchLiveShared } from '../api'

/**
 * REPORT-WEBSITE-TAB-001 — one home for everything about the website: the «الموقع» tab.
 *
 * The Owner, 2026-11-07, after opening a real live link: «نفس الإحصائيات مكررة في عدة صفحات وهذا غير منطقي أبدًا».
 * A website figure appears in EXACTLY ONE tab (the summary carries one small headline strip that opens the tab), the platform
 * funnel is labelled as the ad platforms' own, and the word «GA4» never appears.
 */
const tile = (key: string, kind: string, current: number | null, usd?: number) => ({ key, kind, current, previous: null, change_pct: null, currency: kind === 'money' ? 'SAR' : null, trend: [], ...(usd !== undefined ? { current_usd: usd, previous_usd: null } : {}) })
const KPIS = [
  tile('sessions', 'count', 36361), tile('users_daily_sum', 'count', 33552), tile('new_users', 'count', 29052), tile('bounce_rate', 'rate', 0.708),
  tile('engagement_rate', 'rate', 0.285), tile('avg_session_seconds', 'seconds', 54), tile('transactions', 'count', 410), tile('revenue', 'money', 90000, 24000),
  tile('aov', 'money', 220, 58), tile('conversion_rate', 'rate', 0.0113), tile('add_to_carts', 'count', 8960), tile('checkouts', 'count', 13400),
]
const sources = Array.from({ length: 12 }, (_, i) => ({ name: `src-${i}`, sub: 'cpc', sessions: 100 - i, engagement_rate: 0.5, purchases: 2, conversion_rate: 0.02, revenue: 10, revenue_usd: 2.5 }))

function payload(over: Record<string, unknown> = {}) {
  return {
    period: { from: '2026-10-01', to: '2026-10-30', days: 30 },
    currency: 'USD',
    totals: { spend: 1000, conversions: 50, impressions: 100000, clicks: 3000, revenue: 3000 },
    deltas: {},
    timeseries: [{ date: '2026-10-29', spend: 500, clicks: 1500, conversions: 25, impressions: 50000 }, { date: '2026-10-30', spend: 500, clicks: 1500, conversions: 25, impressions: 50000 }],
    platform_series: { meta: [{ date: '2026-10-29', clicks: 1000 }, { date: '2026-10-30', clicks: 1000 }], snapchat: [{ date: '2026-10-29', clicks: 500 }, { date: '2026-10-30', clicks: 500 }] },
    platforms: [{ provider: 'meta', spend: 700, impressions: 70000, clicks: 2000, conversions: 40, ctr: 0.03 }, { provider: 'snapchat', spend: 300, impressions: 30000, clicks: 1000, conversions: 10, ctr: 0.03 }],
    campaigns: [],
    funnel: [
      { stage: 'impressions', label: 'Impressions', reported: true, count: 100000, from_stage: null, step_rate: null, cost_per: null },
      { stage: 'add_to_cart', label: 'Add to Cart', reported: true, count: 8960, from_stage: 'impressions', step_rate: 0.08, cost_per: null },
      { stage: 'checkout', label: 'Checkout', reported: true, count: 13400, from_stage: 'add_to_cart', step_rate: 1.5, cost_per: null, exceeds_previous: true },
    ],
    ads: [], ads_weakest: [], ads_groups: [], ads_roster: [],
    results: { configured: false, total: null, cost: null, by_path: {}, events: {}, by_platform: {} },
    sections: { attribution: false }, store_funnel: null,
    // The website is NOT an ad platform: it is in neither the chips nor the freshness list the banner reads.
    freshness: [{ kind: 'ad_platform', provider: 'ga4', name: null, state: 'awaiting_credentials' }, { kind: 'ad_platform', provider: 'meta', name: null, state: 'synced' }],
    available: { providers: ['meta', 'snapchat', 'ga4'], campaigns: [], earliest: '2026-01-01', latest: '2026-10-30' },
    metrics: [], applied: { from: '2026-10-01', to: '2026-10-30', providers: [], campaigns: [] },
    report_views: ['summary', 'dashboard', 'platforms', 'content', 'website', 'tables'],
    report_sections: ['kpis', 'trends', 'platform_comparison', 'funnel', 'content_performance', 'daily_records', 'ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables', 'ga4_pulse'],
    ga4_pulse: { currency: 'SAR', usd: null, period: { from: '2026-10-01', to: '2026-10-30' }, kpis: KPIS.filter((k) => ['sessions', 'transactions', 'revenue', 'conversion_rate'].includes(k.key)), funnel: [] },
    ga4_overview: { currency: 'SAR', usd: { available: true, from: 'SAR', rate: 0.27 }, period: { from: '2026-10-01', to: '2026-10-30' }, previous_period: { from: '2026-09-01', to: '2026-09-30' }, property: { name: 'https://example.com/' }, last_synced_at: null, kpis: KPIS, series: [] },
    ga4_acquisition: {
      currency: 'SAR',
      channels: [{ key: 'Direct', sessions: 12230, purchases: null, revenue: null, revenue_usd: null, share: 0.3364 }, { key: 'unclassified', sessions: 24131, purchases: null, revenue: null, revenue_usd: null, share: 0.6636 }],
      devices: [{ key: 'mobile', sessions: 36361, purchases: null, revenue: null, revenue_usd: null, share: 1 }],
      channels_meta: { total: 36361, classified: 12230, unclassified: 24131, rescaled: false, days_covered: 3, days_total: 30 },
      devices_meta: { total: 36361, classified: 36361, unclassified: 0, rescaled: false, days_covered: 30, days_total: 30 },
      funnel: [{ stage: 'sessions', count: 36361, step_rate: null, exceeds_previous: false }, { stage: 'add_to_carts', count: 8960, step_rate: 0.2464, exceeds_previous: false }],
    },
    ga4_platform_gap: { summed: false, currency: 'SAR', rows: [], unpaid: [{ class: 'organic', sessions: 400, purchases: 8, revenue: 1600, currency: 'SAR', revenue_usd: 400, session_share: 0.4 }], other_paid: null, ga4_sessions: 36361 },
    ga4_tables: { currency: 'SAR', tables: { sources: { rows: sources, total: 12 } } },
    ...over,
  }
}

const serve = (over: Record<string, unknown> = {}) => vi.mocked(fetchLiveShared).mockImplementation(async () => ({ status: 200, envelope: { data: payload(over) } }) as never)
const open = async (mode: string) => {
  fireEvent.click(await screen.findByTestId(`live-mode-tab-${mode}`))
  return screen.findByTestId(`live-mode-${mode}`)
}

describe('the «الموقع» tab', () => {
  beforeEach(() => serve())
  afterEach(() => vi.clearAllMocks())

  it('is its own tab, between the content and the tables', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    await screen.findByTestId('live-mode-summary')
    const tabs = within(screen.getByTestId('live-modes')).getAllByRole('tab').map((t) => t.getAttribute('data-testid'))
    expect(tabs).toEqual(['live-mode-tab-summary', 'live-mode-tab-dashboard', 'live-mode-tab-platforms', 'live-mode-tab-content', 'live-mode-tab-website', 'live-mode-tab-tables'])
    expect(screen.getByTestId('live-mode-tab-website')).toHaveTextContent('Website')
  })

  it('shows a website figure in exactly one tab', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-mode-summary')

    const where: Record<string, number> = {}
    for (const mode of ['summary', 'dashboard', 'platforms', 'content', 'tables', 'website']) {
      await open(mode)
      // The tiles, the acquisition donuts, the attribution table and the sources table are the website's figures.
      where[mode] = ['ga4-tiles', 'ga4-channels', 'ga4-devices', 'ga4-funnel', 'ga4-table-tab-sources', 'ga4-gap'].reduce((n, id) => n + screen.queryAllByTestId(id).length, 0)
    }

    expect(where).toEqual({ summary: 0, dashboard: 0, platforms: 0, content: 0, tables: 0, website: 6 })
  })

  it('carries ONE small headline strip on the summary that opens the tab', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    const summary = await screen.findByTestId('live-mode-summary')
    const strip = screen.getByTestId('website-strip')
    expect(within(strip).getByTestId('website-strip-sessions')).toHaveTextContent('36,361')
    expect(within(strip).getByTestId('website-strip-conversion_rate')).toBeInTheDocument()
    expect(within(strip).getAllByTestId(/^website-strip-(sessions|transactions|revenue|conversion_rate)$/).length).toBeLessThanOrEqual(4)
    expect(within(summary).queryByTestId('ga4-tiles')).toBeNull()
    expect(screen.queryByTestId('ga4-tiles')).toBeNull()

    fireEvent.click(within(strip).getByTestId('website-strip-open'))
    expect(await screen.findByTestId('live-mode-website')).toBeInTheDocument()
  })

  it('draws, in order: key figures, channels / devices / funnel, the website against the platforms, the tables', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const website = await open('website')

    const order = ['report-ga4-overview', 'report-ga4-acquisition', 'report-ga4-gap', 'report-ga4-tables']
    const at = order.map((id) => Array.from(website.querySelectorAll('[data-testid]')).findIndex((n) => n.getAttribute('data-testid') === id))
    expect(at.every((i) => i >= 0)).toBe(true)
    expect([...at].sort((a, b) => a - b)).toEqual(at)
    // The twelve tiles, one source per figure.
    const ids = Array.from(within(website).getByTestId('ga4-tiles').querySelectorAll('[data-testid^="ga4-tile-"]')).map((n) => n.getAttribute('data-testid'))
    for (const k of ['sessions', 'users_daily_sum', 'new_users', 'bounce_rate', 'engagement_rate', 'avg_session_seconds', 'transactions', 'revenue', 'aov', 'conversion_rate', 'add_to_carts', 'checkouts']) expect(ids).toContain(`ga4-tile-${k}`)
  })

  it('names the non-campaign sales clearly: «outside campaigns» with its sub-chips', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const website = await open('website')

    const outside = within(website).getByTestId('outside-campaigns')
    expect(outside).toHaveTextContent('Outside campaigns')
    expect(within(outside).getByTestId('outside-organic')).toHaveTextContent('Free')
    expect(website).not.toHaveTextContent(/No platform trace/i)
  })

  it('reconciles the breakdowns: the donut centre is the visits figure, an unclassified slice and its coverage say why', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const website = await open('website')

    expect(within(website).getByTestId('ga4-tile-sessions')).toHaveTextContent('36,361')
    expect(within(website).getByTestId('ga4-channels')).toHaveAttribute('data-slices', '2')
    expect(within(website).getByTestId('ga4-channels')).toHaveTextContent('36,361')
    expect(within(website).getByTestId('ga4-devices')).toHaveTextContent('36,361')
    const coverage = within(website).getByTestId('ga4-channels-coverage')
    expect(coverage).toHaveTextContent('Unclassified')
    expect(coverage).toHaveTextContent('3/30')
    expect(within(website).queryByTestId('ga4-devices-coverage')).toBeNull()
  })

  it('says nothing of the word GA4 or a raw key in any tab, and keeps the website out of the platform chips and the banner', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    await screen.findByTestId('live-mode-summary')

    expect(screen.queryByTestId('live-platform-ga4')).toBeNull()
    expect(screen.getByTestId('live-platform-meta')).toBeInTheDocument()
    expect(screen.queryByTestId('live-freshness')).toBeNull()
    for (const mode of ['summary', 'dashboard', 'platforms', 'content', 'tables', 'website']) {
      const view = await open(mode)
      expect(view.textContent ?? '', mode).not.toMatch(/ga4/i)
    }
    expect(document.body.textContent ?? '').not.toMatch(/ga4/i)
  })

  it('labels the platform funnel as the ad platforms own and shows «definitions differ» instead of 150%', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const dashboard = await open('dashboard')

    const funnel = within(dashboard).getByTestId('live-funnel')
    expect(funnel).toHaveTextContent('Ad platforms funnel')
    expect(within(funnel).getByTestId('funnel-definitions-differ')).toHaveTextContent('Platform definitions differ')
    expect(funnel.textContent ?? '').not.toMatch(/150%/)
  })

  it('has no website tab when the link carries no website data', async () => {
    serve({ report_sections: ['kpis', 'trends', 'platform_comparison', 'funnel', 'content_performance', 'daily_records'], ga4_overview: null, ga4_acquisition: null, ga4_platform_gap: null, ga4_tables: null, ga4_pulse: null })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    await screen.findByTestId('live-mode-summary')
    expect(screen.queryByTestId('live-mode-tab-website')).toBeNull()
    expect(screen.queryByTestId('website-strip')).toBeNull()
  })

  it('removes a hidden part: no tiles when the overview is hidden, the rest stays', async () => {
    serve({ report_sections: ['kpis', 'funnel', 'ga4_acquisition', 'ga4_tables', 'ga4_pulse'], ga4_overview: null })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const website = await open('website')

    expect(within(website).queryByTestId('ga4-tiles')).toBeNull()
    expect(within(website).getByTestId('ga4-channels')).toBeInTheDocument()
  })

  it('draws one comparison and one products table: the match replaces the older website-vs-platforms card, the products part owns products', async () => {
    serve({
      report_sections: ['kpis', 'funnel', 'ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_attribution', 'ga4_tables', 'ga4_products', 'ga4_pulse'],
      ga4_attribution: {
        state: 'ready', basis: 'ga4', order_level: false, summed: false,
        platforms: [{ platform: 'meta', claimed: { conversions: 20, revenue_usd: 100 }, ga4: { sessions: 100, purchases: 8, revenue_usd: 50, aov_usd: 6 }, confidence: 'utm', matched_share: 0.5 }],
        unattributed: { sessions: 300, classes: [{ class: 'organic', sessions: 300 }] },
      },
      ga4_tables: { currency: 'SAR', tables: { sources: { rows: sources, total: 12 }, products: { rows: [{ name: 'Tee', views: 1, carts: 1, purchased: 1, revenue: 10, revenue_usd: 2.5 }], total: 1 } } },
      ga4_products: { currency: 'SAR', table: { rows: [{ name: 'Tee', views: 1, carts: 1, purchased: 1, revenue: 10, revenue_usd: 2.5 }], total: 1 } },
    } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })
    const website = await open('website')

    expect(within(website).getByTestId('report-ga4-attribution')).toBeInTheDocument()
    expect(within(website).queryByTestId('report-ga4-gap')).toBeNull()
    expect(within(website).getByTestId('report-ga4-products')).toBeInTheDocument()
    expect(within(website).getByTestId('ga4-table-tab-sources')).toBeInTheDocument()
    expect(within(website).queryByTestId('ga4-table-tab-products')).toBeNull()
    // The umbrella is drawn once.
    expect(within(website).getAllByTestId('outside-campaigns')).toHaveLength(1)
  })
})
