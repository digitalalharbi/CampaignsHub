import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectTeamPage } from './ProjectTeamPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('react-router-dom', async (orig) => ({ ...(await (orig() as Promise<Record<string, unknown>>)), useParams: () => ({ projectId: 'p1' }) }))
vi.mock('./api', async (orig) => ({
  ...(await (orig() as Promise<Record<string, unknown>>)),
  listProjectTeam: vi.fn(), listUsers: vi.fn(), addProjectMember: vi.fn(), removeProjectMember: vi.fn(), updateProjectMember: vi.fn(), fetchMemberEffective: vi.fn(),
}))
import { fetchMemberEffective, listProjectTeam, listUsers, updateProjectMember } from './api'

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

  /** The lead-data toggle is appended to the deny list, never replacing the money entry. */
  it('withholds lead personal data alongside the money through the same deny list', async () => {
    renderWithProviders(<ProjectTeamPage />, { locale: 'ar' })
    const sara = await screen.findByTestId('member-pii-m1')
    expect(sara).toHaveTextContent('بيانات العملاء ظاهرة')
    fireEvent.click(sara)
    await waitFor(() => expect(updateProjectMember).toHaveBeenCalledWith('p1', 'm1', { denied: ['money.view', 'leads.pii.view'] }))
  })

  /** The preview prints the server's resolved answer, labelled, with the withheld list apart. */
  it('shows the effective-access preview the server resolves', async () => {
    vi.mocked(fetchMemberEffective).mockResolvedValue({
      id: 'm1', role: 'media_buyer', denied: ['money.view'],
      effective: ['campaigns.view', 'campaigns.manage', 'analytics.view'], withheld: ['money.view', 'team.manage'],
    })
    renderWithProviders(<ProjectTeamPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('member-effective-m1'))
    const panel = await screen.findByTestId('member-effective-panel-m1')
    await waitFor(() => expect(panel).toHaveTextContent('إدارة الحملات'))
    expect(panel).toHaveTextContent('محجوب عنه')
    expect(panel).toHaveTextContent('الأرقام المالية')
    expect(panel).toHaveTextContent('إدارة الفريق')
    expect(fetchMemberEffective).toHaveBeenCalledWith('p1', 'm1')
  })
})
