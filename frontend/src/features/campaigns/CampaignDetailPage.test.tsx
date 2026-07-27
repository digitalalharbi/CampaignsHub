import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignDetailPage } from './CampaignDetailPage'
import type { ExternalCampaign, UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  getCampaign: vi.fn(),
  listLinkedExternal: vi.fn(),
  campaignAction: vi.fn(),
  archiveCampaign: vi.fn(),
  unlinkExternal: vi.fn(),
  // referenced by nested modals (rendered but closed)
  createCampaign: vi.fn(),
  updateCampaign: vi.fn(),
  linkExternal: vi.fn(),
  listExternalCampaigns: vi.fn(),
  listLinkSuggestions: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({ listUsers: vi.fn() }))

// The command-center tabs fire their own metrics/activity/alerts/reports/creatives queries. This unit
// test only exercises the header + platforms; stub the metrics hooks so no unmocked request is issued
// (an unhandled fetch from a background tab was the leading suspect behind the G-001 cross-file flake).
// NOTE: vi.mock is hoisted above module-level consts, so the stubs are defined INSIDE the factory.
vi.mock('./metrics', () => {
  const queryStub = () => ({ data: undefined, isLoading: false, isError: false, error: null, refetch: vi.fn() })
  const mutationStub = () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false, isError: false })
  return {
    useCampaignSummary: queryStub,
    useCampaignPerformance: queryStub,
    useCampaignPlatforms: queryStub,
    useCampaignBudget: queryStub,
    useCampaignFunnel: queryStub,
    useCampaignActivity: queryStub,
    useCampaignAlerts: queryStub,
    useCampaignReports: queryStub,
    useCampaignAnnotations: queryStub,
    useCampaignCreatives: queryStub,
    useCreateAnnotation: mutationStub,
    useUpdateAnnotation: mutationStub,
  }
})

import { archiveCampaign, campaignAction, getCampaign, listLinkedExternal, unlinkExternal } from './api'

const DETAIL_ROUTE = { route: '/campaigns/p1/c1', path: '/campaigns/:projectId/:campaignId' }

const active: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'National Day', objective: 'sales', status: 'active', total_budget: 50000,
  budget_currency: 'SAR', starts_on: '2026-09-20', ends_on: '2026-09-25', primary_conversion_purpose: null,
  attribution_model: null, attribution_window: null, owner_id: 3, target_kpi: null, audience: 'KSA shoppers',
  regions: null, external_campaigns_count: 1, created_at: null,
}
function linked(id: string, name: string): ExternalCampaign {
  return {
    id, unified_campaign_id: 'c1', external_account_id: 'a1', provider: 'sandbox', external_id: 'sbx-' + id,
    name, status: 'active', objective: null, daily_budget: null, lifetime_budget: null, currency: 'SAR',
    is_linked: true, linked_at: null, last_synced_at: null,
  }
}

describe('CampaignDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getCampaign).mockResolvedValue(active)
    vi.mocked(listLinkedExternal).mockResolvedValue([linked('e1', 'Sandbox Awareness')])
  })
  afterEach(() => signOut())

  it('renders the campaign and honours campaigns.view (no-permission fallback otherwise)', async () => {
    signInWith([]) // no campaigns.view
    const view = renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    expect(await screen.findByText(/permission/i)).toBeInTheDocument()
    expect(getCampaign).not.toHaveBeenCalled()

    view.unmount()
    signInWith(['campaigns.view'])
    renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    expect(await screen.findByText('National Day')).toBeInTheDocument()
  })

  it('pause action is gated by campaigns.pause and calls the API', async () => {
    signInWith(['campaigns.view']) // can view, cannot pause
    const view = renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    await screen.findByText('National Day')
    expect(screen.queryByText('Pause')).not.toBeInTheDocument()

    view.unmount()
    vi.mocked(campaignAction).mockResolvedValue({ ...active, status: 'paused' })
    signInWith(['campaigns.view', 'campaigns.pause'])
    renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    await screen.findByText('National Day')
    fireEvent.click(screen.getByText('Pause'))
    await waitFor(() => expect(campaignAction).toHaveBeenCalledWith('p1', 'c1', 'pause'))
  })

  it('shows linked external campaigns and unlinks with campaigns.update', async () => {
    vi.mocked(unlinkExternal).mockResolvedValue(null)
    signInWith(['campaigns.view', 'campaigns.update'])
    renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    await screen.findByText('National Day')

    // Switch to the Platforms tab — the command center groups linked externals into platform cards.
    fireEvent.click(screen.getByText('Platforms'))
    expect(await screen.findByText('Sandbox Awareness')).toBeInTheDocument()

    fireEvent.click(screen.getByText('فك الربط'))
    await waitFor(() => expect(unlinkExternal).toHaveBeenCalledWith('p1', 'c1', 'e1'))
  })

  it('archive calls the API', async () => {
    vi.mocked(archiveCampaign).mockResolvedValue(null)
    signInWith(['campaigns.view', 'campaigns.update'])
    renderWithProviders(<CampaignDetailPage />, DETAIL_ROUTE)
    await screen.findByText('National Day')
    fireEvent.click(screen.getByText('Archive'))
    await waitFor(() => expect(archiveCampaign).toHaveBeenCalledWith('p1', 'c1'))
  })
})
