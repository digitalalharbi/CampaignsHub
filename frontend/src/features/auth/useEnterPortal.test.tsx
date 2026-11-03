import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RequireAgencyPortal } from '@/features/agency/RequireAgencyPortal'
import { RequirePortal } from './RequirePortal'
import type { Membership, MembershipState, PortalKey } from './memberships'

vi.mock('./memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn(), switchMembership: vi.fn() }
})

import { fetchMemberships, switchMembership } from './memberships'

/**
 * ENSURE-PORTAL-SESSION-001 — the server no longer moves the session when another portal's route is hit, so following a
 * link into a portal is a switch this guard makes, before anything of that portal can ask the server for anything.
 */
function membership(portal: PortalKey, tenant: string): Membership {
  return {
    id: `m-${portal}`, portal, portal_path: `/${portal}`, landing_path: `/${portal}`, role: 'owner',
    is_default: portal === 'app', is_active: false,
    tenant: { id: tenant, name: tenant, slug: tenant }, client_scope_ids: [], project_scope_ids: [],
  }
}

function inPortal(current: PortalKey): MembershipState {
  const memberships = [membership('app', 'brand'), membership('agency', 'agency')]
  return {
    memberships, current: memberships.find((m) => m.portal === current) ?? null, destination: '/switch',
    needs_switcher: true, requested_portal: null, requested_portal_held: null,
  }
}

describe('useEnterPortal', () => {
  const assign = vi.fn()
  const original = window.location
  let client: QueryClient

  const open = (route: string) => render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route element={<RequireAgencyPortal />}><Route path="/agency/*" element={<p>Agency screen</p>} /></Route>
          <Route element={<RequirePortal portal="app" />}><Route path="/app/*" element={<p>Advertiser screen</p>} /></Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  beforeEach(() => {
    vi.clearAllMocks()
    window.sessionStorage.clear()
    window.localStorage.clear()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  it('switches out loud on the way in, and draws nothing of the portal until the session has moved', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))
    let finish: (value: { current: Membership; destination: string }) => void = () => {}
    vi.mocked(switchMembership).mockReturnValue(new Promise((resolve) => { finish = resolve }))
    client.setQueryData(['projects'], [{ id: 'of-the-workspace-being-left' }])

    open('/agency/clients/7?tab=files#top')

    await waitFor(() => expect(switchMembership).toHaveBeenCalledWith('m-agency'))
    expect(screen.queryByText('Agency screen')).not.toBeInTheDocument()

    finish({ current: membership('agency', 'agency'), destination: '/agency' })

    // This tab keeps the page the link named…
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/agency/clients/7?tab=files#top'))
    // …the cross-tab signal of WORKSPACE-SWITCH-001 fires, sending the others to the portal's front door…
    expect(JSON.parse(window.localStorage.getItem('chub:workspace-switched') ?? '{}').destination).toBe('/agency')
    // …and nothing the old workspace left in the shared cache survives.
    expect(client.getQueryData(['projects'])).toBeUndefined()
    expect(screen.queryByText('Agency screen')).not.toBeInTheDocument()
  })

  it('goes the other way too', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('agency'))
    vi.mocked(switchMembership).mockResolvedValue({ current: membership('app', 'brand'), destination: '/app' })

    open('/app/projects')

    await waitFor(() => expect(switchMembership).toHaveBeenCalledWith('m-app'))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/app/projects'))
  })

  it('moves nothing when the session is already in this portal', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('agency'))

    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByText('Agency screen')).toBeInTheDocument())
    expect(switchMembership).not.toHaveBeenCalled()
    expect(assign).not.toHaveBeenCalled()
    expect(window.localStorage.getItem('chub:workspace-switched')).toBeNull()
  })

  it('keeps the screens closed when the server refuses, and offers a way out', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))
    vi.mocked(switchMembership).mockRejectedValue(new Error('refused'))

    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByTestId('portal-entry-failed')).toBeInTheDocument())
    expect(screen.queryByText('Agency screen')).not.toBeInTheDocument()
    expect(assign).not.toHaveBeenCalled()
    expect(window.localStorage.getItem('chub:workspace-switched')).toBeNull()
  })

  /** Laravel writes a session back under the id it loaded, so a request in flight can undo a switch. That must not become a reload loop. */
  it('does not reload for ever when a switch that succeeded did not take', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))
    vi.mocked(switchMembership).mockResolvedValue({ current: membership('agency', 'agency'), destination: '/agency' })

    const first = open('/agency/dashboard')
    await waitFor(() => expect(assign).toHaveBeenCalledTimes(1))
    first.unmount()

    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByTestId('portal-entry-failed')).toBeInTheDocument())
    expect(switchMembership).toHaveBeenCalledTimes(1)
    expect(assign).toHaveBeenCalledTimes(1)
  })

  it('still refuses somebody who does not hold the portal, without asking to switch', async () => {
    const memberships = [membership('app', 'brand')]
    vi.mocked(fetchMemberships).mockResolvedValue({ ...inPortal('app'), memberships, current: memberships[0] })

    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByTestId('agency-portal-denied')).toBeInTheDocument())
    expect(switchMembership).not.toHaveBeenCalled()
  })
})
