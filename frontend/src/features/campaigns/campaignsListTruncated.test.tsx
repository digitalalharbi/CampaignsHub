import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'

import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'
import type { Project } from '@/features/projects/api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listUsers: vi.fn() }))

import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'
import { useProject } from '@/stores/project'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the workspace list is bounded, and the page says when it stopped.
 *
 * `index()` returned every campaign in the project. The weight is the smaller half; the larger half
 * is that a list which returns everything and a list which stops silently look identical, so an
 * operator whose campaign is missing cannot tell whether it was never synced or simply not shown.
 */
const project = (id: string, name: string): Project =>
  ({ id, client_workspace_id: 'w1', name, status: 'active', setup_completion: 0, account_manager_id: null, created_at: null })

const campaign = (id: string, name: string): UnifiedCampaign =>
  ({
    id, project_id: 'p1', name, objective: 'sales', status: 'active', total_budget: 1000,
    budget_currency: 'SAR', starts_on: null, ends_on: null, primary_conversion_purpose: null,
    attribution_model: null, attribution_window: null, owner_id: null, target_kpi: null,
    audience: null, regions: null, external_campaigns_count: 2, created_at: null,
  }) as unknown as UnifiedCampaign

const page = (truncated: boolean | null, limit: number | null = 500) => ({
  campaigns: [campaign('c1', 'National Day')],
  truncated,
  limit,
})

describe('the campaigns workspace at the server cap', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'Project A')])
    vi.mocked(listUsers).mockResolvedValue([])
    useProject.getState().setCurrentProjectId('p1')
  })

  it('says the list stopped, and points at the filters that reach the rest', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(page(true) as never)
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    const note = await screen.findByTestId('campaigns-truncated')
    expect(note).toHaveTextContent('Showing 500 campaigns only')
    expect(note).toHaveTextContent('filters')
  })

  it('says nothing when the list is complete', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(page(false) as never)
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    await screen.findByText('National Day')
    expect(screen.queryByTestId('campaigns-truncated')).not.toBeInTheDocument()
  })

  /*
   * A server that has not shipped the flag says nothing, and the page must not fill it in. Silence
   * is «I was not told», which is different from «complete».
   */
  it('does not invent a completeness claim the server never made', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(page(null, null) as never)
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    await screen.findByText('National Day')
    expect(screen.queryByTestId('campaigns-truncated')).not.toBeInTheDocument()
  })

  it('says it in Arabic too', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(page(true) as never)
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })

    const note = await screen.findByTestId('campaigns-truncated')
    expect(note).toHaveTextContent('يُعرض 500')
    expect(note.textContent ?? '').not.toMatch(/[a-z]/)
  })
})
