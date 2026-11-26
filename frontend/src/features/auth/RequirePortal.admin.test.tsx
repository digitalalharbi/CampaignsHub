import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RequirePortal } from './RequirePortal'
import type { MembershipState } from './memberships'

vi.mock('./memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn() }
})

import { fetchMemberships } from './memberships'

const none = (platformAdmin: boolean): MembershipState => ({
  memberships: [], current: null, destination: platformAdmin ? '/admin' : '/register', needs_switcher: false,
  requested_portal: null, requested_portal_held: null, platform_admin: platformAdmin,
})

/** ADMIN-NO-WORKSPACE-001 — the platform admin with no workspace goes to /admin, never to «البوابة غير متاحة لحسابك». */
describe('RequirePortal — platform admin with no workspace', () => {
  let client: QueryClient

  const open = (route: string) => render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route element={<RequirePortal portal="app" />}><Route path="/app/*" element={<p>Advertiser screen</p>} /></Route>
          <Route path="/admin" element={<p>Admin console</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  beforeEach(() => {
    vi.clearAllMocks()
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  it('takes the admin to /admin', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(none(true))
    open('/app/dashboard')
    await waitFor(() => expect(screen.getByText('Admin console')).toBeInTheDocument())
    expect(screen.queryByTestId('app-portal-denied')).not.toBeInTheDocument()
  })

  it('still shows the way out to someone with no workspace who is not the admin', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(none(false))
    open('/app/dashboard')
    await waitFor(() => expect(screen.getByTestId('app-portal-denied')).toBeInTheDocument())
  })
})
