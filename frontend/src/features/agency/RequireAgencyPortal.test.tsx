import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { RequireAgencyPortal } from './RequireAgencyPortal'
import type { Membership, MembershipState } from '@/features/auth/memberships'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/auth/memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn() }
})

import { fetchMemberships } from '@/features/auth/memberships'

function membership(portal: Membership['portal']): Membership {
  return {
    id: `m-${portal}`,
    portal,
    portal_path: `/${portal}`,
    landing_path: `/${portal}`,
    role: 'member',
    is_default: true,
    is_active: true,
    tenant: { id: 't1', name: 'Acme Agency', slug: 'acme' },
    client_scope_ids: [],
    project_scope_ids: [],
  }
}

function state(memberships: Membership[]): MembershipState {
  return {
    memberships,
    current: memberships[0] ?? null,
    destination: '/switch',
    needs_switcher: memberships.length > 1,
    // No portal was requested, so there is nothing to have been refused (LOGIN-003).
    requested_portal: null,
    requested_portal_held: null,
  }
}

function renderGate() {
  return renderWithProviders(
    <Routes>
      <Route element={<RequireAgencyPortal />}>
        <Route path="/agency/dashboard" element={<p>Agency inside</p>} />
      </Route>
    </Routes>,
    { route: '/agency/dashboard', locale: 'en' },
  )
}

describe('RequireAgencyPortal', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lets a holder of an agency membership through', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state([membership('agency')]))
    renderGate()
    await waitFor(() => expect(screen.getByText('Agency inside')).toBeInTheDocument())
  })

  /** Holding an advertiser membership in the same tenant is not agency access — the server agrees. */
  it('refuses an advertiser-only account with an explanation, not a wall of failed requests', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state([membership('app')]))
    renderGate()
    await waitFor(() => expect(screen.getByTestId('agency-portal-denied')).toBeInTheDocument())
    expect(screen.queryByText('Agency inside')).not.toBeInTheDocument()
  })

  it('passes a user who holds both an advertiser and an agency membership', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state([membership('app'), membership('agency')]))
    renderGate()
    await waitFor(() => expect(screen.getByText('Agency inside')).toBeInTheDocument())
  })

  /**
   * A failed probe is not proof of absence. Blocking on it would lock out a legitimate operator
   * over a dropped request; the server refuses anything they should not reach anyway.
   */
  it('does not lock anyone out when the membership probe itself fails', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(new Error('offline'))
    renderGate()
    await waitFor(() => expect(screen.getByText('Agency inside')).toBeInTheDocument())
  })
})
