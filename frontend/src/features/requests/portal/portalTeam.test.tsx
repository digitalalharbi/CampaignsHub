import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PortalTeamSection } from './PortalTeamSection'
import { renderWithProviders } from '@/test/utils'

vi.mock('./portalAccountApi', () => ({ getPortalTeam: vi.fn(), invitePortalPerson: vi.fn(), removePortalPerson: vi.fn() }))
import { getPortalTeam, invitePortalPerson } from './portalAccountApi'

/** CP-USERS-001 — the section exists only for a client admin whose agency allowed it. */
describe('the portal people section', () => {
  beforeEach(() => vi.clearAllMocks())

  it('draws nothing when the server refuses', async () => {
    vi.mocked(getPortalTeam).mockRejectedValue(Object.assign(new Error('Forbidden'), { status: 403 }))
    renderWithProviders(<PortalTeamSection ar={false} />)

    await waitFor(() => expect(getPortalTeam).toHaveBeenCalled())
    expect(screen.queryByTestId('portal-team')).toBeNull()
  })

  it('lists the people and invites a viewer into the admin space', async () => {
    vi.mocked(getPortalTeam).mockResolvedValue({ workspaces: [{ id: 'w1', name: 'Razzah', people: [
      { membership_id: 'm1', email: 'boss@client.test', role: 'client_admin', state: 'active' },
      { membership_id: 'm2', email: 'v@client.test', role: 'client_viewer', state: 'active' },
    ] }] })
    vi.mocked(invitePortalPerson).mockResolvedValue({ state: 'invited' })
    renderWithProviders(<PortalTeamSection ar={false} />)

    expect(await screen.findAllByTestId('portal-team-person')).toHaveLength(2)
    // The admin cannot be removed from here; the viewer can.
    expect(screen.getAllByTestId('portal-team-remove')).toHaveLength(1)

    fireEvent.change(screen.getByTestId('portal-team-email'), { target: { value: 'new@client.test' } })
    fireEvent.click(screen.getByTestId('portal-team-invite'))
    await waitFor(() => expect(invitePortalPerson).toHaveBeenCalledWith({ client_workspace_id: 'w1', email: 'new@client.test', role: 'client_viewer' }))
  })
})
