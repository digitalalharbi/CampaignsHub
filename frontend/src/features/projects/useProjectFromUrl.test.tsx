import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CampaignsPage } from '@/features/campaigns/CampaignsPage'
import type { Project } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { campaignPage } from '@/test/campaignPage'
import { useLocation } from 'react-router-dom'

/** The memory router's address, read from inside it — `window.location` never moves under it. */
function Address() {
  const location = useLocation()
  return <span data-testid="address">{location.search}</span>
}

vi.mock('@/features/campaigns/api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('./api', () => ({ listProjects: vi.fn(), listClientWorkspaces: vi.fn(), listUsers: vi.fn().mockResolvedValue([]) }))
import { listCampaigns } from '@/features/campaigns/api'
import { listClientWorkspaces, listProjects } from './api'

const project = (id: string, client: string): Project => ({
  id, client_workspace_id: client, name: `Project ${id}`, status: 'active', setup_completion: 0, account_manager_id: null, created_at: null,
})

/**
 * DASHBOARD-DRILLDOWN-001 — a project named in the address is chosen on arrival, within reach only.
 */
describe('a campaigns link that names its project', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'w1'), project('p2', 'w2')])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w2', name: 'Beta' }] as never)
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([]))
    useProject.getState().setCurrentProjectId('p1')
    useAgencyClient.getState().setCurrentClientId(null)
  })
  afterEach(() => signOut())

  it('chooses that project (and its client, when held), then cleans the key out of the address', async () => {
    renderWithProviders(<><CampaignsPage /><Address /></>, { route: '/agency/campaigns?project=p2&view=table&band=paused', locale: 'en' })
    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('p2'))
    expect(useAgencyClient.getState().currentClientId).toBe('w2')
    await waitFor(() => expect(listCampaigns).toHaveBeenCalledWith('p2', expect.anything()))
    await waitFor(() => expect(screen.getByTestId('address')).toHaveTextContent('?view=table&band=paused'))
  })

  it('leaves the choice alone for a project outside the reader’s reach', async () => {
    renderWithProviders(<><CampaignsPage /><Address /></>, { route: '/agency/campaigns?project=p-stranger', locale: 'en' })
    await waitFor(() => expect(screen.getByTestId('address')).toHaveTextContent(''))
    await waitFor(() => expect(screen.getByTestId('address').textContent).toBe(''))
    expect(useProject.getState().currentProjectId).toBe('p1')
    expect(await screen.findByTestId('view-table')).toBeInTheDocument()
  })
})
