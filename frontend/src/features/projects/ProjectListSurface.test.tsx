import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ProjectsPage } from './ProjectsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/**
 * PROJECT-LIST-SURFACE-001 §10 — the card answers the questions a list of clients is asked.
 *
 * Before this it said name, client, status and «الإعداد 40%». Everything an operator actually wants
 * from a list — how many ad accounts, which platforms, when the data last arrived, whether anything
 * needs them — meant opening each project in turn. A list that cannot answer those is a menu, and
 * the rail was already the menu.
 *
 * The attention badge is three named states and never a score. «Something about this client is worse
 * than it was» is a badge people learn not to see; `no_accounts`, `never_synced` and `stale` each
 * name a thing to go and do, and the test keeps them told apart on the screen as well as in the API.
 */

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listProjects: vi.fn(),
    listClientWorkspaces: vi.fn(),
    createProject: vi.fn(),
    updateProject: vi.fn(),
    projectAction: vi.fn(),
    archiveProject: vi.fn(),
    fetchProjectDeletionImpact: vi.fn(),
    deleteProject: vi.fn(),
  }
})

import { listClientWorkspaces, listProjects } from './api'

const base = { client_workspace_id: 'w1', status: 'active', setup_completion: 40, account_manager_id: null, created_at: null }

describe('the projects list as a management surface', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme WS' } as never])
  })
  afterEach(() => signOut())

  it('states the accounts, the platforms and the team on the card', async () => {
    vi.mocked(listProjects).mockResolvedValue([
      {
        ...base, id: 'p1', name: 'رزة أفينيو',
        summary: {
          accounts: 3,
          providers: ['meta', 'snapchat'],
          data_last_synced_at: new Date(Date.now() - 3 * 3600_000).toISOString(),
          team_members: 4,
          attention: null,
        },
      },
    ] as never)

    renderWithProviders(<ProjectsPage />, { locale: 'ar' })

    const card = await screen.findByTestId('project-card-p1')
    // Latin digits, the product's rule in every locale.
    expect(within(card).getByTestId('project-accounts')).toHaveTextContent('3')
    expect(within(card).getByTestId('project-team')).toHaveTextContent('4')
    expect(card.textContent).toContain('Meta')
    expect(card.textContent).toContain('Snapchat')
  })

  /** Each attention state reads as itself, and a healthy project wears no badge at all. */
  it.each([
    ['no_accounts', 'لا حسابات'],
    ['never_synced', 'لم تصل بيانات'],
    ['stale', 'البيانات متأخرة'],
  ])('shows the %s state in words an operator can act on', async (attention, words) => {
    vi.mocked(listProjects).mockResolvedValue([
      {
        ...base, id: 'p1', name: 'رزة أفينيو',
        summary: { accounts: attention === 'no_accounts' ? 0 : 1, providers: [], data_last_synced_at: null, team_members: 0, attention },
      },
    ] as never)

    renderWithProviders(<ProjectsPage />, { locale: 'ar' })

    const badge = await screen.findByTestId('project-attention-p1')
    expect(badge).toHaveTextContent(words)
  })

  it('wears no attention badge when there is nothing to do', async () => {
    vi.mocked(listProjects).mockResolvedValue([
      {
        ...base, id: 'p1', name: 'رزة أفينيو',
        summary: { accounts: 2, providers: ['snapchat'], data_last_synced_at: new Date().toISOString(), team_members: 1, attention: null },
      },
    ] as never)

    renderWithProviders(<ProjectsPage />, { locale: 'ar' })

    await screen.findByTestId('project-card-p1')
    expect(screen.queryByTestId('project-attention-p1')).not.toBeInTheDocument()
  })

  /**
   * A project the server sent no summary for still draws.
   *
   * The listing always carries one, but a card that throws on a missing optional is a card that
   * takes the whole page down the first time an older cached response is replayed.
   */
  it('draws a card that arrived without a summary', async () => {
    vi.mocked(listProjects).mockResolvedValue([{ ...base, id: 'p1', name: 'رزة أفينيو' }] as never)

    renderWithProviders(<ProjectsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('project-card-p1')).toBeInTheDocument()
    expect(screen.queryByTestId('project-accounts')).not.toBeInTheDocument()
  })
})
