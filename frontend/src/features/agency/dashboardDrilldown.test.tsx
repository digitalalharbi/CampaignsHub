import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import { ClientsPortfolioPage } from '@/features/clients/ClientsPortfolioPage'
import { RequestsDashboardPage } from '@/features/requests/RequestsDashboardPage'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn() }))
vi.mock('@/features/clients/api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listClients: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) }))
vi.mock('@/features/requests/internalApi', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listRequests: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) }))
import { fetchAgencyDashboard, fetchClientBudgets, type ClientBudgetRow } from './api'
import { listClients } from '@/features/clients/api'
import { listRequests } from '@/features/requests/internalApi'

/**
 * DASHBOARD-DRILLDOWN-001 — each «needs your attention» row lands on exactly what it counts.
 */
const payload = () => ({
  scope: { all_clients: true, client_count: 6 },
  clients: { total: 6, active: 6, onboarding: 1, needs_attention: 2 },
  projects: { total: 5, active: 5 },
  campaigns: { total: 23, active: 19, paused: 3, by_objective: [] },
  requests: { open: 5, awaiting_client: 2 },
})

const paused = (projects: Array<{ project_id: string; project_name: string; paused: number }>) => {
  const d = payload()
  return { ...d, campaigns: { ...d.campaigns, paused: projects.reduce((n, p) => n + p.paused, 0), paused_by_project: projects } }
}

const budgetRow = (over: Partial<ClientBudgetRow> = {}): ClientBudgetRow => ({
  client_id: 'c1', client_name: 'Acme', projects: 1, campaigns: 3, budget: 1000, spent: 200, remaining: 800, projected: 600,
  pace: 0.6, currency: 'SAR', currencies: 1, excluded: 0,
  projects_breakdown: [{ project_id: 'p9', project_name: 'Q3 Launch', campaigns: 3, budget: 1000, spent: 200, remaining: 800, projected: 600, pace: 0.6, currency: 'SAR', currencies: 1, excluded: 0 }],
  ...over,
})

describe('the agency dashboard’s attention rows', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(payload() as never)
    vi.mocked(fetchClientBudgets).mockResolvedValue([])
  })
  afterEach(() => signOut())

  it('link to the filtered view each count describes', async () => {
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    const row = (label: string) => screen.findByRole('link', { name: new RegExp(label) })
    expect(await row('Clients needing attention')).toHaveAttribute('href', '/agency/clients?status=needs_attention')
    expect(await row('Clients onboarding')).toHaveAttribute('href', '/agency/clients?status=onboarding')
    expect(await row('Requests awaiting the client')).toHaveAttribute('href', '/agency/requests?status=client_review')
    expect(await row('Paused campaigns')).toHaveAttribute('href', '/agency/campaigns?view=table&lifecycle=all&band=paused')
  })

  /*
   * The campaigns surface is project-scoped, so the paused count — which spans clients — opens as
   * what it counted only one project at a time. One project: the row IS that project's Paused view.
   */
  it('the paused row opens the one project holding them, chosen in the address', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(paused([{ project_id: 'p1', project_name: 'Q3 Launch', paused: 3 }]) as never)
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    expect(await screen.findByRole('link', { name: /Paused campaigns/ }))
      .toHaveAttribute('href', '/agency/campaigns?project=p1&view=table&lifecycle=all&band=paused')
    expect(screen.queryByTestId('attention-paused-projects')).not.toBeInTheDocument()
  })

  it('the paused row lists each project with its own count and link when several hold them', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(paused([
      { project_id: 'p1', project_name: 'Q3 Launch', paused: 2 },
      { project_id: 'p2', project_name: 'Store', paused: 1 },
    ]) as never)
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    const list = within(await screen.findByTestId('attention-paused-projects'))
    expect(list.getByRole('link', { name: /Q3 Launch/ })).toHaveAttribute('href', '/agency/campaigns?project=p1&view=table&lifecycle=all&band=paused')
    expect(list.getByRole('link', { name: /Q3 Launch/ })).toHaveTextContent('2')
    expect(list.getByRole('link', { name: /Store/ })).toHaveAttribute('href', '/agency/campaigns?project=p2&view=table&lifecycle=all&band=paused')
    // The row still carries the whole count, and opens the board with the Paused band already chosen.
    expect(screen.getByRole('link', { name: /Paused campaigns/ })).toHaveAttribute('href', '/agency/campaigns?view=table&lifecycle=all&band=paused')
  })

  it('the client-mix legend lands on the portfolio filtered to each state', async () => {
    // The bands must fit inside the whole, or the bar refuses to draw and there is no legend to link.
    const d = payload()
    vi.mocked(fetchAgencyDashboard).mockResolvedValue({ ...d, clients: { total: 6, active: 3, onboarding: 1, needs_attention: 2 } } as never)
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    const legend = within(await screen.findByTestId('client-mix-legend'))
    expect(legend.getByRole('link', { name: 'Active' })).toHaveAttribute('href', '/agency/clients?status=active')
    expect(legend.getByRole('link', { name: 'Onboarding' })).toHaveAttribute('href', '/agency/clients?status=onboarding')
    expect(legend.getByRole('link', { name: 'Needs attention' })).toHaveAttribute('href', '/agency/clients?status=needs_attention')
  })

  it('a project budget row opens that project’s campaigns, chosen in the address', async () => {
    vi.mocked(fetchClientBudgets).mockResolvedValue([budgetRow()])
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    fireEvent.click(await screen.findByRole('button', { name: 'Acme' }))
    const panel = within(await screen.findByTestId('project-budgets'))
    expect(panel.getByRole('link', { name: 'Q3 Launch' })).toHaveAttribute('href', '/agency/campaigns?project=p9&view=table&lifecycle=all')
  })
})

describe('the pages those rows open', () => {
  beforeEach(() => signInWith(['campaigns.view', 'clients.view', 'requests.view']))
  afterEach(() => signOut())

  it('the clients portfolio asks for exactly the status the link named', async () => {
    renderWithProviders(<ClientsPortfolioPage />, { route: '/agency/clients?status=needs_attention', locale: 'en' })
    await waitFor(() => expect(listClients).toHaveBeenCalledWith(expect.objectContaining({ status: 'needs_attention' })))
  })

  it('the requests board asks for exactly the status the link named', async () => {
    renderWithProviders(<RequestsDashboardPage />, { route: '/agency/requests?status=client_review', locale: 'en' })
    await waitFor(() => expect(listRequests).toHaveBeenCalledWith(expect.objectContaining({ status: 'client_review' })))
  })
})
