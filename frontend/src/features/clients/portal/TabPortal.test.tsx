import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { TabPortal } from './TabPortal'
import { renderWithProviders } from '@/test/utils'
import type { ClientDetail } from '../api'

vi.mock('../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api')>()
  return { ...actual, updateSettings: vi.fn() }
})
vi.mock('./portalAdminApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAdminApi')>()
  return { ...actual, listPortalUsers: vi.fn(), invitePortalUser: vi.fn(), revokePortalUser: vi.fn() }
})

import { updateSettings } from '../api'
import { invitePortalUser, listPortalUsers, revokePortalUser } from './portalAdminApi'

/**
 * CP-VISIBILITY-001, CP-WRITE-DENY-001, CP-USERS-001 — the agency edits what a client's portal shows and
 * who can open it; only the powers an agency may hand over are offered, and a client administrator can be
 * chosen only once that power is switched on.
 */
const detail = (over: Partial<ClientDetail> = {}): ClientDetail => ({
  id: 'c1', name: 'Razzah', projects: [{ id: 'p1', name: 'Alpha', status: 'active', created_at: null }, { id: 'p2', name: 'Bravo', status: 'active', created_at: null }],
  can: { update: true, manage_settings: true, manage_team: true, archive: false, view_analytics: true, view_reports: true, manage_files: false },
  portal: {
    mode: 'managed',
    visibility: { dashboard: true, spend: false, revenue: false, invoices: true, ad_account_names: false },
    capabilities: { create_requests: true, approve_content: true, team_admin: false, campaign_writes: false },
  },
  ...over,
} as unknown as ClientDetail)

const mount = (d = detail()) => renderWithProviders(<TabPortal d={d} />, { locale: 'en' })

describe('TabPortal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listPortalUsers).mockResolvedValue([])
    vi.mocked(updateSettings).mockResolvedValue({} as never)
  })

  it('shows the current switches and saves exactly what was changed, offering no write power', async () => {
    mount()
    const spend = await screen.findByTestId('vis-spend')
    expect(spend).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByTestId('vis-dashboard')).toHaveAttribute('aria-checked', 'true')
    expect(screen.queryByTestId('cap-campaign_writes')).toBeNull()
    for (const k of ['budget_writes', 'pause_launch', 'integration_admin', 'margin_profit', 'internal_notes']) expect(screen.queryByTestId(`cap-${k}`)).toBeNull()

    fireEvent.click(spend)
    fireEvent.click(screen.getByTestId('cap-team_admin'))
    fireEvent.click(screen.getByTestId('portal-save'))

    await waitFor(() => expect(updateSettings).toHaveBeenCalled())
    const body = vi.mocked(updateSettings).mock.calls[0][1] as { settings: { portal: { visibility: Record<string, boolean>; capabilities: Record<string, boolean> } } }
    expect(body.settings.portal.visibility.spend).toBe(true)
    expect(body.settings.portal.capabilities).toEqual({ create_requests: true, approve_content: true, team_admin: true })
  })

  it('invites a person with a role and a project ceiling', async () => {
    vi.mocked(invitePortalUser).mockResolvedValue({ state: 'invited', dev_link: null })
    mount()
    const form = await screen.findByTestId('portal-invite')

    fireEvent.change(within(form).getByLabelText('Email'), { target: { value: 'sara@client.test' } })
    fireEvent.click(within(form).getByLabelText('Alpha'))
    fireEvent.click(within(form).getByRole('button', { name: 'Invite' }))

    await waitFor(() => expect(invitePortalUser).toHaveBeenCalledWith('c1', { email: 'sara@client.test', role: 'client_viewer', project_ids: ['p1'] }))
    expect(await within(form).findByRole('status')).toHaveTextContent('Invitation sent.')
  })

  it('offers the client administrator role only after that power is switched on', async () => {
    mount()
    const form = await screen.findByTestId('portal-invite')
    expect(within(form).queryByRole('option', { name: 'Client admin' })).toBeNull()

    fireEvent.click(screen.getByTestId('cap-team_admin'))
    expect(within(form).getByRole('option', { name: 'Client admin' })).toBeInTheDocument()
  })

  it('lists members and pending invitations with their ceilings, and removes one', async () => {
    vi.mocked(listPortalUsers).mockResolvedValue([
      { membership_id: 'm1', user_id: 7, name: 'Sara', email: 'sara@client.test', role: 'client_approver', projects: ['p1'], state: 'active' },
      { invitation_id: 'i1', email: 'later@client.test', role: 'client_viewer', projects: null, state: 'invited' },
    ])
    vi.mocked(revokePortalUser).mockResolvedValue({} as never)
    mount()

    const list = await screen.findByTestId('portal-users')
    await waitFor(() => expect(within(list).getByText('sara@client.test')).toBeInTheDocument())
    expect(within(list).getByText(/Approver · Alpha/)).toBeInTheDocument()
    expect(within(list).getByText(/Viewer · All projects/)).toBeInTheDocument()

    fireEvent.click(within(list).getByRole('button', { name: 'Remove' }))
    await waitFor(() => expect(revokePortalUser).toHaveBeenCalledWith('c1', 'm1'))
  })

  it('is read-only for someone who may not manage settings or the team', async () => {
    mount(detail({ can: { update: false, manage_settings: false, manage_team: false, archive: false, view_analytics: false, view_reports: false, manage_files: false } } as never))

    expect(await screen.findByTestId('vis-spend')).toBeDisabled()
    expect(screen.queryByTestId('portal-save')).toBeNull()
    expect(screen.queryByTestId('portal-invite')).toBeNull()
  })
})
