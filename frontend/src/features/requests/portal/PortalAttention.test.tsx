import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { PortalAttention } from './PortalAttention'
import { renderWithProviders } from '@/test/utils'
import type { PortalOverview } from './portalOverviewApi'

vi.mock('./portalOverviewApi', async (orig) => ({ ...(await orig<typeof import('./portalOverviewApi')>()), fetchPortalOverview: vi.fn() }))
import { fetchPortalOverview } from './portalOverviewApi'

const base = (attention?: PortalOverview['attention']): PortalOverview => ({
  space: { slug: 'razzah', name: 'Razzah', mode: 'managed' }, period: { from: '', to: '', days: 30 }, currency: null,
  freshness: { state: null, last_sync_at: null }, kpis: {}, platforms: [], projects: [], campaigns: null, attention,
})

describe('PortalAttention', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists only what is waiting on the client, each linking to its list', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base({ requests_waiting: 2, invoices_overdue: 1, invoices_due: 0 }))
    renderWithProviders(<PortalAttention />, { locale: 'en', route: '/portal/razzah' })

    expect(await screen.findByText('2 requests are waiting on you')).toBeInTheDocument()
    expect(screen.getByText('1 invoice is overdue')).toBeInTheDocument()
    expect(screen.queryByTestId('attention-due')).not.toBeInTheDocument()
    expect(screen.getByTestId('attention-overdue')).toHaveAttribute('href', '/portal/razzah/invoices')
  })

  it('draws nothing when there is nothing to do, or when the server sent no counts', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base({ requests_waiting: 0, invoices_overdue: 0, invoices_due: 0 }))
    const { container } = renderWithProviders(<PortalAttention />, { locale: 'en', route: '/portal/razzah' })
    await vi.waitFor(() => expect(fetchPortalOverview).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="portal-attention"]')).toBeNull()
  })

  it('speaks Arabic with the right plural', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base({ invoices_overdue: 1 }))
    renderWithProviders(<PortalAttention />, { locale: 'ar', route: '/portal/razzah' })

    expect(await screen.findByText('1 فاتورة متأخرة')).toBeInTheDocument()
  })
})
