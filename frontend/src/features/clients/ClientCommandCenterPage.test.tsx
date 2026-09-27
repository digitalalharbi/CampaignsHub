import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { ClientCommandCenterPage } from './ClientCommandCenterPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getClient: vi.fn() }
})

import { getClient } from './api'

function httpError(status: number): AxiosError {
  const error = new AxiosError('failed')
  error.response = {
    status,
    statusText: '',
    data: { message: 'no', errors: null },
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  }

  return error
}

/**
 * Typing another agency client's id into the URL is the cheapest attack there is, and the page now
 * serves an agency portal where most clients are out of scope by design. The server refuses with 403;
 * what these cover is that the REFUSAL reads as a boundary and does not take the route down — the
 * first version dereferenced the missing payload and crashed the whole router.
 */
describe('ClientCommandCenterPage — a client outside the caller’s scope', () => {
  beforeEach(() => vi.clearAllMocks())

  it('explains the boundary instead of crashing on a 403', async () => {
    vi.mocked(getClient).mockRejectedValue(httpError(403))
    renderWithProviders(<ClientCommandCenterPage />, {
      route: '/agency/clients/other-agency-client',
      path: '/agency/clients/:clientId',
      locale: 'en',
    })

    await waitFor(() => expect(screen.getByTestId('client-out-of-scope')).toBeInTheDocument())
    expect(screen.getByText('This client is outside your access')).toBeInTheDocument()
  })

  /** A missing id must not read differently from a forbidden one, or the pair becomes a probe. */
  it('says the same thing for a 404', async () => {
    vi.mocked(getClient).mockRejectedValue(httpError(404))
    renderWithProviders(<ClientCommandCenterPage />, {
      route: '/agency/clients/does-not-exist',
      path: '/agency/clients/:clientId',
      locale: 'en',
    })

    await waitFor(() => expect(screen.getByTestId('client-out-of-scope')).toBeInTheDocument())
  })

  /** A server fault is a different thing and must not be dressed up as a permissions boundary. */
  it('does not present a server error as a scope boundary', async () => {
    vi.mocked(getClient).mockRejectedValue(httpError(500))
    renderWithProviders(<ClientCommandCenterPage />, {
      route: '/agency/clients/c1',
      path: '/agency/clients/:clientId',
      locale: 'en',
    })

    await waitFor(() => expect(screen.queryByTestId('client-out-of-scope')).not.toBeInTheDocument())
  })

  /** The way back stays inside the portal the operator is in. */
  it('offers a way back that stays in the current portal', async () => {
    vi.mocked(getClient).mockRejectedValue(httpError(403))
    renderWithProviders(<ClientCommandCenterPage />, {
      route: '/agency/clients/x',
      path: '/agency/clients/:clientId',
      locale: 'en',
    })

    await waitFor(() => expect(screen.getByTestId('client-out-of-scope')).toBeInTheDocument())
    expect(screen.getByRole('link')).toHaveAttribute('href', '/agency/clients')
  })

  /**
   * Every counter on the overview is named in the reader's language.
   *
   * Three of the four went through `t(...)` and the fourth was the literal `'Draft'`, so an Arabic
   * command centre read «المشاريع · حملات نشطة · Draft · طلبات مفتوحة». Seen on the rendered page
   * at 1440 — it is the kind of thing that survives review because the line looks like its
   * neighbours until you read the argument.
   */
  it('names every overview counter in the reader’s language', async () => {
    vi.mocked(getClient).mockResolvedValue({
      id: 'c1', name: 'Northwind', client_status: 'active', service_level: null,
      industry: null, source: null, classification: {}, is_archived: false, archived_at: null,
      can: {
        update: false, manage_settings: false, manage_team: false, archive: false,
        view_analytics: false, view_reports: false, manage_files: false,
      },
      overview: { projects: 0, active_campaigns: 0, draft_campaigns: 0, open_requests: 1 },
      projects: [{ id: 'p1', name: 'Q3 Launch', status: 'active', created_at: null }],
      campaigns: [{
        id: 'k1', project_id: 'p1', name: 'National Day', objective: 'sales',
        status: 'paused', budget: null, currency: 'SAR',
      }],
      requests: [],
    } as never)

    renderWithProviders(<ClientCommandCenterPage />, { locale: 'ar' })

    expect(await screen.findByText('حملات مسودة')).toBeInTheDocument()
    expect(screen.queryByText('Draft')).not.toBeInTheDocument()
  })

  /**
   * The lists name their states, rather than printing the column.
   *
   * The projects tab rendered `p.status` and the campaigns tab `c.objective` and `c.status`
   * verbatim, so an Arabic command centre listed «active», «sales», «paused». Every one of those
   * already has a written name elsewhere: the portfolio card calls `projectStatusLabel`, and the
   * campaigns feature owns `campaignStatusLabel` and `objectiveLabel`. Importing them is also what
   * stops the two surfaces drifting into different words for the same state.
   */
  it('names a project’s and a campaign’s state instead of printing the column', async () => {
    renderWithProviders(<ClientCommandCenterPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByRole('button', { name: 'المشاريع' }))
    expect(await screen.findByText('Q3 Launch')).toBeInTheDocument()
    expect(screen.queryByText('active')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'الحملات' }))
    expect(await screen.findByText('National Day')).toBeInTheDocument()
    expect(screen.queryByText('paused')).not.toBeInTheDocument()
    expect(screen.queryByText('sales')).not.toBeInTheDocument()
  })
})
