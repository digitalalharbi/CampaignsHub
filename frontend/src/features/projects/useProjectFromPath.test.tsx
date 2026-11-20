import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { resetClientProjectAutoPick, useClientProjectAutoSelect } from '@/features/agency/clientProject'
import type { Project } from './api'
import { useProjectFromPath } from './useProjectFromUrl'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const project = (id: string, client: string): Project => ({
  id, client_workspace_id: client, name: id, status: 'active', setup_completion: 100, account_manager_id: null, created_at: null,
  summary: { accounts: 1, providers: [], data_last_synced_at: null, team_members: 0 } as unknown as Project['summary'],
})

/** The agency shell's auto-select (parent) and a campaign page whose path names another client's project (child). */
function Shell() {
  useClientProjectAutoSelect()
  return <Page />
}

function Page() {
  useProjectFromPath('theirs')
  return <p>campaign</p>
}

/**
 * CAMPAIGN-DETAIL-SCOPE-LOOP-001 — Production 2026-11-19: /agency/campaigns/<another client's project>/<campaign> fell
 * over with React error #185. The page set the path's project while the sidebar's client kept re-picking its own
 * single project, for ever.
 */
describe('a campaign page for a project of another client', () => {
  beforeEach(() => {
    resetClientProjectAutoPick()
    signInWith(['campaigns.view'])
    useAgencyClient.setState({ currentClientId: 'c1' })
    useProject.getState().setCurrentProjectId('mine')
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (url.startsWith('/client-workspaces')) return [{ id: 'c1', name: 'One' }, { id: 'c2', name: 'Two' }] as never
      if (url.startsWith('/projects')) return [project('mine', 'c1'), project('theirs', 'c2')] as never
      return {} as never
    })
  })

  afterEach(() => {
    signOut()
    vi.clearAllMocks()
    useAgencyClient.setState({ currentClientId: null })
    useProject.getState().setCurrentProjectId(null)
  })

  it('selects the path\'s project with the client that owns it, and settles', async () => {
    const view = renderWithProviders(<Shell />, { route: '/agency/campaigns/theirs/x' })

    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('theirs'))
    expect(useAgencyClient.getState().currentClientId).toBe('c2')
    expect(view.getByText('campaign')).toBeTruthy()
  })
})
