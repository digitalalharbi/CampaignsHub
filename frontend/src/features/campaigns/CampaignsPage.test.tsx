import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
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
import { campaignPage } from '@/test/campaignPage'

describe('CampaignsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'Project A'), project('p2', 'Project B')])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([campaign('c1', 'National Day')]))
    useProject.getState().setCurrentProjectId('p1')
  })

  /** The page opens on the overview; the list lives behind the cards/table modes (CAMPAIGN-010). */
  const openList = async () => fireEvent.click(await screen.findByTestId('view-cards'))

  it('lists campaigns for the auto-selected project', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)

    await openList()
    expect(await screen.findByText('National Day')).toBeInTheDocument()
    await waitFor(() => expect(listCampaigns).toHaveBeenCalledWith('p1', expect.anything()))
  })

  it('offers all five view modes and switches between them', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)

    for (const mode of ['overview', 'cards', 'table', 'compare', 'attention']) {
      expect(await screen.findByTestId(`view-${mode}`)).toBeInTheDocument()
    }

    fireEvent.click(screen.getByTestId('view-compare'))
    expect(await screen.findByText('اختر حملات للمقارنة')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('view-attention'))
    /*
     * ATTENTION-REQUEST-STATE-001 — rewritten, and the claim it was written for is kept.
     *
     * It asserted an `attention-row` for «one linked, active, on-budget campaign with no metrics
     * loaded», on the reasoning that it must never be «silently treated as healthy». The reasoning is
     * right and the rendering was the other false claim: no metrics are loaded here because nothing in
     * this test answers that request, so the row said «no performance data for this campaign in the
     * selected period» — a finding about the account manufactured out of a request still in flight,
     * and the transient that cost the webkit gate four runs.
     *
     * Neither silently healthy nor falsely flagged: the view says it has not judged them yet. That a
     * campaign the platform genuinely reported nothing for IS still raised is asserted where the
     * figures actually arrive — `campaignAttentionRequestState.test.tsx`, «still raises the verdict
     * once the figures are in».
     */
    expect(await screen.findByText(/have not been judged yet/i)).toBeInTheDocument()
    expect(screen.queryByTestId('attention-row')).toBeNull()
    expect(screen.queryByText(/Nothing needs attention/i)).toBeNull()
  })

  it('filters by taxonomy chips without leaving the list', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)

    await openList()
    await screen.findByText('National Day')
    const chips = await screen.findAllByTestId('taxonomy-chip')
    expect(chips.length).toBeGreaterThan(1)

    fireEvent.click(chips[1])
    await waitFor(() => expect(listCampaigns).toHaveBeenCalledWith('p1', expect.objectContaining({ status: 'draft' })))
  })

  it('shows the create button only with campaigns.create', async () => {
    signInWith(['campaigns.view'])
    const view = renderWithProviders(<CampaignsPage />)
    await openList()
    await screen.findByText('National Day')
    expect(screen.queryByText('New campaign')).not.toBeInTheDocument()

    view.unmount()
    signInWith(['campaigns.view', 'campaigns.create'])
    renderWithProviders(<CampaignsPage />)
    await openList()
    await screen.findByText('National Day')
    expect(screen.getByText('New campaign')).toBeInTheDocument()
  })

  it('renders an empty state when there are no campaigns', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([]))
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignsPage />)
    await openList()
    expect(await screen.findByText('No campaigns yet')).toBeInTheDocument()
  })

  afterEach(() => signOut())
})
