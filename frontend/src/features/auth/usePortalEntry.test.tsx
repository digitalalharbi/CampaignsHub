import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RequireAgencyPortal } from '@/features/agency/RequireAgencyPortal'
import { RequireInfluencerPortal } from '@/features/influencers/RequireInfluencerPortal'
import { claimedMembership } from '@/lib/api/workspaceClaim'
import { RequirePortal } from './RequirePortal'
import type { Membership, MembershipState, PortalKey } from './memberships'

vi.mock('./memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn(), switchMembership: vi.fn() }
})

import { fetchMemberships, switchMembership } from './memberships'

/**
 * ENSURE-PORTAL-SESSION-001 (#945) — a route alone never moves the persistent selection, here any more than on the
 * server. Only the person's click does, through the endpoint the server authorises.
 */
function membership(portal: PortalKey, tenant: string): Membership {
  return {
    id: `m-${portal}`, portal, portal_path: `/${portal}`, landing_path: `/${portal}`, role: 'owner',
    is_default: portal === 'app', is_active: false,
    tenant: { id: tenant, name: tenant, slug: tenant }, client_scope_ids: [], project_scope_ids: [],
  }
}

function inPortal(current: PortalKey, memberships = [membership('app', 'Brand Co'), membership('agency', 'Agency Co')]): MembershipState {
  return {
    memberships, current: memberships.find((m) => m.portal === current) ?? null, destination: '/switch',
    needs_switcher: true, requested_portal: null, requested_portal_held: null,
  }
}

describe('usePortalEntry', () => {
  const assign = vi.fn()
  const original = window.location
  const screenAsked = vi.fn()
  let client: QueryClient

  function AgencyScreen() {
    screenAsked()
    return <p>Agency screen</p>
  }

  const open = (route: string) => render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route element={<RequireAgencyPortal />}><Route path="/agency/*" element={<AgencyScreen />} /></Route>
          <Route element={<RequirePortal portal="app" />}><Route path="/app/*" element={<p>Advertiser screen</p>} /></Route>
          <Route element={<RequireInfluencerPortal />}><Route path="/influencers/*" element={<p>Influencer screen</p>} /></Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  beforeEach(() => {
    vi.clearAllMocks()
    window.localStorage.clear()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  it('opening another portal switches nothing and mounts nothing: it asks, naming both', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))

    open('/agency/clients/7')

    await waitFor(() => expect(screen.getByTestId('portal-entry-choice')).toBeInTheDocument())
    expect(screen.getByTestId('portal-entry-current')).toHaveTextContent('Brand Co')
    expect(screen.getByTestId('portal-entry-switch-m-agency')).toHaveTextContent('Agency Co')

    expect(switchMembership).not.toHaveBeenCalled()
    expect(screenAsked).not.toHaveBeenCalled()
    expect(assign).not.toHaveBeenCalled()
    expect(window.localStorage.getItem('chub:workspace-switched')).toBeNull()
  })

  it('holds in the other direction too', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('agency'))

    open('/app/projects')

    await waitFor(() => expect(screen.getByTestId('portal-entry-switch-m-app')).toBeInTheDocument())
    expect(switchMembership).not.toHaveBeenCalled()
    expect(screen.queryByText('Advertiser screen')).not.toBeInTheDocument()
  })

  it('the click is what switches: it keeps the deep link, tells the other tabs, and purges both caches', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))
    vi.mocked(switchMembership).mockResolvedValue({ current: membership('agency', 'Agency Co'), destination: '/agency' })
    client.setQueryData(['projects'], [{ id: 'of-the-workspace-being-left' }])
    window.localStorage.setItem('campaign-hub-project-storage', '{"state":{"currentProjectId":"p-old"}}')
    window.localStorage.setItem('campaign-hub-agency-client', '{"state":{"currentClientId":"c-old"}}')

    open('/agency/clients/7?tab=files#top')
    fireEvent.click(await screen.findByTestId('portal-entry-switch-m-agency'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/agency/clients/7?tab=files#top'))
    expect(switchMembership).toHaveBeenCalledTimes(1)
    expect(switchMembership).toHaveBeenCalledWith('m-agency')
    expect(JSON.parse(window.localStorage.getItem('chub:workspace-switched') ?? '{}').destination).toBe('/agency')
    expect(client.getQueryData(['projects'])).toBeUndefined()
    expect(window.localStorage.getItem('campaign-hub-project-storage')).toBeNull()
    expect(window.localStorage.getItem('campaign-hub-agency-client')).toBeNull()
    expect(screenAsked).not.toHaveBeenCalled()
  })

  it('«stay» goes back and moves nothing', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))

    open('/agency/dashboard')
    fireEvent.click(await screen.findByTestId('portal-entry-stay'))

    await waitFor(() => expect(screen.getByText('Advertiser screen')).toBeInTheDocument())
    expect(switchMembership).not.toHaveBeenCalled()
    expect(screenAsked).not.toHaveBeenCalled()
  })

  it('a refused switch says so, keeps the screens closed, and moves no other tab', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))
    vi.mocked(switchMembership).mockRejectedValue(new Error('refused'))

    open('/agency/dashboard')
    fireEvent.click(await screen.findByTestId('portal-entry-switch-m-agency'))

    await waitFor(() => expect(screen.getByTestId('portal-entry-error')).toBeInTheDocument())
    expect(screenAsked).not.toHaveBeenCalled()
    expect(assign).not.toHaveBeenCalled()
    expect(window.localStorage.getItem('chub:workspace-switched')).toBeNull()
  })

  it('offers each workspace when the portal is held in more than one, and picks none', async () => {
    const second = { ...membership('agency', 'Second Agency'), id: 'm-agency-2' }
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app', [membership('app', 'Brand Co'), membership('agency', 'Agency Co'), second]))

    open('/agency/dashboard')

    expect(await screen.findByTestId('portal-entry-switch-m-agency-2')).toHaveTextContent('Second Agency')
    expect(screen.getByTestId('portal-entry-switch-m-agency')).toBeInTheDocument()
    expect(switchMembership).not.toHaveBeenCalled()
  })

  it('mounts and claims when the session is already here, and moves nothing', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('agency'))

    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByText('Agency screen')).toBeInTheDocument())
    expect(claimedMembership()).toBe('m-agency')
    expect(switchMembership).not.toHaveBeenCalled()
  })

  it('a tab that claims nothing yet does not claim the portal it merely asked about', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app'))

    open('/agency/dashboard')

    await screen.findByTestId('portal-entry-choice')
    expect(claimedMembership()).toBeNull()
  })

  it('leaves when a later answer says the session moved under this tab, without adopting it', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app', [membership('app', 'Brand Co'), { ...membership('app', 'Other Brand'), id: 'm-app-2' }]))
    open('/app/projects')
    await screen.findByText('Advertiser screen')
    expect(claimedMembership()).toBe('m-app')

    const moved = inPortal('app', [membership('app', 'Brand Co'), { ...membership('app', 'Other Brand'), id: 'm-app-2' }])
    moved.current = moved.memberships[1]
    client.setQueryData(['memberships'], moved)

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/switch?reason=workspace_changed'))
    expect(claimedMembership()).toBeNull()
    expect(screen.queryByText('Advertiser screen')).not.toBeInTheDocument()
  })

  it('still refuses somebody who does not hold the portal, without asking', async () => {
    const memberships = [membership('app', 'Brand Co')]
    vi.mocked(fetchMemberships).mockResolvedValue(inPortal('app', memberships))

    open('/agency/dashboard')

    await waitFor(() => expect(screen.getByTestId('agency-portal-denied')).toBeInTheDocument())
    expect(screen.queryByTestId('portal-entry-choice')).not.toBeInTheDocument()
  })
})
