import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { UserAccessDialog } from './UserAccessDialog'
import type { TenantPerson } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, actOnUserAccess: vi.fn() }
})

import { actOnUserAccess } from './api'

const person: TenantPerson = {
  user_id: '7', user_uuid: 'u-7', mfa_enabled: true, name: 'Sara', email: 'sara@agency.test', portal: 'agency', role: 'owner', status: 'active',
}

/** SUPERADMIN-ACCESS-001 — a reason every time; the operator's password for the actions that hand back a way in. */
describe('UserAccessDialog', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('ends every session with only a reason', async () => {
    vi.mocked(actOnUserAccess).mockResolvedValue({ action: 'revoke_sessions', sessions_revoked: true })
    renderWithProviders(<UserAccessDialog person={person} ar={false} onClose={() => {}} />, { locale: 'en' })

    expect(screen.queryByTestId('access-password')).not.toBeInTheDocument()
    fireEvent.change(screen.getByTestId('access-reason'), { target: { value: 'Laptop stolen' } })
    fireEvent.click(screen.getByTestId('access-submit'))

    await waitFor(() => expect(actOnUserAccess).toHaveBeenCalledWith('u-7', { action: 'revoke_sessions', reason: 'Laptop stolen', current_password: undefined }))
    expect(await screen.findByTestId('access-result')).toHaveTextContent(/Done/)
  })

  it('asks for the operator password for a set-password link and shows the link once', async () => {
    vi.mocked(actOnUserAccess).mockResolvedValue({ action: 'password_link', link: 'https://campaignshub.io/reset-password?token=t&email=s', delivery: 'failed' })
    renderWithProviders(<UserAccessDialog person={person} ar={false} onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('access-action-password_link'))
    fireEvent.change(screen.getByTestId('access-reason'), { target: { value: 'Lost access to email' } })
    fireEvent.change(screen.getByTestId('access-password'), { target: { value: 'operator-pass' } })
    fireEvent.click(screen.getByTestId('access-submit'))

    await waitFor(() => expect(actOnUserAccess).toHaveBeenCalledWith('u-7', { action: 'password_link', reason: 'Lost access to email', current_password: 'operator-pass' }))
    expect(await screen.findByTestId('access-link')).toHaveTextContent('/reset-password?token=t')
    expect(screen.getByTestId('access-result')).toHaveTextContent(/verifying who they are/i)
  })

  it('does not offer a two-factor reset to someone without two-factor', () => {
    renderWithProviders(<UserAccessDialog person={{ ...person, mfa_enabled: false }} ar={false} onClose={() => {}} />, { locale: 'en' })

    expect(screen.getByTestId('access-action-reset_mfa')).toBeDisabled()
  })
})
