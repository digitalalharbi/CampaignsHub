import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { AgencyDashboardPage } from './AgencyDashboardPage'
import type { AgencyDashboard } from './api'
import { renderWithProviders } from '@/test/utils'

/* The client-budget rung is its own query on this page — stubbed empty so it renders nothing here. */
vi.mock('./api', () => ({ fetchAgencyOverview: vi.fn(() => Promise.resolve({ scope: { client_count: 0, project_count: 0, is_restricted: false }, period: { from: '2026-09-11', to: '2026-10-10' }, previous_period: { from: '2026-08-12', to: '2026-09-10' }, currency: null, current: null, previous: null, by_provider: [], timeseries: [], freshness: { last_synced_at: null } })),  fetchAgencyDashboard: vi.fn(), fetchClientBudgets: vi.fn(() => Promise.resolve([])) }))

vi.mock('@/features/alerts/api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listAlertEvents: vi.fn(() => Promise.resolve({ events: [], total: 0, counts: { open: 0, snoozed: 0, resolved: 0, open_critical: 0 } })) }))
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

  /**
   * Owner directive 2026-10-09 §11 — the Dashboard's one question is «what needs attention now?» —
   * and the standing feature-first rule: the feature at the top, the charts below it.
   *
   * The page opened with four count cards, a client-mix bar and an objective chart, and put the
   * attention block at the fold; the client pace and budgets — the money signals — sat below a
   * three-thousand-pixel creative section. Asserted as DOM order, so a chart cannot be moved back
   * above the answer without this failing.
   */
  it('answers «what needs attention» before it draws the charts', async () => {
    vi.mocked(fetchAgencyDashboard).mockResolvedValue(payload())
    renderWithProviders(<AgencyDashboardPage />, { route: '/agency/dashboard', locale: 'en' })
    await waitFor(() => expect(screen.getByTestId('agency-objective-chart')).toBeInTheDocument())

    const precedes = (a: Element, b: Element) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
    const attention = screen.getByTestId('agency-attention')
    const mix = screen.getByTestId('client-mix-bar')
    const chart = screen.getByTestId('agency-objective-chart')

    expect(precedes(screen.getByTestId('agency-intro'), attention), 'the context must still open the page').toBe(true)
    expect(precedes(attention, mix), 'the client-mix chart is drawn before the attention block').toBe(true)
    expect(precedes(attention, chart), 'the objective chart is drawn before the attention block').toBe(true)
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
