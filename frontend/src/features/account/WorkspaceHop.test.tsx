import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AccountMenu } from './UserMenu'
import { RequireAgencyPortal } from '@/features/agency/RequireAgencyPortal'
import { renderWithProviders, signOut } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { Membership, MembershipState } from '@/features/auth/memberships'

vi.mock('@/features/auth/memberships', async (orig) => ({
  ...(await orig<typeof import('@/features/auth/memberships')>()),
  fetchMemberships: vi.fn(),
  switchMembership: vi.fn(),
}))
vi.mock('@/features/auth/workspaceSwitch', async (orig) => ({
  ...(await orig<typeof import('@/features/auth/workspaceSwitch')>()),
  completeWorkspaceSwitch: vi.fn(),
}))

import { fetchMemberships, switchMembership } from '@/features/auth/memberships'
import { completeWorkspaceSwitch } from '@/features/auth/workspaceSwitch'

const agency: Membership = {
  id: 'm-agency', portal: 'agency', portal_path: '/agency', landing_path: '/agency/dashboard', role: 'owner', is_default: true, is_active: false,
  tenant: { id: 't1', name: 'وكالتي', slug: 'mine' }, client_scope_ids: [], project_scope_ids: [],
}
const state = (over: Partial<MembershipState> = {}): MembershipState => ({
  memberships: [agency], current: null, destination: '/admin', needs_switcher: false,
  requested_portal: null, requested_portal_held: null, platform_admin: true, ...over,
})
const signIn = (admin: boolean) => useAuth.getState().setUser({
  id: 'u1', name: 'Owner', email: 'o@test.dev', tenant_id: null, is_platform_admin: admin, permissions: [], created_at: null,
} as never)

/** CONS-ADMIN-SWITCH — admin panel ⇄ own agency workspace, from the account menu, without signing out. */
describe('WorkspaceHop', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('an admin in the admin panel enters their agency workspace through the server switch', async () => {
    signIn(true)
    vi.mocked(fetchMemberships).mockResolvedValue(state())
    vi.mocked(switchMembership).mockResolvedValue({ current: { ...agency, is_active: true }, destination: '/agency/dashboard' })
    renderWithProviders(<AccountMenu variant="topbar" />, { route: '/admin/tenants', locale: 'ar' })

    fireEvent.click(screen.getByRole('button'))
    expect(await screen.findByTestId('workspace-hop-admin')).toBeDisabled()
    fireEvent.click(await screen.findByTestId('workspace-hop-m-agency'))

    await waitFor(() => expect(switchMembership).toHaveBeenCalledWith('m-agency'))
    await waitFor(() => expect(completeWorkspaceSwitch).toHaveBeenCalledWith(expect.anything(), '/agency/dashboard'))
  })

  it('inside the agency workspace it names the current one and offers the admin panel', async () => {
    signIn(true)
    vi.mocked(fetchMemberships).mockResolvedValue(state({ current: { ...agency, is_active: true } }))
    renderWithProviders(<AccountMenu variant="topbar" />, { route: '/agency/reports', locale: 'ar' })

    fireEvent.click(screen.getByRole('button'))
    expect(await screen.findByTestId('workspace-hop-m-agency')).toBeDisabled()
    expect(screen.getByTestId('workspace-hop-admin')).toBeEnabled()
    expect(screen.getByText('لوحة الإدارة')).toBeInTheDocument()
  })

  it('is absent for an account that is not an admin, and for an admin with no workspace', async () => {
    signIn(false)
    vi.mocked(fetchMemberships).mockResolvedValue(state({ platform_admin: false }))
    const { unmount } = renderWithProviders(<AccountMenu variant="topbar" />, { route: '/agency/reports', locale: 'ar' })
    fireEvent.click(screen.getByRole('button'))
    expect(screen.queryByTestId('workspace-hop')).not.toBeInTheDocument()
    expect(fetchMemberships).not.toHaveBeenCalled()
    unmount()

    signIn(true)
    vi.mocked(fetchMemberships).mockResolvedValue(state({ memberships: [] }))
    renderWithProviders(<AccountMenu variant="topbar" />, { route: '/admin', locale: 'ar' })
    fireEvent.click(screen.getByRole('button'))
    await waitFor(() => expect(fetchMemberships).toHaveBeenCalled())
    expect(screen.queryByTestId('workspace-hop')).not.toBeInTheDocument()
  })

  it('an admin refused by the agency gate is shown the way back to the admin panel', async () => {
    signIn(true)
    vi.mocked(fetchMemberships).mockResolvedValue(state({ memberships: [] }))
    renderWithProviders(<RequireAgencyPortal />, { route: '/agency/dashboard', locale: 'ar' })

    expect(await screen.findByTestId('agency-portal-denied')).toBeInTheDocument()
    expect(screen.getByTestId('denied-back-to-admin')).toHaveAttribute('href', '/admin')
  })
})
