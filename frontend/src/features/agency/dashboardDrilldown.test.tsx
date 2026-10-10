import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import { ClientsPortfolioPage } from '@/features/clients/ClientsPortfolioPage'
import { RequestsDashboardPage } from '@/features/requests/RequestsDashboardPage'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn(() => Promise.resolve([])) }))
vi.mock('@/features/clients/api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listClients: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) }))
vi.mock('@/features/requests/internalApi', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listRequests: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) }))
import { fetchAgencyDashboard } from './api'
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

describe('the agency dashboard’s attention rows', () => {
  beforeEach(() => { signInWith(['campaigns.view']); vi.mocked(fetchAgencyDashboard).mockResolvedValue(payload() as never) })
  afterEach(() => signOut())

  it('link to the filtered view each count describes', async () => {
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    const row = (label: string) => screen.findByRole('link', { name: new RegExp(label) })
    expect(await row('Clients needing attention')).toHaveAttribute('href', '/agency/clients?status=needs_attention')
    expect(await row('Clients onboarding')).toHaveAttribute('href', '/agency/clients?status=onboarding')
    expect(await row('Requests awaiting the client')).toHaveAttribute('href', '/agency/requests?status=client_review')
    expect(await row('Paused campaigns')).toHaveAttribute('href', '/agency/campaigns?view=table&lifecycle=all&band=paused')
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
