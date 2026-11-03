import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ClientsTab } from '@/features/settings/tabs/ClientsTab'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/features/projects/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listProjects: vi.fn(), listClientWorkspaces: vi.fn() }
})

vi.mock('@/features/settings/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useClients: vi.fn(), useClientActions: vi.fn() }
})

import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { useClientActions, useClients } from '@/features/settings/api'

/**
 * ENSURE-PORTAL-SESSION-001 — `/client-workspaces` is served from the agency's tenant whatever screen asks. Somebody
 * holding both portals must not get another workspace's clients on an advertiser screen, nor a form that writes to them.
 */
describe('agency-scoped data on an advertiser screen', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view', 'projects.create'])
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listClientWorkspaces).mockResolvedValue([])
    vi.mocked(useClients).mockReturnValue({ data: [], isLoading: false, isError: false, error: null } as never)
    vi.mocked(useClientActions).mockReturnValue({ create: {}, update: {}, archive: {} } as never)
  })
  afterEach(() => signOut())

  it('the projects page does not ask from /app', async () => {
    renderWithProviders(<ProjectsPage />, { locale: 'en', route: '/app/projects' })
    await waitFor(() => expect(listProjects).toHaveBeenCalled())
    expect(listClientWorkspaces).not.toHaveBeenCalled()
  })

  it('the projects page does ask from /agency', async () => {
    renderWithProviders(<ProjectsPage />, { locale: 'en', route: '/agency/projects' })
    await waitFor(() => expect(listClientWorkspaces).toHaveBeenCalled())
  })

  it('the clients settings tab does not ask, or offer a form, from /app', () => {
    renderWithProviders(<ClientsTab />, { locale: 'en', route: '/app/settings/workspace' })
    expect(screen.getByTestId('settings-clients-agency-only')).toBeInTheDocument()
    expect(useClients).not.toHaveBeenCalled()
  })

  it('the clients settings tab works from /agency', () => {
    renderWithProviders(<ClientsTab />, { locale: 'en', route: '/agency/settings/workspace' })
    expect(useClients).toHaveBeenCalled()
    expect(screen.queryByTestId('settings-clients-agency-only')).not.toBeInTheDocument()
  })
})
