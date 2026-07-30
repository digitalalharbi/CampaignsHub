import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import type { AgencyDashboard } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({ fetchAgencyDashboard: vi.fn() }))

import { fetchAgencyDashboard } from './api'

function payload(over: Partial<AgencyDashboard> = {}): AgencyDashboard {
  return {
    scope: { client_count: 12, is_restricted: false },
    clients: { total: 12, active: 9, onboarding: 2, needs_attention: 1 },
    projects: { total: 20, active: 14 },
    campaigns: { total: 31, active: 22, paused: 4, by_objective: { sales: 18, awareness: 13 } },
    requests: { open: 5, awaiting_client: 2 },
    ...over,
  }
}

describe('AgencyDashboardPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('reports the whole agency when the membership is unrestricted', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(payload())
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('agency-scope-banner')).toBeInTheDocument())
    expect(screen.getByTestId('agency-scope-banner')).toHaveTextContent('whole agency')
    expect(screen.getByText('31')).toBeInTheDocument()
  })

  /**
   * The boundary must be stated ABOVE the figures. A scoped operator reading "12 clients" as the
   * agency's total is the failure this guards: every number below is a subset, and the page says so.
   */
  it('says plainly when the figures cover only the operator’s own clients', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(
      payload({ scope: { client_count: 3, is_restricted: true }, clients: { total: 3, active: 3, onboarding: 0, needs_attention: 0 } }),
    )
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('agency-scope-banner')).toBeInTheDocument())
    const banner = screen.getByTestId('agency-scope-banner')
    expect(banner).toHaveTextContent('names specific clients')
    expect(banner).toHaveTextContent('3')
    expect(banner).not.toHaveTextContent('whole agency')
  })

  /** An empty agency shows zeros — never a sample figure standing in for a real one. */
  it('shows zeros rather than sample data for an empty agency', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(
      payload({
        scope: { client_count: 0, is_restricted: false },
        clients: { total: 0, active: 0, onboarding: 0, needs_attention: 0 },
        projects: { total: 0, active: 0 },
        campaigns: { total: 0, active: 0, paused: 0, by_objective: {} },
        requests: { open: 0, awaiting_client: 0 },
      }),
    )
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Campaigns by objective')).toBeInTheDocument())
    expect(screen.getByText('No campaigns within your scope yet.')).toBeInTheDocument()
    expect(screen.getAllByText('0').length).toBeGreaterThanOrEqual(4)
  })

  it('breaks campaigns down by objective, largest first', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(
      payload({ campaigns: { total: 6, active: 6, paused: 0, by_objective: { awareness: 1, sales: 5 } } }),
    )
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Sales')).toBeInTheDocument())
    const labels = screen.getAllByText(/^(Sales|Awareness)$/).map((n) => n.textContent)
    expect(labels).toEqual(['Sales', 'Awareness'])
  })

  /** A failed load must not fall back to plausible-looking numbers. */
  it('shows an error rather than estimated figures when the load fails', async () => {
    vi.mocked(fetchAgencyDashboard).mockRejectedValue(new Error('boom'))
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })

    await waitFor(() =>
      expect(screen.getByText('The agency overview could not be loaded.')).toBeInTheDocument(),
    )
    expect(screen.queryByTestId('agency-scope-banner')).not.toBeInTheDocument()
  })
})
