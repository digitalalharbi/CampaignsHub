import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/reports/PublicReport', () => ({ PublicReport: ({ token }: { token: string }) => <div data-testid="public-report">{token}</div> }))
vi.mock('./ClientLiveDashboard', () => ({ ClientLiveDashboard: () => <div data-testid="live-dashboard" /> }))
vi.mock('./PortalAudience', () => ({ PortalAudience: () => null }))
vi.mock('./PortalAttention', () => ({ PortalAttention: () => null }))
vi.mock('../clientPortalApi', async (o) => ({ ...(await o<typeof import('../clientPortalApi')>()), listPortalRequests: vi.fn().mockResolvedValue({ requests: [] }) }))
vi.mock('./portalAccountApi', async (o) => ({
  ...(await o<typeof import('./portalAccountApi')>()),
  listPortalQuotes: vi.fn().mockResolvedValue({ items: [] }), listPortalInvoices: vi.fn().mockResolvedValue({ items: [] }),
  listPortalThreads: vi.fn().mockResolvedValue({ items: [] }), listPortalContentApprovals: vi.fn().mockResolvedValue({ items: [] }),
  listClientReports: vi.fn(),
}))
vi.mock('./usePortalVisibility', () => ({ usePortalVisibility: () => ({ allows: () => true }) }))

import { listClientReports } from './portalAccountApi'
import { ClientDashboardPage } from './ClientDashboardPage'

/** PORTAL-REPORT-HOME-001 — the client's shared report opens as the home; the live dashboard only stands in without one. */
describe('the portal home', () => {
  beforeEach(() => vi.clearAllMocks())

  it('opens the latest shared report straight away and switches between reports', async () => {
    vi.mocked(listClientReports).mockResolvedValue([
      { id: 'r-1', name: 'Monthly', share: { shared: true } },
      { id: 'r-2', name: 'Weekly', share: { shared: true } },
      { id: 'r-3', name: 'Draft', share: { shared: false } },
    ] as never)
    renderWithProviders(<ClientDashboardPage />, { locale: 'en', route: '/portal/nasel', path: '/portal/:clientSlug' })

    expect(await screen.findByTestId('public-report')).toHaveTextContent('portal:r-1')
    expect(screen.queryByTestId('portal-home-report-r-3')).toBeNull()
    fireEvent.click(screen.getByTestId('portal-home-report-r-2'))
    expect(await screen.findByTestId('public-report')).toHaveTextContent('portal:r-2')
    expect(screen.queryByTestId('live-dashboard')).toBeNull()
  })

  it('shows the live dashboard when no report is shared', async () => {
    vi.mocked(listClientReports).mockResolvedValue([] as never)
    renderWithProviders(<ClientDashboardPage />, { locale: 'en', route: '/portal/nasel', path: '/portal/:clientSlug' })

    expect(await screen.findByTestId('live-dashboard')).toBeInTheDocument()
  })
})
