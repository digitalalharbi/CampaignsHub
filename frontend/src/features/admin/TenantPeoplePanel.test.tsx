import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AddTenantMember, PendingInvitations } from './TenantPeoplePanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, addTenantMember: vi.fn(), reissueTenantInvitation: vi.fn() }
})

import { addTenantMember, reissueTenantInvitation } from './api'

const roles = [
  { slug: 'tenant-owner', name: 'Owner' }, { slug: 'media-buyer', name: 'Media buyer' },
  { slug: 'platform-admin', name: 'Sneaky' }, { slug: 'super-admin', name: 'Sneakier' },
]

/** ADMIN-FULL-CONTROL-001 — no owner and no platform role is on offer, and no password field exists. */
describe('AddTenantMember', () => {
  beforeEach(() => vi.clearAllMocks())

  const fill = () => {
    fireEvent.change(screen.getByTestId('add-member-email'), { target: { value: 'sam@x.test' } })
    fireEvent.change(screen.getByTestId('add-member-role'), { target: { value: 'media-buyer' } })
    fireEvent.change(screen.getByTestId('add-member-reason'), { target: { value: 'Asked for by the owner' } })
  }

  it('offers no owner and no platform role, and asks for no password', () => {
    renderWithProviders(<AddTenantMember tenantId="t-1" roles={roles} ar={false} />, { locale: 'en' })

    const options = Array.from(screen.getByTestId('add-member-role').querySelectorAll('option')).map((o) => o.getAttribute('value'))
    expect(options).toEqual(['', 'media-buyer'])
    expect(screen.getByTestId('add-member-form').querySelector('input[type="password"]')).toBeNull()
  })

  it('needs a reason, and says an existing person was added', async () => {
    vi.mocked(addTenantMember).mockResolvedValue({ added: true, member: { roles: ['media-buyer'], status: 'active', member: true }, invitation: null })
    renderWithProviders(<AddTenantMember tenantId="t-1" roles={roles} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('add-member-submit')).toBeDisabled()
    fill()
    fireEvent.click(screen.getByTestId('add-member-submit'))

    await waitFor(() => expect(addTenantMember).toHaveBeenCalledWith('t-1', { email: 'sam@x.test', role: 'media-buyer', reason: 'Asked for by the owner' }))
    expect(await screen.findByTestId('add-member-added')).toBeInTheDocument()
  })

  it('says when an invitation went out instead, and hands over the link once', async () => {
    vi.mocked(addTenantMember).mockResolvedValue({ added: false, member: null, invitation: { delivery_status: 'sent', accept_link: 'https://x.test/invite/accept?token=abc', expires_in_hours: 72 } })
    renderWithProviders(<AddTenantMember tenantId="t-1" roles={roles} ar={false} />, { locale: 'en' })

    fill()
    fireEvent.click(screen.getByTestId('add-member-submit'))

    expect(await screen.findByTestId('add-member-invited')).toHaveTextContent('choose their own password')
  })
})

describe('PendingInvitations', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows nothing when nobody is waiting', () => {
    renderWithProviders(<PendingInvitations tenantId="t-1" invitations={[]} ar={false} />, { locale: 'en' })

    expect(screen.queryByTestId('pending-invitations')).not.toBeInTheDocument()
  })

  it('reissues with a reason', async () => {
    vi.mocked(reissueTenantInvitation).mockResolvedValue({ invitation: { delivery_status: 'sent', accept_link: 'https://x.test/invite/accept?token=new', expires_in_hours: 72 } })
    renderWithProviders(<PendingInvitations tenantId="t-1" ar={false} invitations={[{ id: 'i-1', email: 'new@x.test', role: 'media-buyer', delivery_status: 'failed', expires_at: '2020-01-01T00:00:00Z' }]} />, { locale: 'en' })

    expect(screen.getByTestId('invitation-i-1')).toHaveTextContent('Expired')
    fireEvent.click(screen.getByTestId('invitation-reissue-i-1'))
    expect(screen.getByTestId('invitation-reissue-submit')).toBeDisabled()
    fireEvent.change(screen.getByTestId('invitation-reason'), { target: { value: 'The mail never arrived' } })
    fireEvent.click(screen.getByTestId('invitation-reissue-submit'))

    await waitFor(() => expect(reissueTenantInvitation).toHaveBeenCalledWith('t-1', 'i-1', 'The mail never arrived'))
  })
})
