import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ProjectsPage } from './ProjectsPage'

/**
 * PROJECT-LIFECYCLE-001 — «أرشفة» and «استعادة» are their own permissions (projects.archive / projects.restore,
 * workspace owner by default). A member without them is not offered the button; the server refuses them anyway.
 */
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listProjects: vi.fn(), listClientWorkspaces: vi.fn(), projectAction: vi.fn(), archiveProject: vi.fn() }
})

import { listClientWorkspaces, listProjects } from './api'

const ACTIVE = { id: 'p1', name: 'Spring', status: 'active', client_workspace_id: 'w1', setup_completion: 40 }

describe('archive permission', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([ACTIVE as never])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme' } as never])
  })
  afterEach(() => signOut())

  it('hides «أرشفة» from a member without projects.archive', async () => {
    signInWith(['projects.view', 'projects.update'])
    renderWithProviders(<ProjectsPage />, { locale: 'ar' })
    expect(await screen.findByText('Spring')).toBeInTheDocument()
    expect(screen.queryByText('أرشفة')).not.toBeInTheDocument()
  })

  it('offers «أرشفة» to a holder of projects.archive', async () => {
    signInWith(['projects.view', 'projects.update', 'projects.archive'])
    renderWithProviders(<ProjectsPage />, { locale: 'ar' })
    expect(await screen.findByText('Spring')).toBeInTheDocument()
    expect(screen.getByText('أرشفة')).toBeInTheDocument()
  })
})
