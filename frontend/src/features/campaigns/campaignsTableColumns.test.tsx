import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'
import type { Project } from '@/features/projects/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { campaignPage } from '@/test/campaignPage'

vi.mock('./api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listClientWorkspaces: vi.fn().mockResolvedValue([]), listUsers: vi.fn() }))
vi.mock('@/features/dashboard/savedViews', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useSavedViews: vi.fn(), useSaveView: vi.fn(), useDeleteView: vi.fn(), useSetDefaultView: vi.fn() }
})
import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'
import { useDeleteView, useSaveView, useSavedViews, useSetDefaultView } from '@/features/dashboard/savedViews'

const project = (id: string, name: string): Project => ({ id, client_workspace_id: 'w1', name, status: 'active', setup_completion: 0, account_manager_id: null, created_at: null })
const campaign = (id: string, name: string): UnifiedCampaign => ({
  id, project_id: 'p1', name, objective: 'sales', status: 'active', total_budget: 1000, budget_currency: 'SAR',
  starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 2, created_at: null,
})

/** CAMPAIGNS-TABLE-001 — the campaigns table's columns are chosen, remembered and saved as views. */
describe('the campaigns table columns', () => {
  const mutation = () => ({ mutate: vi.fn(), isPending: false })
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.removeItem('ch-campaign-columns')
    vi.mocked(listProjects).mockResolvedValue([project('p1', 'Project A')])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([campaign('c1', 'National Day')]))
    vi.mocked(useSavedViews).mockReturnValue({ data: [], isPending: false } as never)
    vi.mocked(useSaveView).mockReturnValue(mutation() as never)
    vi.mocked(useDeleteView).mockReturnValue(mutation() as never)
    vi.mocked(useSetDefaultView).mockReturnValue(mutation() as never)
    useProject.getState().setCurrentProjectId('p1')
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  const openTable = async () => {
    fireEvent.click(await screen.findByTestId('view-table'))
    return within(await screen.findByTestId('campaigns-table'))
  }

  it('opens on the performance preset and switches to engagement with one click', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })
    const table = await openTable()
    expect(screen.getByTestId('campaigns-preset-performance')).toHaveAttribute('aria-pressed', 'true')
    expect(table.getByTestId('campaigns-head-cost')).toHaveTextContent('تكلفة النتيجة')
    expect(table.queryByTestId('campaigns-head-ctr')).toBeNull()

    fireEvent.click(screen.getByTestId('campaigns-preset-engagement'))
    expect(table.getByTestId('campaigns-head-ctr')).toHaveTextContent('نسبة النقر')
    expect(table.queryByTestId('campaigns-head-cost')).toBeNull()
    expect(JSON.parse(localStorage.getItem('ch-campaign-columns') ?? '[]')).toContain('ctr')
  })

  it('a hand-picked column makes the set custom, and it is remembered', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    const table = await openTable()
    fireEvent.click(screen.getByTestId('campaigns-columns-toggle'))
    fireEvent.click(screen.getByTestId('campaigns-column-impressions'))
    expect(table.getByTestId('campaigns-head-impressions')).toHaveTextContent('Impressions')
    expect(screen.getByTestId('campaigns-preset-custom')).toBeInTheDocument()
    expect(JSON.parse(localStorage.getItem('ch-campaign-columns') ?? '[]')).toContain('impressions')
  })

  it('applies a saved view as one change: columns, status and order together', async () => {
    vi.mocked(useSavedViews).mockReturnValue({ data: [{ id: 'v1', name: 'مبيعات رمضان', module: 'campaigns', filters: { columns: ['spend', 'revenue', 'roas'], status: 'active', sort: 'spend', dir: 'asc' }, date_range: { days: 7 }, comparison: null, sort_order: 0, is_default: false }], isPending: false } as never)
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })
    const table = await openTable()
    fireEvent.click(screen.getByTestId('campaign-view-v1'))
    expect(table.getByTestId('campaigns-head-roas')).toHaveTextContent('العائد على الإنفاق')
    expect(table.queryByTestId('campaigns-head-cost')).toBeNull()
    await waitFor(() => expect(listCampaigns).toHaveBeenLastCalledWith('p1', expect.objectContaining({ sort: 'spend', dir: 'asc' })))
  })

  it('saves the current columns and filters under a name', async () => {
    const save = mutation()
    vi.mocked(useSaveView).mockReturnValue(save as never)
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openTable()
    fireEvent.click(screen.getByTestId('campaign-view-new'))
    fireEvent.change(screen.getByTestId('campaign-view-name'), { target: { value: 'Q4 board' } })
    fireEvent.click(screen.getByTestId('campaign-view-save'))
    expect(save.mutate).toHaveBeenCalledWith(expect.objectContaining({ name: 'Q4 board', filters: expect.objectContaining({ columns: expect.arrayContaining(['spend', 'results']) }), date_range: { days: 30 } }), expect.anything())
  })
})
