import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'

/*
 * Only the branding lookup goes through axios; everything the live page asks for goes through
 * `fetch`, so the network is the global `fetch` and every request to it is counted by URL.
 */
vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  sharedBranding: vi.fn().mockResolvedValue({ name: 'Nakheel', logo_url: null, logo_source: 'none', by: null }),
}))

import { PublicReport } from '../PublicReport'

/**
 * PERF-REFETCH-001 — the live link asks for its figures ONCE, and opening a content does not ask again.
 *
 * `/r/:token` is a public, rationed endpoint read by a client with no session. Every extra request
 * to `/live` is a second computation of the whole report, and a page that recomputes everything
 * because a dialog opened is the defect this holds the line against: the content drill-down asks
 * for ITS trend (`/live/content/<key>`) and nothing else.
 */
const ad = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03, roas: 3.2, cpa: spend / 12,
})

const roster = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, format: 'image', metrics: { spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03 },
})

function livePayload() {
  const rows = [
    { provider: 'meta', spend: 700, impressions: 70_000, clicks: 2100, conversions: 40, cpa: 17.5, ctr: 0.03 },
    { provider: 'snapchat', spend: 300, impressions: 30_000, clicks: 900, conversions: 10, cpa: 30, ctr: 0.03 },
  ]

  return {
    period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
    currency: 'SAR',
    totals: { spend: 1000, conversions: 50, impressions: 100_000, clicks: 3000, revenue: 3000, cpa: 20, ctr: 0.03, cpc: 0.33, cpm: 10 },
    deltas: { spend: 0.1, conversions: -0.05 },
    timeseries: [{ date: '2026-09-15', spend: 500, clicks: 1500, conversions: 25 }, { date: '2026-09-16', spend: 500, clicks: 1500, conversions: 25 }],
    platforms: rows,
    campaigns: [],
    funnel: [{ stage: 'impressions', label: 'Impressions', reported: true, count: 100_000, from_stage: null, step_rate: null, cost_per: null }],
    objective_performance: {
      paths: [],
      direct: { label_ar: 'مباشر', label_en: 'Direct', spend: 600, orders: 40, revenue: 0, cpa: 15, roas: null, aov: null, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
      blended: { label_ar: 'مدمج', label_en: 'Blended', spend: 1000, orders: 40, revenue: 0, blended_cpa: 25, blended_roas: null, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 400 },
    },
    ads: rows.map((r) => ad(`Best on ${r.provider}`, r.provider, `key-best-${r.provider}`, r.spend)),
    ads_weakest: rows.map((r) => ad(`Weak on ${r.provider}`, r.provider, `key-weak-${r.provider}`, 5)),
    ads_groups: [],
    ads_roster: rows.map((r) => ({ ...roster(`Roster ${r.provider}`, r.provider, `key-roster-${r.provider}`, r.spend), verified_result: { metric: 'purchases', path: null, value: 12 } })),
    results: {
      configured: true, total: 50, cost: 20, by_path: {}, events: { meta: { all: 'purchases' }, snapchat: { all: 'purchases' } },
      by_platform: Object.fromEntries(rows.map((r) => [r.provider, { results: r.conversions, metric: 'purchases', spend: r.spend, cost: r.cpa }])),
    },
    creatives_in_scope: rows.length,
    budget: [],
    objective_leaders: { paths: [] },
    sections: { attribution: false },
    store_funnel: null,
    freshness: [],
    available: { providers: ['meta', 'snapchat'], campaigns: [], earliest: '2026-01-01', latest: '2026-09-16' },
    metrics: [],
    applied: { from: '2026-08-18', to: '2026-09-16', providers: [], campaigns: [] },
  }
}

const report = {
  name: 'Q3', currency: 'SAR', is_demo: false, generated_at: '2026-09-16T10:00:00Z', mode: 'live', form: 'detailed',
  settings: { allow_download: false, watermark: false }, data: {},
}

const trend = {
  period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
  granularity: 'day',
  content: roster('Roster meta', 'meta', 'key-roster-meta', 700),
  trend: [{ date: '2026-09-15', date_to: '2026-09-15', reported: true, spend: 350, clicks: 150, conversions: 20 }],
}

function answer(body: unknown) {
  return { ok: true, status: 200, json: async () => body } as Response
}

/** The requests made so far, by the part of the path that names the endpoint. */
function requests(network: ReturnType<typeof vi.fn>) {
  return network.mock.calls.map(([input]) => String(input))
}

const liveRequests = (network: ReturnType<typeof vi.fn>) => requests(network).filter((url) => /\/live\?/.test(url))
const contentRequests = (network: ReturnType<typeof vi.fn>) => requests(network).filter((url) => /\/live\/content\//.test(url))

describe('the live link asks the network once (PERF-REFETCH-001)', () => {
  const network = vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (/\/live\/content\//.test(url)) return answer({ data: trend })
    if (/\/live\?/.test(url)) return answer({ data: livePayload() })
    if (/\/widgets/.test(url)) return answer({ data: { widgets: [] } })
    if (/\/reports\/shared\/tok$/.test(url)) return answer({ data: report })
    throw new Error(`unexpected request ${url}`)
  })

  beforeEach(() => {
    vi.stubGlobal('fetch', network)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    network.mockClear()
  })

  it('mounts /r/:token with ONE request to /live, and opening a content asks only for its trend', async () => {
    renderWithProviders(<PublicReport />, { route: '/r/tok?view=content', path: '/r/:token' })

    const all = await screen.findByTestId('live-content-all')
    // Settled: the report, the branding and the widgets have all been asked for once.
    await waitFor(() => expect(requests(network).filter((url) => /\/reports\/shared\/tok$/.test(url))).toHaveLength(1))

    expect(liveRequests(network), 'the live figures on mount').toHaveLength(1)
    expect(contentRequests(network)).toHaveLength(0)

    fireEvent.click(within(all).getAllByTestId('live-content-tile')[0])
    await waitFor(() => expect(screen.getByTestId('live-content-trend')).toHaveAttribute('data-state', 'ready'))

    expect(contentRequests(network), 'the content’s own trend').toHaveLength(1)
    expect(liveRequests(network), 'the whole payload was not asked for again').toHaveLength(1)

    // Closing it and opening another: still only that content’s trend, never the page.
    fireEvent.keyDown(document, { key: 'Escape' })
    fireEvent.click(within(all).getAllByTestId('live-content-tile')[1])
    await waitFor(() => expect(contentRequests(network)).toHaveLength(2))
    expect(liveRequests(network)).toHaveLength(1)
  })

  it('re-renders of the page re-request nothing', async () => {
    const { rerender } = renderWithProviders(<PublicReport />, { route: '/r/tok', path: '/r/:token' })
    await screen.findByTestId('live-report')
    const before = requests(network).length

    rerender(<PublicReport />)
    rerender(<PublicReport />)
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(requests(network).length).toBe(before)
    expect(liveRequests(network)).toHaveLength(1)
  })
})
