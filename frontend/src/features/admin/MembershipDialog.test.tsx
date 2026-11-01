import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { MembershipDialog } from './MembershipDialog'
import type { TenantPerson } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, actOnMembership: vi.fn() }
})

import { actOnMembership } from './api'

const person = (over: Partial<TenantPerson> = {}): TenantPerson => ({
  user_id: '7', user_uuid: 'u-7', mfa_enabled: false, name: 'Sam', email: 'sam@x.test', portal: 'agency', role: 'member',
  roles: ['media-buyer'], status: 'active', ...over,
})
const roles = [{ slug: 'tenant-owner', name: 'Owner' }, { slug: 'media-buyer', name: 'Media buyer' }]

/** SUPERADMIN-MEMBERS-001 — role and membership in one workspace, always with a reason; the server's refusal is shown as is. */
describe('MembershipDialog', () => {
  beforeEach(() => vi.clearAllMocks())

  it('changes the role with the chosen role and the reason', async () => {
    vi.mocked(actOnMembership).mockResolvedValue({ member: { roles: ['tenant-owner'], status: 'active', member: true } })
    renderWithProviders(<MembershipDialog tenantId="t-1" person={person()} roles={roles} ar={false} onClose={() => {}} />, { locale: 'en' })

    expect(screen.getByTestId('membership-submit')).toBeDisabled()
    fireEvent.change(screen.getByTestId('membership-role'), { target: { value: 'tenant-owner' } })
    fireEvent.change(screen.getByTestId('membership-reason'), { target: { value: 'Agency asked for it' } })
    fireEvent.click(screen.getByTestId('membership-submit'))

    await waitFor(() => expect(actOnMembership).toHaveBeenCalledWith('t-1', 'u-7', { action: 'change_role', role: 'tenant-owner', reason: 'Agency asked for it' }))
    expect(await screen.findByTestId('membership-result')).toHaveTextContent('tenant-owner')
  })

  it('offers reactivate for a suspended membership, and shows the last-owner refusal', async () => {
    vi.mocked(actOnMembership).mockRejectedValue({ response: { status: 422, data: { message: 'A workspace must keep at least one active owner.', errors: { action: ['A workspace must keep at least one active owner.'] } } } })
    renderWithProviders(<MembershipDialog tenantId="t-1" person={person({ status: 'suspended' })} roles={roles} ar={false} onClose={() => {}} />, { locale: 'en' })

    expect(screen.getByTestId('membership-action-reactivate')).toBeInTheDocument()
    expect(screen.queryByTestId('membership-action-suspend')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('membership-action-remove'))
    fireEvent.change(screen.getByTestId('membership-reason'), { target: { value: 'Left the agency' } })
    fireEvent.click(screen.getByTestId('membership-submit'))

    await waitFor(() => expect(actOnMembership).toHaveBeenCalledWith('t-1', 'u-7', { action: 'remove', role: undefined, reason: 'Left the agency' }))
    expect(await screen.findByTestId('membership-error')).toHaveTextContent(/active owner/i)
  })
})
