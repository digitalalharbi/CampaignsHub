import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import { renderWithProviders } from '@/test/utils'
import type { AgencyDashboard, ClientBudgetRow } from './api'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn() }))

import { fetchAgencyDashboard, fetchClientBudgets } from './api'

/**
 * BUDGET-GOVERNANCE-001 — the PROJECT rung, reachable from the client that raised the question.
 *
 * The client table said a client was pacing at 1.4× and named no project responsible for it, so the
 * operator left the screen, guessed a project and opened it to find out which one. The rung is the
 * same roll-up at a finer grain; here it is the answer to the question the row above it just asked.
 */
const dashboard = (): AgencyDashboard => ({
  scope: { client_count: 1, is_restricted: false, reachable: null },
  clients: { total: 1, active: 1, onboarding: 0, needs_attention: 0 },
  projects: { total: 2, active: 2 },
  campaigns: { total: 3, active: 3, paused: 0, by_objective: [] },
  requests: { open: 0, awaiting_client: 0 },
} as unknown as AgencyDashboard)

const project = (over: Record<string, unknown>) => ({
  project_id: 'p1', project_name: 'Riyadh launch', campaigns: 2,
  budget: 40_000, spent: 30_000, remaining: 10_000, projected: 48_000,
  pace: 1.2, currency: 'SAR', currencies: 1, excluded: 0,
  ...over,
})

const client = (over: Partial<ClientBudgetRow> = {}): ClientBudgetRow => ({
  client_id: 'c1', client_name: 'Acme', projects: 2, campaigns: 3,
  budget: 50_000, spent: 32_000, remaining: 18_000, projected: 60_000,
  pace: 1.2, currency: 'SAR', currencies: 1, excluded: 0,
  projects_breakdown: [
    project({}),
    project({ project_id: 'p2', project_name: 'Jeddah retainer', budget: 10_000, spent: 2_000, remaining: 8_000, projected: 4_000, pace: 0.4, campaigns: 1 }),
  ],
  ...over,
} as ClientBudgetRow)

describe('the project rung under the client that raised the question', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(dashboard())
  })

  it('names the projects a client’s money is in', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([client()])
    renderWithProviders(<AgencyDashboardPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Acme/ }))

    const panel = await screen.findByTestId('project-budgets')
    expect(panel.textContent).toContain('Riyadh launch')
    expect(panel.textContent).toContain('Jeddah retainer')
  })

  it('offers no drill-down for a client whose projects hold nothing', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([client({ projects_breakdown: [] })])
    renderWithProviders(<AgencyDashboardPage />)

    expect(await screen.findByText('Acme')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Acme/ })).toBeNull()
  })
})
