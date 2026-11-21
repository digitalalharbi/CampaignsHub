import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { DashboardUnit, DeliveryRow, RoleDashboard, WatchBoard } from './api'

const state: { data: RoleDashboard | null; watch: WatchBoard | null } = { data: null, watch: null }
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  useRoleDashboard: () => ({ data: state.data, isLoading: false, isError: false, error: null, refetch: vi.fn() }),
  // DASH-PERF-SPLIT-001 — the panels' own request answers with the same fixture.
  useDashboardPerformance: () => ({ data: state.data, isLoading: false, isError: false }),
  // WATCH-BOARD-001 — the follow-up board's own request.
  useWatchBoard: () => ({ data: state.watch, isLoading: false, isError: false, isFetching: false, isPlaceholderData: false }),
}))
vi.mock('@/features/funding/fundingApi', () => ({ useFinancialDisclaimer: () => ({ data: { ar: 'نص', en: 'Disclaimer' } }) }))

import { RoleDashboardPage } from './RoleDashboardPage'

const unit = (over: Partial<DashboardUnit>): DashboardUnit => ({
  id: 'c1', kind: 'client', name: 'Alpha', projects: [{ id: 'p1', name: 'P1' }], budget: 1000, spent: 700, remaining: 300, projected: 900, pace: 0.9,
  currency: 'SAR', currencies: 1, excluded: 0, unmeasured: 0, campaigns: 2, ratio: 0.7, daily_rate_3d: 100, days_left: 3, run_out_on: '2026-11-18', ...over,
})

const base = (over: Partial<RoleDashboard>): RoleDashboard => ({
  view: 'manager', default_view: 'manager', available_views: ['manager', 'pm', 'finance', 'marketer'],
  window: { from: '2026-10-17', to: '2026-11-15', days: 30, today: '2026-11-15' }, thresholds: { near: 0.8 },
  scope: { clients: 3, projects: 3, restricted: false }, can_money: true, sections: [],
  money: { budget: 3000, spent: 1500, remaining: 1500, projected: 2000, pace: 0.7, currency: 'SAR', currencies: 1, excluded: 0, unmeasured: 0, campaigns: 6, rows: 6 },
  units: [unit({}), unit({ id: 'c2', name: 'Beta', ratio: 0.85, pace: 1.1 }), unit({ id: 'c3', name: 'Gamma', ratio: 1.2, pace: 1.6, days_left: 0, run_out_on: '2026-11-15' }), unit({ id: 'c4', name: 'Delta', ratio: null, pace: null, spent: null, remaining: null })],
  daily_spend: [{ date: '2026-11-13', spend: 100 }, { date: '2026-11-14', spend: 120 }],
  alerts: { open: 3, critical: 1, warning: 2 }, integrations: { ok: 4, attention: 1, pending: 0, platforms: [{ platform: 'meta', ok: 2, attention: 1 }] },
  campaigns: { active: 5, paused: 1, draft: 0, completed: 2, other: 0, total: 8 },
  ...over,
})

const page = (d: RoleDashboard, locale: 'ar' | 'en' = 'en') => {
  state.data = d
  return renderWithProviders(<RoleDashboardPage portal="agency" />, { route: '/agency/dashboard', locale })
}

describe('the role dashboards', () => {
  beforeEach(() => { state.data = null; state.watch = null })

  it('manager: figures open their pages, spend carries its status, the strip names alerts and integrations', () => {
    page(base({}))
    // ANALYTICS-POPUP-001 — a figure the board answers opens that section in a popup; the rest stay links.
    // HOME-DOORS-001 — money opens «الميزانيات» itself; the campaigns figure still opens the board.
    expect(screen.getByTestId('dash-kpi-budget')).toHaveAttribute('href', '/agency/budgets')
    expect(screen.getByTestId('dash-kpi-active')).toHaveAttribute('data-opens', 'analytics:board')
    expect(screen.getByTestId('dash-kpi-alerts')).toHaveAttribute('href', '/agency/alerts')
    // 1500 of 3000 is 50%: green.
    expect(screen.getByTestId('dash-kpi-spend')).toHaveAttribute('data-status', 'within')
    expect(screen.getByTestId('dash-kpi-alerts')).toHaveAttribute('data-status', 'exceeded')
    expect(screen.getByTestId('dash-chip-integrations-review')).toHaveAttribute('href', '/agency/integrations')
    // Spend carries its currency mark, never a bare number.
    expect(screen.getByTestId('dash-kpi-budget-value').textContent).toContain('ر.س')
    expect(screen.getByTestId('financial-disclaimer')).toBeTruthy()
  })

  it('manager: a section the member lacks is not drawn (no alerts, no integrations)', () => {
    page(base({ alerts: null, integrations: null }))
    expect(screen.queryByTestId('dash-kpi-alerts')).toBeNull()
    expect(screen.queryByTestId('dash-chip-integrations-ok')).toBeNull()
  })

  it('only the workspace manager is offered the other roles', () => {
    page(base({ available_views: ['pm'], view: 'pm', tasks: null, approvals: null }))
    expect(screen.queryByTestId('role-dashboard-views')).toBeNull()
  })

  it('pm: a gauge per client in the shared status colours, with tasks and approvals', () => {
    page(base({ view: 'pm', tasks: { open: 9, overdue: 2, blocked: 1, mine: 4 }, approvals: { pending: 3, changes_requested: 1 } }))
    expect(screen.getByTestId('dash-gauge-c1')).toHaveAttribute('data-status', 'within')
    expect(screen.getByTestId('dash-gauge-c2')).toHaveAttribute('data-status', 'near')
    expect(screen.getByTestId('dash-gauge-c3')).toHaveAttribute('data-status', 'exceeded')
    expect(screen.getByTestId('dash-gauge-c4')).toHaveAttribute('data-status', 'unknown')
    expect(screen.getByTestId('dash-gauge-c1')).toHaveAttribute('href', '/agency/clients/c1')
    expect(screen.getByTestId('dash-kpi-tasks')).toHaveAttribute('href', '/agency/tasks')
    expect(screen.getByTestId('dash-kpi-approvals').textContent).toContain('3')
  })

  it('finance: planned, spent, remaining, run-out dates from the last three days, invoices', () => {
    page(base({
      view: 'finance',
      invoices: { currency: 'SAR', mixed_currency: false, invoiced: 5000, collected: 3000, owed: 2000, overdue: 500, overdue_count: 2, due_soon_count: 1, by_status: {} },
    }))
    expect(screen.getByTestId('dash-kpi-budget')).toBeTruthy()
    expect(screen.getByTestId('dash-kpi-owed')).toHaveAttribute('href', '/agency/billing')
    expect(screen.getByTestId('dash-kpi-overdue')).toHaveAttribute('data-status', 'exceeded')
    // Soonest run-out first; an exhausted client says so.
    const rows = within(screen.getByTestId('dash-runout')).getAllByRole('link')
    expect(rows[0]).toHaveAttribute('data-testid', 'dash-runout-c3')
    expect(rows[0].textContent).toContain('Exhausted')
    expect(screen.getByTestId('dash-chip-over').textContent).toContain('1')
  })

  it('marketer: results and cost belong to ONE objective, ROAS only for sales, best/worst link to the campaign', () => {
    const rows = [
      { id: 'k1', project_id: 'p1', name: 'K1', status: 'active', family: 'sales', platform: 'meta', spend: 400, result: 20, result_label: { ar: 'Purchases', en: 'Purchases' }, cost_per_result: 20, cost_label: { ar: 'Cost per purchase', en: 'Cost per purchase' }, roas: 3 },
      { id: 'k2', project_id: 'p1', name: 'K2', status: 'active', family: 'sales', platform: 'tiktok', spend: 200, result: 5, result_label: { ar: 'Purchases', en: 'Purchases' }, cost_per_result: 40, cost_label: { ar: 'Cost per purchase', en: 'Cost per purchase' }, roas: 1.5 },
      { id: 'k3', project_id: 'p1', name: 'K3', status: 'active', family: 'traffic', platform: 'meta', spend: 900, result: 900, result_label: { ar: 'Visits', en: 'Visits' }, cost_per_result: 1, cost_label: { ar: 'Cost per visit', en: 'Cost per visit' }, roas: null },
    ]
    page(base({
      view: 'marketer', mine: true, campaign_rows: rows, rank_family: 'sales',
      families: [{ family: 'sales', campaigns: 2, spend: 600 }, { family: 'traffic', campaigns: 1, spend: 900 }],
      best: [{ id: 'k1', project_id: 'p1', name: 'K1', family: 'sales', cost_per_result: 20, cost_label: null, result: 20, result_label: null, roas: 3 }],
      worst: [{ id: 'k2', project_id: 'p1', name: 'K2', family: 'sales', cost_per_result: 40, cost_label: null, result: 5, result_label: null, roas: 1.5 }],
      platforms: [{ platform: 'meta', spend: 400, share: 0.67, result: 20, result_label: { ar: 'Purchases', en: 'Purchases' }, cost_per_result: 20, cost_label: { ar: 'Cost per purchase', en: 'Cost per purchase' }, roas: 3, ctr: 0.01 }, { platform: 'tiktok', spend: 200, share: 0.33, result: 5, result_label: { ar: 'Purchases', en: 'Purchases' }, cost_per_result: 40, cost_label: { ar: 'Cost per purchase', en: 'Cost per purchase' }, roas: 1.5, ctr: 0.02 }],
    }))
    // 600 spent over 25 purchases = 24 — the traffic campaign's 900 visits are not mixed in.
    expect(screen.getByTestId('dash-kpi-cost-value').textContent).toContain('24')
    expect(screen.getByTestId('dash-kpi-results-value').textContent).toContain('25')
    expect(screen.getByTestId('dash-kpi-roas')).toBeTruthy()
    expect(screen.getByTestId('dash-best-k1')).toHaveAttribute('href', '/agency/campaigns/p1/k1')
  })

  it('marketer: a non-sales objective never shows ROAS', () => {
    page(base({ view: 'marketer', mine: false, campaign_rows: [{ id: 'k3', project_id: 'p1', name: 'K3', status: 'active', family: 'traffic', platform: 'meta', spend: 900, result: 900, result_label: { ar: 'Visits', en: 'Visits' }, cost_per_result: 1, cost_label: { ar: 'Cost per visit', en: 'Cost per visit' }, roas: null }], rank_family: 'traffic', families: [{ family: 'traffic', campaigns: 1, spend: 900 }], best: [], worst: [], platforms: [] }))
    expect(screen.queryByTestId('dash-kpi-roas')).toBeNull()
  })

  it('without the money key nothing about money is drawn and no disclaimer is needed', () => {
    page(base({ can_money: false, money: null, units: [], daily_spend: [] }))
    expect(screen.queryByTestId('financial-disclaimer')).toBeNull()
    expect(screen.getByTestId('dash-kpi-budget-value').textContent).toBe('—')
  })

  it('reads in Arabic with two-word headings and no retired word', () => {
    page(base({}), 'ar')
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => (h.textContent ?? '').trim())
    for (const h of headings) expect(h.split(/\s+/).length, h).toBeLessThanOrEqual(2)
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('الرئيسية')
  })
})

/** DASH-MANAGER-PERFORMANCE-001 — the manager reads the leading campaigns, the platforms and where the spend went. */
describe('HOME-BUDGET-FALLBACK-001 — a project with no approved budget', () => {
  beforeEach(() => { state.data = null; state.watch = null })

  it('reads the platforms\' campaign budgets and the window\'s real spend instead of «—»', () => {
    page(base({
      money: { budget: null, spent: null, remaining: null, projected: null, pace: null, currency: null, currencies: 0, excluded: 1, unmeasured: 0, campaigns: 0, rows: 0,
        platform_campaign_budgets: { budget: 960, spent: 923.26, remaining: 36.74, projected: 923.26, pace: 0.96, currency: 'USD', currencies: 1, excluded: 4, unmeasured: 0, campaigns: 6 } },
      performance: { spend: 412, previous_spend: null, previous: null, withheld: 0, campaigns: 2, family: 'sales', results: 3, result_label: null, cost_per_result: 137, cost_label: null },
    } as unknown as Partial<RoleDashboard>))
    expect(screen.getByTestId('dash-kpi-budget')).toHaveTextContent('960')
    expect(screen.getByTestId('dash-kpi-budget')).toHaveTextContent('Campaign budgets')
    expect(screen.getByTestId('dash-kpi-spend')).toHaveTextContent('412')
    expect(screen.getByTestId('dash-kpi-remaining')).toHaveTextContent('37')
  })
})

describe('the manager performance panels', () => {
  beforeEach(() => { state.data = null; state.watch = null })

  it('lists the leading campaigns, compares the platforms with a bar each and shares the spend', () => {
    page(base({
      rank_family: 'sales',
      campaign_rows: [
        { id: 'k1', project_id: 'p1', name: 'Summer', status: 'active', family: 'sales', platform: 'snapchat', spend: 500, result: 40, result_label: null, cost_per_result: 12.5, cost_label: null, roas: 3.6 },
        { id: 'k2', project_id: 'p1', name: 'Weekend', status: 'active', family: 'sales', platform: 'meta', spend: 300, result: 90, result_label: null, cost_per_result: 3.3, cost_label: null, roas: 3.1 },
      ],
      platforms: [
        { platform: 'snapchat', spend: 500, share: 0.625, result: 40, result_label: null, cost_per_result: 12.5, cost_label: null, roas: 3.6, ctr: null },
        { platform: 'meta', spend: 300, share: 0.375, result: 90, result_label: null, cost_per_result: 3.3, cost_label: null, roas: 3.1, ctr: null },
      ],
    }))

    const top = screen.getByTestId('dash-top')
    const rows = within(top).getAllByRole('row').slice(1)
    // The most results first.
    expect(rows[0]).toHaveTextContent('Weekend')
    // HOME-DOORS-001 — a campaign opens a popup with its figures; the campaign page is one link inside it.
    fireEvent.click(within(top).getByTestId('dash-top-open-k2'))
    const popup = screen.getByTestId('dash-top-popup')
    expect(within(popup).getByRole('link', { name: 'Details' })).toHaveAttribute('href', '/agency/campaigns/p1/k2')
    expect(screen.getByTestId('dash-compare-snapchat')).toHaveTextContent('3.6')
    expect(screen.getByTestId('dash-spend-share-legend')).toHaveTextContent(/6[23]%/)
  })

  it('DASH-PERIOD-001 — reads the window\'s spend against the window before it, and offers the shared date picker', () => {
    page(base({
      performance: { spend: 450, previous_spend: 600, previous: { from: '2026-11-02', to: '2026-11-08' }, withheld: 0, campaigns: 2, family: 'sales', results: 130, result_label: null, cost_per_result: 3.46, cost_label: null },
    }))
    expect(screen.getByTestId('dash-window-kpis')).toBeInTheDocument()
    expect(screen.getByTestId('dash-window-spend-delta')).toHaveTextContent('25%')
    expect(screen.getByTestId('dash-kpi-window-results-value')).toHaveTextContent('130')
    // DASH-DATE-RANGE-001 — the shared date picker replaces the fixed day chips.
    expect(screen.getByTestId('role-dashboard-range')).toBeInTheDocument()
    expect(screen.queryByTestId('role-window-30')).not.toBeInTheDocument()
  })

  it('says there is nothing yet instead of drawing empty tables', () => {
    page(base({ campaign_rows: [], platforms: [] }))
    expect(screen.queryByTestId('dash-top')).not.toBeInTheDocument()
    expect(screen.queryByTestId('dash-compare')).not.toBeInTheDocument()
    expect(screen.getByTestId('dash-panel-spend-share')).toHaveTextContent('No data yet')
  })

  it('WATCH-BOARD-001 — follows every client from one board: best and weakest campaigns of every platform, and the content', () => {
    const ranked = (id: string, name: string, platform: string, result: number, cost: number) => ({ id, project_id: 'p1', name, family: 'leads', platform, spend: 100, cost_per_result: cost, cost_label: null, result, result_label: null, roas: null })
    const ad = (id: string, name: string, provider: string) => ({ id, name, provider, preview: null, spend: 50, impressions: 1000, clicks: 40, conversions: 4, ctr: 0.04, cpa: 12.5, roas: null })
    state.watch = {
      window: { from: '2026-10-17', to: '2026-11-15', days: 30, today: '2026-11-15' },
      units: [{ id: 'c1', kind: 'client', name: 'Alpha', projects: 1 }, { id: 'c2', kind: 'client', name: 'Beta', projects: 2 }],
      unit: null, can_money: true, currency: 'SAR',
      trend: [{ date: '2026-11-14', spend: 100, impressions: 1000, clicks: 30, conversions: 3 }, { date: '2026-11-15', spend: 120, impressions: 1500, clicks: 45, conversions: 5 }],
      content: { best: [ad('a1', 'Story A', 'snapchat'), ad('a2', 'Reel B', 'meta')], worst: [ad('a3', 'Banner C', 'tiktok')], basis: { metric: 'cpa', label_ar: 'تكلفة النتيجة', label_en: 'Cost per result', family: 'leads' }, in_scope: 3 },
      rank_family: 'leads',
      best: [ranked('k1', 'Leads Snap', 'snapchat', 90, 4), ranked('k2', 'Leads Meta', 'meta', 60, 6)],
      worst: [ranked('k3', 'Leads TikTok', 'tiktok', 5, 40)],
      platforms: [], performance: { spend: 220, previous_spend: 200, previous: { from: '2026-09-17', to: '2026-10-16' }, withheld: 0, campaigns: 3, family: 'leads', results: 155, result_label: null, cost_per_result: 1.42, cost_label: null },
    }
    page(base({ can_watch: true }))

    const board = screen.getByTestId('watch-board')
    // One compact picker, «All clients» first, then every client in reach.
    const picker = within(board).getByTestId('watch-unit-select') as HTMLSelectElement
    expect(picker.value).toBe('')
    expect([...picker.options].map((o) => o.textContent)).toEqual(['All clients', 'Alpha', 'Beta'])
    expect(screen.getByTestId('watch-kpi-results-value')).toHaveTextContent('155')
    expect(screen.getByTestId('watch-kpi-clicks-value')).toHaveTextContent('75')
    // Best and weakest, each opening its campaign, the platform named beside it.
    expect(within(screen.getByTestId('watch-best')).getByRole('link', { name: /Leads Snap/ })).toHaveAttribute('href', '/agency/campaigns/p1/k1')
    expect(screen.getByTestId('watch-best-k1')).toHaveTextContent('Snapchat')
    expect(screen.getByTestId('watch-worst-k3')).toHaveTextContent('Leads TikTok')
    // HOME-CONTENT-PARITY-001 — best and weakest are the same report section, drawn alike.
    const best = screen.getByTestId('watch-panel-content-best')
    const cards = within(best).getAllByTestId('report-ad-card')
    expect(cards.length).toBe(2)
    expect(within(screen.getByTestId('watch-panel-content-worst')).getAllByTestId('report-ad-card')).toHaveLength(1)
    // …and a card opens the content's full analysis in a popup over Home.
    fireEvent.click(cards[0])
    expect(screen.getByTestId('ad-preview-dialog')).toBeInTheDocument()
    expect(screen.getByTestId('watch-open-analytics')).toHaveAttribute('href', '/agency/analytics')
  })

  it('WATCH-BOARD-001 — is not offered to a member who cannot open the campaigns', () => {
    page(base({ can_watch: false }))
    expect(screen.queryByTestId('watch-board')).not.toBeInTheDocument()
  })

  it('FINANCE-HOME-SCOPE-001 — the finance page never carries the campaigns board, even for a member who may open campaigns', () => {
    page(base({ can_watch: true, view: 'finance' }))
    expect(screen.queryByTestId('watch-board')).not.toBeInTheDocument()
  })
})

describe('ANALYTICS-POPUP-001 — Home opens the matching analytics section in a popup', () => {
  beforeEach(() => { state.data = null; state.watch = null })

  it('pressing a campaigns card opens the Performance board section over Home, view-only, and the page address does not move', async () => {
    const { user } = page(base({})) as unknown as { user?: { click: (e: Element) => Promise<void> } }
    expect(screen.queryByTestId('analytics-popup')).toBeNull()
    const card = screen.getByTestId('dash-kpi-active')
    if (user) await user.click(card); else card.click()
    const popup = await screen.findByTestId('analytics-popup')
    expect(popup.getAttribute('data-section')).toBe('board')
    // The only link out is «افتح التحليلات», to the same section of the board — never a campaign's controls.
    expect(screen.getByTestId('analytics-popup-open').getAttribute('href')).toBe('/agency/analytics?tab=s_board')
  })

  it('a card that is not an analytics matter (alerts) keeps its link', () => {
    page(base({}))
    expect(screen.getByTestId('dash-kpi-alerts')).toHaveAttribute('href', '/agency/alerts')
  })
})

/** DELIVERY-ALL-ROLES-001 — running or stopped on every role's page; the money beside it only when the server sends it. */
describe('running or stopped, merged with the budget, for every role', () => {
  const delivery: DeliveryRow[] = [
    { id: 'c1', kind: 'client' as const, name: 'Alpha', delivering: true, delivering_platforms: { meta: true, snapchat: false }, budget: 1000, spent: 700, remaining: 300, ratio: 0.7, currency: 'SAR' },
    { id: 'c2', kind: 'client' as const, name: 'Beta', delivering: false, delivering_platforms: { tiktok: false }, budget: 500, spent: 100, remaining: 400, ratio: 0.2, currency: 'SAR' },
  ]

  for (const view of ['manager', 'pm', 'finance', 'marketer'] as const) {
    it(`draws it on the ${view} page`, () => {
      page(base({ view, delivery }))
      const panel = screen.getByTestId('dash-panel-delivery')
      expect(within(panel).getByTestId('dash-delivery-on')).toHaveTextContent('1')
      expect(within(panel).getByTestId('dash-delivery-off')).toHaveTextContent('1')
      expect(within(panel).getByTestId('dash-delivery-left-c2')).toBeInTheDocument()
      const alpha = within(panel).getByTestId('dash-delivery-platforms-c1')
      expect(within(alpha).getByTestId('live-platform-meta').dataset.on).toBe('true')
      expect(within(alpha).getByTestId('live-platform-snapchat').dataset.on).toBe('false')
    })
  }

  it('reads as a table like the budgets table: one header row, the clients under it (DELIVERY-TABLE-001)', () => {
    page(base({ view: 'manager', delivery }))
    const panel = screen.getByTestId('dash-panel-delivery')
    const header = within(panel).getByTestId('dash-delivery-header')
    expect(header.children).toHaveLength(5)
    expect(header).toHaveTextContent(/Client · project.*Status.*Platforms.*Spent.*Remaining/)
    expect(within(panel).getByTestId('dash-delivery-c1')).toHaveTextContent('Client')
  })

  it('shows the state with no money for a member without the money key', () => {
    page(base({ view: 'marketer', can_money: false, money: null, units: [], delivery: delivery.map((r) => ({ ...r, budget: null, spent: null, remaining: null, ratio: null })) }))
    const panel = screen.getByTestId('dash-panel-delivery')
    expect(within(panel).getByTestId('dash-delivery-c1')).toHaveTextContent('Alpha')
    expect(within(panel).queryByTestId('dash-delivery-left-c2')).toBeNull()
    expect(panel.textContent).not.toMatch(/400|300/)
  })
})
