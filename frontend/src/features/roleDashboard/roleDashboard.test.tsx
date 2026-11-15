import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { DashboardUnit, RoleDashboard } from './api'

const state: { data: RoleDashboard | null } = { data: null }
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  useRoleDashboard: () => ({ data: state.data, isLoading: false, isError: false, error: null, refetch: vi.fn() }),
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
  return renderWithProviders(<RoleDashboardPage portal="agency" />, { route: '/agency/home', locale })
}

describe('the role dashboards', () => {
  beforeEach(() => { state.data = null })

  it('manager: figures open their pages, spend carries its status, the strip names alerts and integrations', () => {
    page(base({}))
    expect(screen.getByTestId('dash-kpi-budget')).toHaveAttribute('href', '/agency/budgets')
    expect(screen.getByTestId('dash-kpi-active')).toHaveAttribute('href', '/agency/campaigns')
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
