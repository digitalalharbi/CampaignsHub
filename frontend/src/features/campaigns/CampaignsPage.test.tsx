import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'
import type { Project } from '@/features/projects/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listCampaigns: vi.fn(),
  createCampaign: vi.fn(),
  updateCampaign: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({
  listProjects: vi.fn(),
  listUsers: vi.fn(),
}))

import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'

function project(id: string, name: string): Project {
  return { id, client_workspace_id: 'w1', name, status: 'active', setup_completion: 0, account_manager_id: null, created_at: null }
}
function campaign(id: string, name: string, status = 'draft'): UnifiedCampaign {
  return {
    id, project_id: 'p1', name, objective: 'sales', status, total_budget: 1000, budget_currency: 'SAR',
    starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null,
    attribution_window: null, owner_id: null, target_kpi: null, audience: null, regions: null,
    external_campaigns_count: 2, created_at: null,
  }
}

import { useProject } from '@/stores/project'

describe('CampaignsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'Project A'), project('p2', 'Project B')])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue([campaign('c1', 'National Day')])
    useProject.getState().setCurrentProjectId('p1')
  })

  it('lists campaigns for the auto-selected project', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)

    expect(await screen.findByText('National Day')).toBeInTheDocument()
    await waitFor(() => expect(listCampaigns).toHaveBeenCalledWith('p1', expect.anything()))
  })

  it('shows the create button only with campaigns.create', async () => {
    signInWith(['campaigns.view'])
    const view = renderWithProviders(<CampaignsPage />)
    await screen.findByText('National Day')
    expect(screen.queryByText('New campaign')).not.toBeInTheDocument()

    view.unmount()
    signInWith(['campaigns.view', 'campaigns.create'])
    renderWithProviders(<CampaignsPage />)
    await screen.findByText('National Day')
    expect(screen.getByText('New campaign')).toBeInTheDocument()
  })

  it('renders an empty state when there are no campaigns', async () => {
    vi.mocked(listCampaigns).mockResolvedValue([])
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)
    expect(await screen.findByText('No campaigns yet')).toBeInTheDocument()
  })

  afterEach(() => signOut())
})
