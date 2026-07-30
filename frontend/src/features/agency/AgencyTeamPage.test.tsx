import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AgencyTeamPage } from './AgencyTeamPage'
import type { AgencyTeam, AgencyTeamMember } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchAgencyTeam: vi.fn(),
    grantClientScopes: vi.fn(),
    withdrawClientScope: vi.fn(),
  }
})

import { fetchAgencyTeam, grantClientScopes, withdrawClientScope } from './api'

function member(over: Partial<AgencyTeamMember> = {}): AgencyTeamMember {
  return {
    id: 'mem-1',
    role: 'member',
    status: 'active',
    user: { id: 'u1', name: 'Sam Rahal', email: 'sam@agency.dev' },
    client_scope_ids: ['c1'],
    clients: [{ id: 'c1', name: 'Alpha' }],
    is_client_scoped: true,
    has_unrestricted_permission: false,
    is_self: false,
    ...over,
  }
}

function team(over: Partial<AgencyTeam> = {}): AgencyTeam {
  return {
    members: [member()],
    can_manage: true,
    assignable_clients: [{ id: 'c1', name: 'Alpha' }, { id: 'c2', name: 'Beta' }],
    ...over,
  }
}

describe('AgencyTeamPage', () => {
  beforeEach(() => vi.clearAllMocks())

  /**
   * The distinction the whole model turns on. If the page let "no clients" read as "everything",
   * an administrator would grant far more than they meant to, and never know.
   */
  it('never presents an absence of scopes as unrestricted access', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(
      team({ members: [member({ client_scope_ids: [], clients: [], is_client_scoped: false })] }),
    )
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('scope-none')).toBeInTheDocument())
    expect(screen.getByTestId('scope-none')).toHaveTextContent('Reaches no clients')
    expect(screen.queryByTestId('scope-unrestricted')).not.toBeInTheDocument()
    expect(screen.getByText(/Unrestricted access is an explicit permission/)).toBeInTheDocument()
  })

  it('marks unrestricted access as the explicit permission it is', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(
      team({ members: [member({ client_scope_ids: [], clients: [], is_client_scoped: false, has_unrestricted_permission: true })] }),
    )
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('scope-unrestricted')).toBeInTheDocument())
    // An unrestricted member has nothing to add — the control would imply it did something.
    expect(screen.queryByText('Add a client')).not.toBeInTheDocument()
  })

  /** Granting sends ONLY the new client, so the ones already held survive the call. */
  it('grants one client without resending the ones already held', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(team())
    vi.mocked(grantClientScopes).mockResolvedValue({ member: member() })
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Add a client')).toBeInTheDocument())
    fireEvent.click(screen.getByText('Add a client'))

    fireEvent.click(screen.getByRole('combobox'))
    await waitFor(() => expect(screen.getByRole('option', { name: /Beta/ })).toBeInTheDocument())
    fireEvent.mouseDown(screen.getByRole('option', { name: /Beta/ }))
    fireEvent.click(screen.getByRole('button', { name: /Grant access/ }))

    await waitFor(() => expect(grantClientScopes).toHaveBeenCalledWith('mem-1', ['c2']))
  })

  /** The picker never offers a client they already hold — it would imply the grant did something. */
  it('does not offer a client the member already holds', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(team())
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Add a client')).toBeInTheDocument())
    fireEvent.click(screen.getByText('Add a client'))
    fireEvent.click(screen.getByRole('combobox'))

    await waitFor(() => expect(screen.getByRole('option', { name: /Beta/ })).toBeInTheDocument())
    // 'Alpha' is still on screen as the member's existing chip, but never as a selectable option.
    expect(screen.queryByRole('option', { name: /Alpha/ })).not.toBeInTheDocument()
  })

  it('withdraws exactly one client', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(
      team({ members: [member({ client_scope_ids: ['c1', 'c2'], clients: [{ id: 'c1', name: 'Alpha' }, { id: 'c2', name: 'Beta' }] })] }),
    )
    vi.mocked(withdrawClientScope).mockResolvedValue({ member: member() })
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByLabelText('Remove Alpha')).toBeInTheDocument())
    fireEvent.click(screen.getByLabelText('Remove Alpha'))

    await waitFor(() => expect(withdrawClientScope).toHaveBeenCalledWith('mem-1', 'c1'))
  })

  /** A client outside the reader's own access is counted, not named, and not removable by them. */
  it('counts a client it cannot name, and does not offer to remove it', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(
      team({ members: [member({ client_scope_ids: ['c9'], clients: [{ id: 'c9', name: null }] })] }),
    )
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByText('A client outside your access')).toBeInTheDocument())
    expect(screen.queryByLabelText(/^Remove /)).not.toBeInTheDocument()
  })

  /** Widening your own ceiling is self-promotion; the server refuses it, so the UI must not offer it. */
  it('offers no scope controls on the operator’s own row', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(team({ members: [member({ is_self: true })] }))
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('scope-self')).toBeInTheDocument())
    expect(screen.queryByText('Add a client')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Remove Alpha')).not.toBeInTheDocument()
  })

  it('offers no controls at all to a view-only operator', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(team({ can_manage: false }))
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'en' })

    await waitFor(() => expect(screen.getByText('You have view-only access here.')).toBeInTheDocument())
    expect(screen.queryByText('Add a client')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Remove Alpha')).not.toBeInTheDocument()
  })
})
