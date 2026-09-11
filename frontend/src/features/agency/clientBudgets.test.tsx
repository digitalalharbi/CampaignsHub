import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import { renderWithProviders } from '@/test/utils'
import type { AgencyDashboard, ClientBudgetRow } from './api'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn() }))

import { fetchAgencyDashboard, fetchClientBudgets } from './api'

/**
 * BUDGET-GOVERNANCE-001 — «which client is overspending», on the page an agency opens first.
 *
 * This dashboard carried counts of clients, projects and campaigns and no money at all, so the
 * question could not be asked anywhere in the product. The figures are the same aggregator's, rolled
 * up per client — nothing here is a second budget engine.
 */
const dashboard = (): AgencyDashboard => ({
  scope: { client_count: 2, is_restricted: false, reachable: null },
  clients: { total: 2, active: 2, onboarding: 0, needs_attention: 0 },
  projects: { total: 2, active: 2 },
  campaigns: { total: 2, active: 2, paused: 0, by_objective: [] },
  requests: { open: 0, awaiting_client: 0 },
} as unknown as AgencyDashboard)

const row = (over: Partial<ClientBudgetRow>): ClientBudgetRow => ({
  client_id: 'c1', client_name: 'Big Spender', projects: 2, campaigns: 3,
  budget: 60_000, spent: 10_000, remaining: 50_000, projected: 72_000,
  pace: 1.2, currency: 'SAR', currencies: 1, excluded: 0, projects_breakdown: [],
  ...over,
})

describe('the agency dashboard carries the client budget rung', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
  })

  it('lists each client against its committed budget', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([row({}), row({ client_id: 'c2', client_name: 'Small', budget: 5_000, pace: 0.4 })])
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    const section = await screen.findByTestId('client-budgets')
    expect(section).toHaveTextContent('Big Spender')
    expect(section).toHaveTextContent('Small')
    expect(section).toHaveTextContent(/Forecast/i)
  })

  /**
   * A client whose figures cannot be added shows «—», never a total built from two currencies.
   *
   * That is the money contract carried up to the rung an agency reads first, where a wrong number
   * would be acted on across every client at once.
   */
  it('refuses a total it cannot compute', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([
      row({ budget: null, spent: null, remaining: null, projected: null, pace: null, currency: null, currencies: 2 }),
    ])
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    const section = await screen.findByTestId('client-budgets')
    expect(section).toHaveTextContent('Big Spender')
    expect(section).toHaveTextContent('—')
  })

  /** What the total left out is said where the total is read — no silent caps. */
  it('counts what it excluded', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([row({ excluded: 2 })])
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('client-budgets')).toHaveTextContent(/2 excluded/i)
  })

  /** A budget that fails to load must not take the counts down with it. */
  it('leaves the rest of the dashboard standing when the budgets fail', async () => {
    vi.mocked(fetchClientBudgets).mockRejectedValue(new Error('nope'))
    renderWithProviders(<AgencyDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('agency-scope-banner')).toBeVisible()
    expect(screen.queryByTestId('client-budgets')).toBeNull()
  })
})
