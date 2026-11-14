import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'
import type { ClientBudgetRow } from '@/features/agency/api'
import { OverviewDashboard } from './OverviewDashboard'
import { ClientCards } from './ClientCards'

const row = (over: Partial<ClientBudgetRow>): ClientBudgetRow => ({
  client_id: 'c1', client_name: 'Alpha', projects: 1, campaigns: 2, budget: 1000, spent: 400, remaining: 600, projected: null, pace: null,
  currency: 'SAR', currencies: 1, excluded: 0, daily_spend_7d: [100, 100, 100, 100, 100, 100, 100],
  projects_breakdown: [{ project_id: 'p1', project_name: 'P1', campaigns: 2, budget: 1000, spent: 400, remaining: 600, projected: null, pace: null, currency: 'SAR', currencies: 1, excluded: 0 }] as never,
  ...over,
})

const rows = [
  row({}),
  row({ client_id: 'c2', client_name: 'Beta', spent: 100, remaining: 2900, budget: 3000, projects_breakdown: [] }),
]

vi.mock('@/features/agency/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/agency/api')>()),
  fetchClientBudgets: vi.fn(() => Promise.resolve(rows)),
}))
vi.mock('@/features/alerts/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/alerts/api')>()),
  listAlertEvents: vi.fn(() => Promise.resolve({ events: [{ id: 'a', status: 'open', severity: 'warning', project_id: 'p1' }] })),
}))
vi.mock('@/features/agency/AgencyDashboardPage', () => ({
  AgencyDashboardPage: ({ onOpenClient }: { onOpenClient?: (r: ClientBudgetRow) => void }) => (
    <div data-testid="agency-page">{onOpenClient && <ClientCards ar={false} onOpenClient={onOpenClient} />}</div>
  ),
}))
vi.mock('@/features/analytics/AnalyticsPage', () => ({
  AnalyticsPage: ({ surface }: { surface: string }) => <div data-testid="single-client">{surface}</div>,
}))

describe('«كل العملاء»', () => {
  beforeEach(() => {
    useAgencyClient.getState().setCurrentClientId(null)
    useProject.getState().setCurrentProjectId(null)
  })

  it('shows four figures that add up to the cards, nearest-to-running-out first', async () => {
    renderWithProviders(<OverviewDashboard portal="agency" />, { route: '/agency/dashboard' })

    expect(await screen.findByTestId('overview-client-c1')).toBeTruthy()
    // 400 + 100 spent, 600 + 2900 remaining — summed from the same rows.
    expect(screen.getByTestId('overview-spend').textContent).toContain('500')
    expect(screen.getByTestId('overview-remaining').textContent).toContain('3.5K')
    expect(screen.getByTestId('overview-running').textContent).toContain('2')
    // Alpha has 6 days of cover (a week or less) and one open alert; Beta has 29.
    expect(screen.getByTestId('overview-near').textContent).toContain('1')
    const order = screen.getAllByTestId(/overview-client-/).map((el) => el.getAttribute('data-testid'))
    expect(order).toEqual(['overview-client-c1', 'overview-client-c2'])
    expect(screen.getByTestId('overview-client-c1').textContent).toContain('6 days left')
  })

  it('opens one client at the second level, with a trail that returns', async () => {
    renderWithProviders(<OverviewDashboard portal="agency" />, { route: '/agency/dashboard' })

    fireEvent.click(await screen.findByTestId('overview-client-c1'))

    expect((await screen.findByTestId('single-client')).textContent).toBe('dashboard')
    expect(useAgencyClient.getState().currentClientId).toBe('c1')
    expect(useProject.getState().currentProjectId).toBe('p1')
    expect(screen.getByTestId('overview-trail').textContent).toContain('Alpha')

    fireEvent.click(screen.getByText('All clients'))
    await waitFor(() => expect(screen.queryByTestId('single-client')).toBeNull())
    expect(useAgencyClient.getState().currentClientId).toBeNull()
  })

  it('brings the stores into line with an address opened cold, so the figures are the named client’s', async () => {
    useAgencyClient.getState().setCurrentClientId('someone-else')
    useProject.getState().setCurrentProjectId('their-project')
    renderWithProviders(<OverviewDashboard portal="agency" />, { route: '/agency/dashboard?client=c1' })

    expect((await screen.findByTestId('single-client')).textContent).toBe('dashboard')
    expect(useAgencyClient.getState().currentClientId).toBe('c1')
    expect(useProject.getState().currentProjectId).toBe('p1')
  })

  it('drops an address naming a client the reader cannot reach, instead of showing another client’s numbers', async () => {
    useAgencyClient.getState().setCurrentClientId('someone-else')
    renderWithProviders(<OverviewDashboard portal="agency" />, { route: '/agency/dashboard?client=nobody' })

    expect(await screen.findByTestId('overview-client-c1')).toBeTruthy()
    expect(screen.queryByTestId('single-client')).toBeNull()
  })

  it('leaves the project to the switcher when a client has several', async () => {
    renderWithProviders(<OverviewDashboard portal="agency" />, { route: '/agency/dashboard' })

    fireEvent.click(await screen.findByTestId('overview-client-c2'))

    await screen.findByTestId('single-client')
    expect(useProject.getState().currentProjectId).toBeNull()
  })

  it('is only ever the single-client dashboard in the advertiser portal, and links the projects summary', async () => {
    renderWithProviders(<OverviewDashboard portal="app" />, { route: '/app/dashboard?client=c1' })

    expect((await screen.findByTestId('single-client')).textContent).toBe('dashboard')
    expect(screen.queryByTestId('overview-trail')).toBeNull()
    expect(screen.getByTestId('overview-summary-link').getAttribute('href')).toBe('/app/portfolio')
  })
})
