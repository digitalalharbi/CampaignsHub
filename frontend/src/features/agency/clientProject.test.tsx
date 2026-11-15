import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { ReportsPage } from '@/features/reports/ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import type { Project } from '@/features/projects/api'
import { pickClientProject, resetClientProjectAutoPick } from './clientProject'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const project = (id: string, client: string, synced: string | null = null): Project => ({
  id, client_workspace_id: client, name: id, status: 'active', setup_completion: 100, account_manager_id: null, created_at: null,
  summary: { accounts: 1, providers: [], data_last_synced_at: synced, team_members: 0 } as unknown as Project['summary'],
})

const PROJECTS = [
  project('solo', 'c1'),
  project('older', 'c2', '2026-11-10T08:00:00Z'),
  project('busy', 'c2', '2026-11-15T08:00:00Z'),
  project('elsewhere', 'c3', '2026-11-16T08:00:00Z'),
]

/** CONS-CLIENT-PROJECT — a client chosen in the sidebar brings its project with it, on reports and analytics. */
describe('pickClientProject', () => {
  it('picks the only project, the most recently active of several, and never another client\'s', () => {
    expect(pickClientProject(PROJECTS, 'c1')).toBe('solo')
    expect(pickClientProject(PROJECTS, 'c2')).toBe('busy')
    expect(pickClientProject(PROJECTS, 'nobody')).toBeNull()
    expect(pickClientProject(PROJECTS, null)).toBeNull()
    // No sync anywhere: the server's order (newest project first) decides.
    expect(pickClientProject([project('new', 'c9'), project('old', 'c9')], 'c9')).toBe('new')
  })
})

describe('client selected → project selected', () => {
  beforeEach(() => {
    resetClientProjectAutoPick()
    signInWith(['campaigns.view', 'reports.view', 'reports.create'])
    useProject.getState().setCurrentProjectId(null)
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (url.startsWith('/client-workspaces')) return [{ id: 'c1', name: 'Solo' }, { id: 'c2', name: 'Pair' }] as never
      if (url.startsWith('/projects') && !url.includes('/reports')) return PROJECTS.filter((p) => p.client_workspace_id !== 'c3') as never
      if (url.includes('/reports')) return { reports: [], summary: { total: 0, completed: 0, processing: 0, failed: 0 } } as never
      if (url.includes('saved-views')) return [] as never
      return {} as never
    })
  })

  afterEach(() => {
    signOut()
    vi.clearAllMocks()
    useAgencyClient.setState({ currentClientId: null })
  })

  it('on /agency/reports a one-project client lands in its project instead of «لم يُحدَّد مشروع»', async () => {
    useAgencyClient.setState({ currentClientId: 'c1' })
    renderWithProviders(<ReportsPage />, { locale: 'ar', route: '/agency/reports' })

    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('solo'))
    expect(await screen.findByText('تقارير المشروع الحالي')).toBeInTheDocument()
    expect(screen.queryByText('لم يُحدَّد مشروع')).not.toBeInTheDocument()
  })

  it('on /agency/analytics a several-project client lands in its most recently active project', async () => {
    useAgencyClient.setState({ currentClientId: 'c2' })
    renderWithProviders(<AnalyticsPage />, { locale: 'ar', route: '/agency/analytics' })

    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('busy'))
    await waitFor(() => expect(screen.queryByText('لا مشروع')).not.toBeInTheDocument())
  })

  it('does nothing in the advertiser portal, which has no client step', async () => {
    useAgencyClient.setState({ currentClientId: 'c1' })
    renderWithProviders(<ReportsPage />, { locale: 'ar', route: '/app/reports' })

    await screen.findByText('لم يُحدَّد مشروع')
    expect(useProject.getState().currentProjectId).toBeNull()
    expect(vi.mocked(getData).mock.calls.some(([u]) => String(u).startsWith('/client-workspaces'))).toBe(false)
  })
})
