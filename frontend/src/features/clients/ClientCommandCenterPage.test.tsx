import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
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
})
