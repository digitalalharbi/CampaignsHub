import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RequirePortal } from './RequirePortal'
import type { Membership, MembershipState, PortalKey } from './memberships'

vi.mock('./memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn() }
})

import { fetchMemberships } from './memberships'

function membership(portal: PortalKey): Membership {
  return {
    id: `m-${portal}`, portal, portal_path: `/${portal}`, landing_path: `/${portal}/dashboard`, role: 'owner',
    is_default: true, is_active: true,
    tenant: { id: 't1', name: 'Smart Code', slug: 'smart-code' }, client_scope_ids: [], project_scope_ids: [],
  }
}

function only(portal: PortalKey): MembershipState {
  const m = membership(portal)
  return { memberships: [m], current: m, destination: m.landing_path, needs_switcher: false, requested_portal: null, requested_portal_held: null }
}

function Where() {
  const { pathname, search, hash } = useLocation()
  return <p data-testid="where">{`${pathname}${search}${hash}`}</p>
}

/**
 * MAIL-LINK-PORTAL-001 — a digest link is written under `/app/…`. An agency member must land on the same page in the
 * agency portal, with the project, tab and period the link carried, never on a wall or the home page.
 */
describe('RequirePortal — mail links for an agency member', () => {
  let client: QueryClient

  const open = (route: string) => render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route element={<RequirePortal portal="app" />}><Route path="/app/*" element={<p>Advertiser screen</p>} /></Route>
          <Route path="/agency/*" element={<Where />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  beforeEach(() => {
    vi.clearAllMocks()
    window.localStorage.clear()
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  it('opens the content tab of the same project in the agency portal', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(only('agency'))

    open('/app/analytics?project=p1&tab=creative&days=1')

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/agency/analytics?project=p1&tab=creative&days=1'))
  })

  it('opens the project reports in the agency portal', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(only('agency'))

    open('/app/reports?project=p1')

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/agency/reports?project=p1'))
  })

  it('still refuses someone who holds neither portal', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(only('influencers'))

    open('/app/reports?project=p1')

    await waitFor(() => expect(screen.getByTestId('app-portal-denied')).toBeInTheDocument())
  })

  it('an advertiser stays in the advertiser portal', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(only('app'))

    open('/app/reports?project=p1')

    await waitFor(() => expect(screen.getByText('Advertiser screen')).toBeInTheDocument())
  })
})
