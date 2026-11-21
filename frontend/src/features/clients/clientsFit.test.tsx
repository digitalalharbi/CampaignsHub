import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ClientsPortfolioPage } from './ClientsPortfolioPage'
import { listClients, type ClientCard } from './api'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listClients: vi.fn(), getTaxonomy: vi.fn().mockResolvedValue({ assignable_users: [] }) }))
vi.mock('@/features/taxonomy/taxonomyApi', () => ({ useTaxonomyOptions: () => ({ options: [], isPending: false, error: null, refetch: vi.fn() }) }))

const client = (n: number, over: Partial<ClientCard> = {}): ClientCard => ({
  id: `c${n}`, name: `Client ${n}`, client_status: 'active', service_level: null, industry: null, priority: null, owner_id: null,
  is_archived: false, projects: 1, active_campaigns: 2, open_requests: 3, alerts: 0, spend: 100, spend_currency_mode: 'single',
  currency: 'SAR', data_sources: ['meta'], last_report_at: null, last_sync_at: '2026-11-15 10:30:00', ...over,
})

/** NOV-FIT-001 — the clients page asks for what fits, pages the rest, and counts its figures over the whole roster. */
describe('the clients page fits the screen', () => {
  beforeEach(() => {
    signInWith(['clients.view'])
    vi.mocked(listClients).mockImplementation(async (f) => {
      const all = Array.from({ length: 30 }, (_, i) => client(i + 1, i % 3 === 0 ? { client_status: 'needs_attention' } : {}))
      const per = f.per_page ?? 24
      const page = f.page ?? 1

      return { data: all.slice((page - 1) * per, page * per), meta: { total: 30, per_page: per, current_page: page, last_page: Math.ceil(30 / per) } }
    })
  })
  afterEach(() => { signOut(); vi.clearAllMocks(); localStorage.clear() })

  it('sends a per_page the screen can hold, and pages through the rest', async () => {
    renderWithProviders(<ClientsPortfolioPage />, { route: '/agency/clients', locale: 'en' })

    await waitFor(() => expect(listClients).toHaveBeenCalledWith(expect.objectContaining({ page: 1, per_page: expect.any(Number) })))
    const perPage = vi.mocked(listClients).mock.calls.find(([f]) => f.per_page !== 100)![0].per_page!
    expect(perPage).toBeGreaterThan(2)
    expect(perPage).toBeLessThan(30)

    expect(await screen.findByText('Client 1')).toBeInTheDocument()
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
    await waitFor(() => expect(listClients).toHaveBeenCalledWith(expect.objectContaining({ page: 2, per_page: perPage })))
    expect(await screen.findByText(`Client ${perPage + 1}`)).toBeInTheDocument()
  })

  it('counts its figures over the whole roster, not the page on screen', async () => {
    renderWithProviders(<ClientsPortfolioPage />, { route: '/agency/clients', locale: 'en' })

    await waitFor(() => expect(listClients).toHaveBeenCalledWith(expect.objectContaining({ per_page: 100 })))
    // 30 clients, every third needs attention: 10 of them, 20 active.
    await waitFor(() => expect(screen.getByTestId('clients-intro-kpis')).toHaveTextContent('20'))
    expect(screen.getByTestId('clients-intro-kpis')).toHaveTextContent('10')
    expect(screen.getByTestId('clients-intro-kpis')).toHaveTextContent('30')
  })

  it('opens the rest of a row in a panel, and reads a spaced date without «Invalid Date»', async () => {
    localStorage.setItem('clients_view', 'table')
    renderWithProviders(<ClientsPortfolioPage />, { route: '/agency/clients', locale: 'en' })

    fireEvent.click((await screen.findAllByRole('button', { name: /^Details: Client 1$/ }))[0])
    const body = await screen.findByTestId('client-detail-body')
    expect(body).toHaveTextContent('2026-11-15')
    expect(body).not.toHaveTextContent('Invalid')
  })
})
