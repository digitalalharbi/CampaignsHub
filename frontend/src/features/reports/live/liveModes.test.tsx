import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveSharedReport } from '../LiveSharedReport'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchLiveShared: vi.fn(),
  fetchLiveContent: vi.fn(),
}))

import { fetchLiveContent, fetchLiveShared } from '../api'

const ad = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03, roas: 3.2, cpa: spend / 12,
})

const roster = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, format: 'image', metrics: { spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03 },
})

function payloadFor(providers: string[]) {
  const all = [
    { provider: 'meta', spend: 700, impressions: 70_000, clicks: 2100, conversions: 40, cpa: 17.5, ctr: 0.03 },
    { provider: 'snapchat', spend: 300, impressions: 30_000, clicks: 900, conversions: 10, cpa: 30, ctr: 0.03 },
  ]
  const rows = providers.length === 0 ? all : all.filter((p) => providers.includes(p.provider))
  const spend = rows.reduce((a, r) => a + r.spend, 0)
  const conversions = rows.reduce((a, r) => a + r.conversions, 0)

  return {
    period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
    currency: 'SAR',
    totals: { spend, conversions, impressions: 100_000, clicks: 3000, revenue: 3000, cpa: spend / conversions, ctr: 0.03, cpc: 0.33, cpm: 10 },
    deltas: { spend: 0.1, conversions: -0.05 },
    timeseries: [{ date: '2026-09-15', spend: spend / 2, clicks: 1500, conversions: conversions / 2 }, { date: '2026-09-16', spend: spend / 2, clicks: 1500, conversions: conversions / 2 }],
    platforms: rows,
    campaigns: [],
    funnel: [{ stage: 'impressions', label: 'Impressions', reported: true, count: 100_000, from_stage: null, step_rate: null, cost_per: null }],
    objective_performance: {
      paths: [],
      direct: { label_ar: 'مباشر', label_en: 'Direct', spend: 600, orders: 40, revenue: 0, cpa: 15, roas: null, aov: null, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
      blended: { label_ar: 'مدمج', label_en: 'Blended', spend, orders: 40, revenue: 0, blended_cpa: 25, blended_roas: null, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 400 },
    },
    ads: rows.map((r) => ad(`Best on ${r.provider}`, r.provider, `key-best-${r.provider}`, r.spend)),
    ads_weakest: rows.map((r) => ad(`Weak on ${r.provider}`, r.provider, `key-weak-${r.provider}`, 5)),
    ads_groups: [],
    ads_roster: rows.map((r) => roster(`Roster ${r.provider}`, r.provider, `key-roster-${r.provider}`, r.spend)),
    creatives_in_scope: rows.length,
    budget: [{ provider: 'meta', budget: 2000, budget_currency: 'SAR', spent: 700, spent_currency: 'SAR', remaining: 1300, consumed_pct: 35, pace: 1, pacing_basis: 'comparable' }],
    objective_leaders: { paths: [{
      path: 'conversion', label_ar: 'التحويل', label_en: 'Conversion & sales', metric: 'orders', comparable: true, comparable_reason: '', campaigns: 2,
      strongest: { id: 'meta', name: 'meta', objective: 'sales', metric: 'cpa', value: 17.5 },
      weakest: { id: 'snapchat', name: 'snapchat', objective: 'sales', metric: 'cpa', value: 30 },
    }] },
    sections: { attribution: false },
    store_funnel: null,
    freshness: [],
    available: { providers: ['meta', 'snapchat'], campaigns: [], earliest: '2026-01-01', latest: '2026-09-16' },
    metrics: [],
    applied: { from: '2026-08-18', to: '2026-09-16', providers, campaigns: [] },
  }
}

function answerByProvider() {
  vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => (
    { status: 200, envelope: { data: payloadFor(opts.providers) } }
  ) as never)
}

describe('a link shared as an executive summary', () => {
  beforeEach(answerByProvider)
  afterEach(() => vi.clearAllMocks())

  it('is the summary and nothing else, whatever the address asks for', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { route: '/r/tok?view=platforms' })

    await screen.findByTestId('live-mode-summary')
    expect(screen.queryByTestId('live-modes')).not.toBeInTheDocument()
    expect(screen.queryByTestId('live-mode-platforms')).not.toBeInTheDocument()
  })

  it('shows headline figures and visuals, not the dashboard’s tables', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />)
    const summary = await screen.findByTestId('live-mode-summary')

    expect(within(summary).getByTestId('live-kpis')).toBeInTheDocument()
    expect(within(summary).queryByTestId('live-kpis-secondary')).not.toBeInTheDocument()
    // CLIENT-REPORT-FRAMING-001 — platforms → results → trend → results split → platforms → content → coverage.
    for (const block of ['exec-platform-cards', 'exec-trend', 'exec-results-split', 'exec-platforms', 'exec-best-content', 'exec-improve-content']) {
      expect(within(summary).getByTestId(block), block).toBeInTheDocument()
    }
    // No spend, no cost and no campaign count anywhere on the client's summary.
    for (const gone of ['exec-spend-split', 'exec-kpi-count', 'exec-health-budget', 'exec-action-impact']) {
      expect(within(summary).queryByTestId(gone), gone).not.toBeInTheDocument()
    }
    // Revenue is a result and keeps its unit; nothing about what was spent is stated.
    expect(summary).not.toHaveTextContent(/Spend|Cost per|ROAS|Budget/)
    for (const table of ['live-platform-comparison', 'live-detail-tables', 'live-funnel', 'live-attention']) {
      expect(within(summary).queryByTestId(table), table).not.toBeInTheDocument()
    }
  })
})

describe('the executive summary (REPORT-EXECUTIVE-SUMMARY-001)', () => {
  afterEach(() => vi.clearAllMocks())

  it('opens on the platforms summary, narrows best and improvable content by platform, and ends on optimisation steps', async () => {
    vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => ({
      status: 200,
      envelope: { data: {
        ...payloadFor(opts.providers),
        attention: [
          {
            key: 'a1', code: 'cpc_rise', severity: 'warning', nature: 'problem', family: 'cost', family_label_ar: '', family_label_en: '',
            platform: 'meta', kpis: [], action: 'review_cost_drivers',
            impact: { kind: 'extra_cost_vs_previous_rate', amount: 120, currency: 'SAR' }, evidence: null,
          },
          {
            key: 'a2', code: 'ctr_drop', severity: 'warning', nature: 'problem', family: 'response', family_label_ar: '', family_label_en: '',
            platform: 'meta', kpis: [], action: 'refresh_creative', impact: null,
            evidence: { platform: 'meta', content: [{ name: 'Best on meta', content_key: 'key-best-meta' }] },
          },
          {
            key: 'a3', code: 'ctr_rise', severity: 'info', nature: 'opportunity', family: 'response', family_label_ar: '', family_label_en: '',
            platform: 'snapchat', kpis: [], action: 'keep_what_works', impact: null, evidence: null,
          },
        ],
      } },
    }) as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })

    // The platforms summary leads, each platform stated by its results, never its spend.
    const cards = await screen.findByTestId('exec-platform-cards')
    expect(within(cards).getByTestId('exec-platform-card-all')).toHaveTextContent('50')
    expect(within(cards).getByTestId('exec-platform-card-meta')).toHaveTextContent('40')
    expect(cards).not.toHaveTextContent(/700|SAR/)

    const best = screen.getByTestId('exec-best-content')
    expect(best).toHaveTextContent('Best on meta')
    expect(best).toHaveTextContent('Best on snapchat')
    expect(best).not.toHaveTextContent(/Spend|ROAS|SAR/)
    expect(screen.getByTestId('exec-improve-content')).toHaveTextContent('Weak on meta')

    fireEvent.click(screen.getByTestId('exec-platform-card-snapchat'))
    await waitFor(() => expect(screen.getByTestId('exec-best-content')).not.toHaveTextContent('Best on meta'))
    expect(screen.getByTestId('exec-best-content')).toHaveTextContent('Best on snapchat')
    expect(screen.getByTestId('exec-improve-content')).not.toHaveTextContent('Weak on meta')

    // Only delivery and response signals reach the client; what works leads, a cost signal never shows.
    expect(screen.queryByTestId('exec-action-a1')).not.toBeInTheDocument()
    const steps = within(screen.getByTestId('exec-actions')).getAllByRole('article').map((a) => a.getAttribute('data-testid'))
    expect(steps).toEqual(['exec-action-a3', 'exec-action-a2'])
    // Nothing is missing, so the coverage strip is not drawn: the page states what is true, not that it is whole.
    expect(screen.queryByTestId('exec-health')).not.toBeInTheDocument()
  })

})

describe('a detailed link', () => {
  beforeEach(answerByProvider)
  afterEach(() => vi.clearAllMocks())

  it('opens on the executive summary and offers five views, none of them campaigns', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />)

    // REPORT-EXECUTIVE-SUMMARY-001 — every live link opens on the executive dashboard.
    await screen.findByTestId('live-mode-summary')
    const tabs = within(screen.getByTestId('live-modes')).getAllByRole('tab').map((t) => t.getAttribute('data-testid'))
    expect(tabs).toEqual(['live-mode-tab-summary', 'live-mode-tab-dashboard', 'live-mode-tab-platforms', 'live-mode-tab-content', 'live-mode-tab-tables'])
    fireEvent.click(screen.getByTestId('live-mode-tab-dashboard'))
    await screen.findByTestId('live-mode-dashboard')
    expect(screen.getByTestId('live-weakest-content')).toBeInTheDocument()
  })

  /*
   * KPI → charts → platform table: measured locally at 1440×900, the dashboard's first chart sat at
   * y=1383, two screens down, under the objective split, the comparison table and the leaders.
   */
  it('draws the charts before the platform table and the leaders', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    const charts = await screen.findByTestId('live-platforms')
    const table = screen.getByTestId('live-platform-comparison')
    const leaders = screen.getByTestId('live-objective-leaders')
    expect(charts.compareDocumentPosition(table) & 4, 'the platform table came before the charts').toBeTruthy()
    expect(charts.compareDocumentPosition(leaders) & 4, 'the leaders came before the charts').toBeTruthy()
  })

  it('prints direct against blended only where the two differ, as the summary does', async () => {
    vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => {
      const data = payloadFor(opts.providers)
      data.objective_performance.direct.spend = data.totals.spend
      data.objective_performance.direct.cpa = 25

      return { status: 200, envelope: { data } } as never
    })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    await screen.findByTestId('live-mode-dashboard')
    expect(screen.queryByTestId('live-objective-split'), 'one figure printed twice under two names').not.toBeInTheDocument()
  })

  it('states the strongest and weakest platform per objective as figures, not a sentence', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    const strongest = await screen.findByTestId('live-leaders-conversion-strongest')
    expect(strongest).toHaveTextContent('Meta')
    expect(strongest).toHaveTextContent('17.50 SAR')
    expect(screen.getByTestId('live-leaders-conversion-weakest')).toHaveTextContent('30 SAR')
  })

  it('reads one platform through the same endpoint, narrowed, and the view agrees with the whole link', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=platforms&platform=snapchat' })

    const view = await screen.findByTestId('live-platform-view-snapchat')
    await waitFor(() => expect(vi.mocked(fetchLiveShared).mock.calls.some(([, o]) => o.providers.join() === 'snapchat')).toBe(true))
    // The whole-link request is never narrowed by the platform mode's own choice.
    expect(vi.mocked(fetchLiveShared).mock.calls.some(([, o]) => o.providers.length === 0)).toBe(true)

    expect(within(view).getByTestId('live-platform-best-content')).toHaveTextContent('Best on snapchat')
    expect(within(view).queryByText('Best on meta')).not.toBeInTheDocument()
    expect(screen.getByTestId('live-platform-card-snapchat')).toHaveAttribute('aria-selected', 'true')
  })

  it('drills from a platform into its content', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=platforms&platform=meta' })

    fireEvent.click(await screen.findByTestId('live-platform-goto-content'))

    const content = await screen.findByTestId('live-mode-content')
    expect(within(content).getByTestId('live-content-platform-meta')).toHaveAttribute('aria-selected', 'true')
    await within(content).findByText('Roster meta')
    expect(within(content).queryByText('Roster snapchat')).not.toBeInTheDocument()
  })
})

describe('a cut list on the live dashboard', () => {
  afterEach(() => vi.clearAllMocks())

  /*
   * The ranked group says «3 of 60» and where the rest are. On the saved report that is the roster
   * below it; on a live link there is no roster on the dashboard — the rest are in Content mode, and
   * «the rest are in the creative roster below» sent the client looking for a table that is not there.
   */
  it('says the rest are under Content, not in a roster the live page does not draw', async () => {
    vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => {
      const data = payloadFor(opts.providers) as Record<string, unknown>
      data.ads_groups = [{
        family: 'sales', label_ar: 'المبيعات', label_en: 'Sales', ranked: true, metric: 'roas',
        metric_label_ar: 'العائد', metric_label_en: 'ROAS', candidates: 60,
        ads: (data.ads as unknown[]).slice(0, 2),
      }]

      return { status: 200, envelope: { data } } as never
    })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard', locale: 'en' })

    const note = await screen.findByTestId('report-ads-of-sales')
    expect(note).toHaveTextContent('2 of 60')
    expect(note).not.toHaveTextContent(/roster below/)
    expect(note).toHaveTextContent(/Content/)
  })
})

describe('opening one piece of content', () => {
  beforeEach(answerByProvider)
  afterEach(() => vi.clearAllMocks())

  it('asks for it by its key and draws its trend', async () => {
    vi.mocked(fetchLiveContent).mockResolvedValue({
      status: 200,
      envelope: { data: {
        period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
        granularity: 'day',
        content: roster('Roster meta', 'meta', 'key-roster-meta', 700),
        trend: [
          { date: '2026-09-15', date_to: '2026-09-15', reported: true, spend: 350, clicks: 150, conversions: 20 },
          { date: '2026-09-16', date_to: '2026-09-16', reported: false },
        ],
      } },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=content' })
    const all = await screen.findByTestId('live-content-all')
    fireEvent.click(within(all).getAllByTestId('live-content-tile')[0])

    await waitFor(() => expect(screen.getByTestId('live-content-trend')).toHaveAttribute('data-state', 'ready'))
    expect(vi.mocked(fetchLiveContent).mock.calls[0][1]).toBe('key-roster-meta')
  })

  it('tells a failed trend apart from content that did not run', async () => {
    vi.mocked(fetchLiveContent).mockResolvedValue({ status: 404, envelope: { message: 'x' } } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=content' })
    const all = await screen.findByTestId('live-content-all')
    fireEvent.click(within(all).getAllByTestId('live-content-tile')[0])

    await waitFor(() => expect(screen.getByTestId('live-content-trend')).toHaveAttribute('data-state', 'failed'))
    expect(screen.queryByTestId('live-content-trend-silent')).not.toBeInTheDocument()
  })
})

describe('the states a reader must never see collapsed', () => {
  afterEach(() => vi.clearAllMocks())

  it('shows a skeleton while pending and never the failure sentence', async () => {
    vi.mocked(fetchLiveShared).mockReturnValue(new Promise(() => {}) as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    expect(await screen.findByTestId('live-skeleton')).toBeInTheDocument()
    expect(screen.queryByTestId('live-failed')).not.toBeInTheDocument()
  })

  it('shows the refusal when the request fails, never a skeleton that waits forever', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 404, envelope: { message: 'The link is not valid.' } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    expect(await screen.findByTestId('live-failed')).toHaveTextContent('The link is not valid.')
    expect(screen.queryByTestId('live-skeleton')).not.toBeInTheDocument()
  })

  it('keeps the figures on screen when a refresh fails, and says so', async () => {
    answerByProvider()
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })
    await screen.findByTestId('live-mode-dashboard')

    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 500, envelope: {} } as never)
    fireEvent.click(screen.getByTestId('live-refresh'))

    expect(await screen.findByTestId('live-refresh-failed')).toBeInTheDocument()
    expect(screen.getByTestId('live-kpis')).toBeInTheDocument()
  })
})

describe('a link left open stays live', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  function visibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
  }

  it('recomputes on its own while the page is visible, and asks nothing while it is hidden', async () => {
    answerByProvider()
    visibility('visible')
    vi.useFakeTimers({ shouldAdvanceTime: true })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })
    await screen.findByTestId('live-mode-dashboard')
    const opened = vi.mocked(fetchLiveShared).mock.calls.length

    visibility('hidden')
    await vi.advanceTimersByTimeAsync(6 * 60_000)
    expect(vi.mocked(fetchLiveShared).mock.calls.length, 'a hidden tab spent the public ration').toBe(opened)

    visibility('visible')
    await vi.advanceTimersByTimeAsync(60_000)
    await waitFor(() => expect(vi.mocked(fetchLiveShared).mock.calls.length).toBeGreaterThan(opened))
  })
})

/**
 * REPORT-ARCHITECTURE-001 — Standard and Detailed are two compositions, not one page with a dial.
 */
describe('the report levels are different products', () => {
  afterEach(() => vi.clearAllMocks())

  const serve = (level: 'standard' | 'detailed', extra: Record<string, unknown> = {}) => vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => ({
    status: 200,
    envelope: { data: {
      ...payloadFor(opts.providers),
      ...extra,
      detail_level: level,
      accounts: level === 'detailed' ? [{ account_id: 'a1', provider: 'meta', account_name: 'HENKA ads', spend: 700, impressions: 70_000, clicks: 2100, conversions: 40, cpa: 17.5 }] : [],
    } },
  }) as never)

  it('a STANDARD link is one composition: no tabs, no tables, no depth it does not carry', async () => {
    serve('standard')
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />)

    const view = await screen.findByTestId('live-mode-standard')
    expect(screen.queryByTestId('live-modes')).not.toBeInTheDocument()
    expect(within(view).getByTestId('standard-best-content')).toBeInTheDocument()
    expect(within(view).getByTestId('standard-commentary')).toBeInTheDocument()
    // REPORT-FOOTER-001 — the link ends with the one footer, outside the report body.
    expect(screen.getByTestId('report-footer')).toBeInTheDocument()
    expect(within(view).queryByTestId('report-footer')).not.toBeInTheDocument()
    for (const deeper of ['live-detail-tables', 'live-scope-counts', 'live-mode-tables']) {
      expect(screen.queryByTestId(deeper), deeper).not.toBeInTheDocument()
    }
  })

  it('a DETAILED link is a workspace of views, and its tables view holds the analytical depth', async () => {
    serve('detailed')
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />)

    await screen.findByTestId('live-mode-summary')
    expect(screen.getByTestId('live-mode-tab-tables')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('live-mode-tab-tables'))

    const tables = await screen.findByTestId('live-mode-tables')
    expect(within(tables).getByTestId('tables-platforms')).toBeInTheDocument()
    expect(within(tables).getByTestId('tables-days')).toBeInTheDocument()
    expect(within(tables).getByTestId('tables-content')).toBeInTheDocument()
    // The roster nests figures under `metrics`; read flat, every cell was a dash.
    const contentRow = within(within(tables).getByTestId('tables-content')).getAllByRole('row')[1]
    expect(contentRow).toHaveTextContent('300')
    expect(contentRow).toHaveTextContent('12')
    expect(within(tables).getByTestId('tables-accounts')).toHaveTextContent('HENKA ads')
  })

  /*
   * REPORT-TABLES-OWNER-COLUMNS-001 — the Owner's column choice reaches every table of the Tables view, the
   * row's name column is never a choice, and a link that hides spend publishes no spend or cost column even
   * when the choice lists them.
   */
  it('shows only the columns the Owner chose in the accounts, content and day tables, and never a hidden money column', async () => {
    serve('detailed', { columns: { accounts: ['spend', 'results'], content: ['results', 'cost'], days: ['clicks'], platforms: ['results'] } })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=tables' })

    const tables = await screen.findByTestId('live-mode-tables')
    const heads = (id: string) => within(within(tables).getByTestId(id)).getAllByRole('columnheader').map((h) => h.textContent ?? '')
    expect(heads('tables-accounts').join('|')).toMatch(/Ad account.*Results.*Spend/)
    expect(heads('tables-accounts').join('|')).not.toMatch(/Impressions|Clicks|Platform/)
    expect(heads('tables-content').join('|')).toMatch(/Content.*Results.*Cost per result/)
    expect(heads('tables-content').join('|')).not.toMatch(/Spend|Impressions|Platform/)
    expect(heads('tables-days').join('|')).toMatch(/Day.*Clicks/)
    expect(heads('tables-days').join('|')).not.toMatch(/Impressions|Spend|Results/)
    expect(heads('tables-platforms').join('|')).not.toMatch(/Spend|CTR|Cost/)
  })

  it('publishes every column when the Owner chose none, and still no money when the link hides spend', async () => {
    serve('detailed', { columns: {}, totals: { spend: null, impressions: 100_000, clicks: 3000, conversions: 50 } })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=tables' })

    const tables = await screen.findByTestId('live-mode-tables')
    const heads = (id: string) => within(within(tables).getByTestId(id)).getAllByRole('columnheader').map((h) => h.textContent ?? '')
    expect(heads('tables-content').join('|')).toMatch(/Results.*Impressions.*Clicks/)
    expect(heads('tables-content').join('|')).not.toMatch(/Spend|Cost per result/)
    expect(heads('tables-accounts').join('|')).not.toMatch(/Spend|Cost per result/)
  })

  /*
   * REPORT-COMPOSITION-CONTROL-001 — the server names the main sections a link offers. Only those get
   * tabs, a closed one cannot be reached by its address, a single one opens without a tab bar, and the
   * daily ledger appears only when its own component is in the section list.
   */
  it('offers only the main sections the server names, and the ledger only when daily records are on', async () => {
    // The platform table belongs to «الجداول التفصيلية» (Owner 2026-10-29): it is listed by that component's key.
    serve('detailed', { report_views: ['summary', 'tables'], report_sections: ['kpis', 'platform_comparison', 'detailed_tables', 'content_performance', 'account_breakdown'] })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard' })

    await screen.findByTestId('live-mode-summary')
    const tabs = within(screen.getByTestId('live-modes')).getAllByRole('tab').map((t) => t.getAttribute('data-testid'))
    expect(tabs).toEqual(['live-mode-tab-summary', 'live-mode-tab-tables'])
    expect(screen.queryByTestId('live-mode-dashboard')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('live-mode-tab-tables'))
    const tables = await screen.findByTestId('live-mode-tables')
    expect(within(tables).queryByTestId('tables-days')).not.toBeInTheDocument()
    expect(within(tables).getByTestId('tables-platforms')).toBeInTheDocument()
  })

  it('a single main section opens directly, with no tab bar', async () => {
    serve('detailed', { report_views: ['tables'], report_sections: ['kpis', 'platform_comparison', 'daily_records'] })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />)

    const tables = await screen.findByTestId('live-mode-tables')
    expect(screen.queryByTestId('live-modes')).not.toBeInTheDocument()
    expect(within(tables).getByTestId('tables-days')).toBeInTheDocument()
  })

  it('the two levels share no composition: a standard report never contains the tables view', async () => {
    serve('standard')
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=tables' })

    await screen.findByTestId('live-mode-standard')
    expect(screen.queryByTestId('live-mode-tables')).not.toBeInTheDocument()
  })
})
