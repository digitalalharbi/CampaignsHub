import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import { renderWithProviders } from '@/test/utils'
import type { AgencyDashboard, ClientBudgetRow } from './api'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn() }))
vi.mock('@/features/content/CreativePulseSection', () => ({ CreativePulseSection: () => null }))

import { fetchAgencyDashboard, fetchClientBudgets } from './api'

/**
 * VIZ-AGENCY-001 — the first surface an agency opens, drawn.
 *
 * The page carried twelve counts and a table. Every one of them was true and none of them had a
 * shape: «24 clients · 18 active · 3 onboarding · 5 needing attention» is four figures a reader has
 * to divide before it answers «how much of my book is in trouble», and a pace column of
 * «1.24 / 0.88 / 1.02» is the same arithmetic one rung down.
 */
const dashboard = (over: Partial<AgencyDashboard> = {}): AgencyDashboard => ({
  scope: { client_count: 24, is_restricted: false },
  clients: { total: 24, active: 18, onboarding: 3, needs_attention: 2 },
  projects: { total: 12, active: 9 },
  campaigns: { total: 30, active: 22, paused: 8, by_objective: { sales: 12, awareness: 10, leads: 8 } },
  requests: { open: 4, awaiting_client: 1 },
  ...over,
} as AgencyDashboard)

const row = (over: Partial<ClientBudgetRow>): ClientBudgetRow => ({
  client_id: 'c1', client_name: 'Big Spender', projects: 2, campaigns: 3,
  budget: 60_000, spent: 10_000, remaining: 50_000, projected: 72_000,
  pace: 1.2, currency: 'SAR', currencies: 1, excluded: 0, projects_breakdown: [],
  ...over,
})

describe('the agency dashboard draws its own composition', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchClientBudgets).mockResolvedValue([])
  })

  it('divides the client book into its states against the total, not beside it', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('agency-intro')
    // 18 of 24 active, 3 onboarding, 2 needing attention — and 1 client in no named state at all.
    expect(screen.getByTestId('client-mix-segment-active')).toHaveStyle({ width: '75%' })
    expect(screen.getByTestId('client-mix-segment-residual')).toBeInTheDocument()
  })

  it('says what the un-named remainder of the book is rather than leaving the bar short', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('agency-intro')
    expect(screen.getByTestId('client-mix-legend')).toHaveTextContent(/Other/i)
  })

  it('draws the campaign objectives through the shared chart layer', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('agency-objective-chart')).toBeInTheDocument()
  })

  it('names an objective the platform never set rather than printing an empty row', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(
      dashboard({ campaigns: { total: 5, active: 5, paused: 0, by_objective: { '': 5 } } } as Partial<AgencyDashboard>),
    )
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('agency-objective-chart')).toHaveTextContent(/Unspecified/i)
  })

  it('ranks each client by its budget pace against a drawn line at 1.0', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({}),
      row({ client_id: 'c2', client_name: 'Careful', pace: 0.6 }),
    ])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('client-pace-row-c1')).toBeInTheDocument()
    expect(screen.getByTestId('client-pace-row-c2')).toBeInTheDocument()
    expect(screen.getByTestId('client-pace-reference')).toHaveTextContent(/On budget/i)
  })

  it('compares a client budgeted in SAR with one budgeted in USD, because a pace carries no currency', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({ currency: 'SAR', pace: 1.3 }),
      row({ client_id: 'c2', client_name: 'US client', currency: 'USD', pace: 0.8 }),
    ])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('client-pace-row-c1')).toBeInTheDocument()
    expect(screen.getByTestId('client-pace-row-c2')).toBeInTheDocument()
  })

  it('withholds a client whose own budget mixes currencies, and counts it', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({}),
      row({ client_id: 'c2', client_name: 'Mixed', currency: null, currencies: 2, pace: 1.9 }),
    ])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('client-pace-row-c1')
    expect(screen.queryByTestId('client-pace-row-c2')).toBeNull()
    expect(screen.getByTestId('client-pace-withheld')).toHaveTextContent('1')
  })

  /**
   * Owner directive 2026-10-09 §19 — «not reported» must not render as «0.00×». A client whose
   * project's window holds no measured row arrives with `pace: null` and `unmeasured > 0`; the note
   * names the reason instead of folding it into «no committed budget».
   */
  it('names a client nobody measured in this period, rather than pacing it at zero', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({}),
      row({ client_id: 'c2', client_name: 'Unmeasured', pace: null, spent: null, unmeasured: 2 }),
    ])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('client-pace-row-c1')
    expect(screen.queryByTestId('client-pace-row-c2')).toBeNull()
    expect(screen.getByTestId('client-pace-unmeasured')).toHaveTextContent('1')
    expect(screen.getByTestId('client-pace-unmeasured')).toHaveTextContent('no measured figures')
    expect(screen.queryByTestId('client-pace-withheld')).toBeNull()
    expect(screen.getByTestId('client-budget-unmeasured-c2')).toBeInTheDocument()
  })

  it('withholds a client whose pace the aggregator could not state, and counts it', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({}),
      row({ client_id: 'c2', client_name: 'No budget', pace: null, budget: null, projected: null }),
    ])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('client-pace-row-c1')
    expect(screen.queryByTestId('client-pace-row-c2')).toBeNull()
    expect(screen.getByTestId('client-pace-withheld')).toHaveTextContent('1')
  })

  it('declines the pace chart entirely when no client has a pace, without claiming anything is on budget', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([row({ pace: null, budget: null })])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    await screen.findByTestId('agency-intro')
    expect(screen.getByTestId('client-pace-empty')).toBeInTheDocument()
    expect(screen.queryByTestId('client-pace-reference')).toBeNull()
  })

  it('keeps the counts when the budget rung fails, because they are a different query', async () => {
    vi.mocked(fetchClientBudgets).mockRejectedValue(new Error('403'))
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('client-mix-bar')).toBeInTheDocument()
  })
})
