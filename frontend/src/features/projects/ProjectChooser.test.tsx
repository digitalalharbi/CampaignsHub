import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectChooser } from './ProjectChooser'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'

vi.mock('./api', async (orig) => ({
  ...(await orig<typeof import('./api')>()),
  listClientWorkspaces: vi.fn(),
  listProjects: vi.fn(),
}))

import { listClientWorkspaces, listProjects } from './api'

const client = (id: string, name: string) => ({ id, name, mode: 'managed', status: 'active' })
const project = (id: string, client_workspace_id: string, name: string, synced: string | null, campaigns = 3) => ({
  id, client_workspace_id, name, status: 'active', setup_completion: 100, account_manager_id: null, created_at: null,
  summary: { accounts: 1, providers: ['meta', 'snapchat'], data_last_synced_at: synced, team_members: 2, campaigns, attention: null },
})

/** PROJECT-FIRST-VISIT-001 — the choice is on the page; one reachable project is chosen for the reader. */
describe('ProjectChooser', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId(null)
    useAgencyClient.getState().setCurrentClientId(null)
  })
  afterEach(() => signOut())

  it('lists every reachable project under its client, most recently synced first, and a click selects client and project', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([client('c1', 'Acme'), client('c2', 'Nova')] as never)
    vi.mocked(listProjects).mockResolvedValue([
      project('p-old', 'c1', 'Ramadan', '2026-09-01T00:00:00Z'),
      project('p-new', 'c2', 'Launch', '2026-10-09T00:00:00Z', 7),
      project('p-none', 'c1', 'Quiet', null, 0),
    ] as never)
    renderWithProviders(<ProjectChooser purpose="campaigns" />, { locale: 'ar', route: '/agency/campaigns' })

    const chooser = await screen.findByTestId('project-chooser')
    const order = [...chooser.querySelectorAll('[data-testid^="project-choice-"]')].map((b) => b.getAttribute('data-testid'))
    expect(order).toEqual(['project-choice-p-new', 'project-choice-p-old', 'project-choice-p-none'])
    expect(screen.getByTestId('project-choice-p-new').textContent).toContain('Nova')
    expect(screen.getByTestId('project-choice-p-new').textContent).toContain('7 حملة')
    expect(screen.getByTestId('project-choice-p-none').textContent).toContain('لم تصل بيانات بعد')
    expect(useProject.getState().currentProjectId).toBeNull()

    fireEvent.click(screen.getByTestId('project-choice-p-new'))
    expect(useProject.getState().currentProjectId).toBe('p-new')
    expect(useAgencyClient.getState().currentClientId).toBe('c2')
  })

  it('chooses the only reachable project for the reader without asking', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([client('c1', 'Acme')] as never)
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'c1', 'Solo', null)] as never)
    renderWithProviders(<ProjectChooser purpose="analytics" />, { locale: 'en', route: '/agency/analytics' })

    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('p1'))
    expect(useAgencyClient.getState().currentClientId).toBe('c1')
  })

  /*
   * The advertiser portal has no clients to name and no right to ask: `/client-workspaces` is
   * agency-scoped and answered 403, which the console-cleanliness gate counts as an error.
   */
  it('never asks for client workspaces outside the agency portal, and still lists the projects', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'c1', 'Growth', null), project('p2', 'c1', 'Retention', null)] as never)
    renderWithProviders(<ProjectChooser purpose="campaigns" />, { locale: 'en', route: '/app/campaigns' })
    expect(await screen.findByTestId('project-choice-p1')).toBeInTheDocument()
    expect(screen.getByTestId('project-choice-p2')).toBeInTheDocument()
    expect(listClientWorkspaces).not.toHaveBeenCalled()
  })

  /*
   * A viewer scoped to projects alone reaches no client workspace. Choosing their one project must
   * set the project and leave the client untouched — a client id the switcher cannot find makes it
   * clear both selections, and the chooser and the switcher chase each other.
   */
  it('sets only the project for a reader who holds no client workspace', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'c1', 'Only', null), project('p2', 'c1', 'Other', null)] as never)
    renderWithProviders(<ProjectChooser purpose="campaigns" />, { locale: 'en', route: '/agency/campaigns' })
    fireEvent.click(await screen.findByTestId('project-choice-p1'))
    expect(useProject.getState().currentProjectId).toBe('p1')
    expect(useAgencyClient.getState().currentClientId).toBeNull()
  })

  it('says honestly when the reader can reach no project at all', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
    renderWithProviders(<ProjectChooser purpose="reports" />, { locale: 'ar', route: '/agency/reports' })

    expect(await screen.findByTestId('project-chooser-empty')).toBeTruthy()
    expect(useProject.getState().currentProjectId).toBeNull()
  })
})
