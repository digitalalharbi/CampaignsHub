import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { InviteAcceptPage } from './InviteAcceptPage'
import * as inviteApi from './inviteApi'
import type { AuthUser } from '@/lib/api/types'
import { useAuth } from '@/stores/auth'
import { renderWithProviders, signOut } from '@/test/utils'

/*
 * AGENCY-PROVISION-001 — an invitation to an address that already has an account is claimed by SIGNING IN
 * as that address, never by choosing a new password; a different signed-in address is told to switch.
 */
vi.mock('./inviteApi', () => ({
  previewInvite: vi.fn(),
  acceptInvite: vi.fn(),
  acceptExistingInvite: vi.fn(),
}))

const preview = (over: Record<string, unknown> = {}) => ({ email: 'Digital@SmartCode.sa', workspace_name: 'Smart Code', role_slug: 'tenant-owner', existing_account: true, ...over })

function signIn(email: string) {
  useAuth.getState().setUser({ id: 'u1', name: 'Owner', email, tenant_id: null, is_platform_admin: false, permissions: [], created_at: null, account: null } as unknown as AuthUser)
}

describe('accepting an agency invitation', () => {
  beforeEach(() => { vi.mocked(inviteApi.previewInvite).mockResolvedValue(preview()) })
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('asks a signed-out visitor to sign in with that address, and offers no password form', async () => {
    renderWithProviders(<InviteAcceptPage />, { route: '/invite/accept?token=abc', locale: 'en' })

    expect(await screen.findByTestId('invite-signin-required')).toHaveTextContent(/Sign in with that same address/)
    expect(screen.queryByTestId('invite-claim')).toBeNull()
    expect(screen.queryByLabelText(/Password/)).toBeNull()
    expect(screen.getByRole('link', { name: /Sign in/ })).toHaveAttribute('href', `/login?redirect=${encodeURIComponent('/invite/accept?token=abc')}`)
  })

  it('does not offer the claim to a different signed-in address', async () => {
    signIn('someone.else@example.com')
    renderWithProviders(<InviteAcceptPage />, { route: '/invite/accept?token=abc', locale: 'en' })

    expect(await screen.findByTestId('invite-signin-required')).toBeInTheDocument()
    expect(screen.queryByTestId('invite-claim')).toBeNull()
  })

  it('lets the invited address claim it, case-insensitively', async () => {
    signIn('digital@smartcode.sa')
    vi.mocked(inviteApi.acceptExistingInvite).mockResolvedValue('m1')
    renderWithProviders(<InviteAcceptPage />, { route: '/invite/accept?token=abc', locale: 'en' })

    fireEvent.click(await screen.findByTestId('invite-claim'))
    await waitFor(() => expect(inviteApi.acceptExistingInvite).toHaveBeenCalledWith('abc'))
  })

  it('still offers the password form for an address with no account', async () => {
    vi.mocked(inviteApi.previewInvite).mockResolvedValue(preview({ existing_account: false }))
    renderWithProviders(<InviteAcceptPage />, { route: '/invite/accept?token=abc', locale: 'en' })

    expect(await screen.findByLabelText(/Password/)).toBeInTheDocument()
    expect(screen.queryByTestId('invite-existing-account')).toBeNull()
  })
})
