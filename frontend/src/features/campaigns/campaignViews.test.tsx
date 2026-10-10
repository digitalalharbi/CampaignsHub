import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import { campaignPage } from '@/test/campaignPage'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listCampaigns: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn().mockResolvedValue([]), listClientWorkspaces: vi.fn().mockResolvedValue([]), listUsers: vi.fn().mockResolvedValue([]) }))
vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))
import { listCampaigns } from './api'
import { getData } from '@/lib/api/client'

/**
 * CAMPAIGN-VIEWS-001 — Drafts · Scheduled · Paused · Change History on the Campaigns surface.
 */
const campaign = (id: string, status = 'active', starts_on: string | null = null, total_budget: number | null = 1000): UnifiedCampaign => ({
  id, project_id: 'p1', name: `Campaign ${id}`, objective: 'sales', status,
  total_budget, budget_currency: 'SAR', starts_on, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 0,
  created_at: null,
} as UnifiedCampaign)

const HISTORY = [
  { id: 'e1', action: 'campaign.created', label: 'أُنشئت الحملة', actor: 'Demo Owner', at: '2026-10-10T08:00:00Z', before: null, after: null, campaign_id: 'd', campaign_name: 'Campaign d', budget_currency: 'SAR' },
  { id: 'e2', action: 'campaign.updated', label: 'تعديل بيانات الحملة', actor: 'Demo Owner', at: '2026-10-10T09:00:00Z', before: { total_budget: 1000 }, after: { total_budget: 1500 }, campaign_id: 'a', campaign_name: 'Campaign a', budget_currency: 'SAR' },
]

describe('the campaigns workspace and its record-state views', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'campaigns.manage'])
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([campaign('a'), campaign('d', 'draft'), campaign('s', 'active', '2026-12-01'), campaign('p', 'paused', null, null)]))
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/campaign-activity')) return Promise.resolve(HISTORY)
      if (path.includes('/metrics/summary')) return Promise.resolve({ current: {}, previous: {}, delta: {}, reported: {}, currency: 'SAR', rows_in_scope: true, previous_rows_in_scope: true, previous_range: { from: '2026-08-12', to: '2026-09-10' }, objective_families_in_scope: ['sales'] })
      return Promise.resolve([])
    })
  })
  afterEach(() => signOut())

  it('counts drafts, scheduled and paused as their own bands', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })
    await screen.findByTestId('campaigns-bands')
    // The strip draws at zero while the list loads; the counts are the thing under test.
    await waitFor(() => expect(screen.getByTestId('campaigns-band-drafts')).toHaveAttribute('data-count', '1'))
    expect(screen.getByTestId('campaigns-band-scheduled')).toHaveAttribute('data-count', '1')
    expect(screen.getByTestId('campaigns-band-paused')).toHaveAttribute('data-count', '1')
    expect(screen.getByTestId('campaigns-band-ended')).toHaveAttribute('data-count', '0')
  })

  it('narrows the table to the band whose chip was pressed, and keeps the counts whole', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })
    await screen.findByTestId('campaigns-bands')
    await waitFor(() => expect(screen.getByTestId('campaigns-band-drafts')).toHaveAttribute('data-count', '1'))
    fireEvent.click(screen.getByTestId('campaigns-band-drafts'))
    // The chip opens the list; the list names what narrowed it and offers the way back.
    const active = await screen.findByTestId('campaigns-band-active')
    expect(active.textContent).toContain('مسودات')
    expect(active.textContent).toContain('1')
    await waitFor(() => expect(screen.getByText('Campaign d')).toBeInTheDocument())
    expect(within(document.body).queryByText('Campaign a')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('campaigns-band-clear'))
    await waitFor(() => expect(screen.getByText('Campaign a')).toBeInTheDocument())
    expect(screen.queryByTestId('campaigns-band-active')).not.toBeInTheDocument()
  })

  it('shows the project change history as a view, each event naming its campaign and its change', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })
    await screen.findByTestId('campaigns-bands')
    fireEvent.click(screen.getByTestId('view-history'))
    const history = await screen.findByTestId('project-change-history')
    expect(within(history).getByText(/Campaign d/)).toBeInTheDocument()
    expect(within(history).getByText(/أُنشئت الحملة/)).toBeInTheDocument()
    // The audited change, written the product's way.
    expect(history.textContent).toContain('1,000')
    expect(history.textContent).toContain('1,500')
  })
})
