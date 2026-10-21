import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectTeamPage } from './ProjectTeamPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('react-router-dom', async (orig) => ({ ...(await (orig() as Promise<Record<string, unknown>>)), useParams: () => ({ projectId: 'p1' }) }))
vi.mock('./api', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  listProjectTeam: vi.fn(), listUsers: vi.fn(), addProjectMember: vi.fn(), removeProjectMember: vi.fn(), updateProjectMember: vi.fn(),
}))
import { listProjectTeam, listUsers, updateProjectMember } from './api'

/** MEMBER-VISIBILITY-001 — the team page says whether each member sees the money, and changes it through the membership's deny list. */
describe('per-member money visibility', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listProjectTeam).mockResolvedValue([
      { id: 'm1', user_id: 1, name: 'Sara', email: 's@t.test', role: 'media_buyer', status: 'active', denied: ['money.view'] },
      { id: 'm2', user_id: 2, name: 'Omar', email: 'o@t.test', role: 'viewer', status: 'active', denied: [] },
    ])
    vi.mocked(updateProjectMember).mockResolvedValue({ id: 'm1' })
    signInWith(['projects.view', 'users.update', 'users.invite'])
  })
  afterEach(() => signOut())

  it('names the withheld member and restores the money through the deny list', async () => {
    renderWithProviders(<ProjectTeamPage />, { locale: 'ar' })
    const sara = await screen.findByTestId('member-money-m1')
    expect(sara).toHaveTextContent('الأرقام المالية مخفية')
    expect(sara).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('member-money-m2')).toHaveTextContent('الأرقام المالية ظاهرة')

    fireEvent.click(sara)
    await waitFor(() => expect(updateProjectMember).toHaveBeenCalledWith('p1', 'm1', { denied: [] }))

    fireEvent.click(screen.getByTestId('member-money-m2'))
    await waitFor(() => expect(updateProjectMember).toHaveBeenCalledWith('p1', 'm2', { denied: ['money.view'] }))
  })
})
