import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/analytics/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    useSummary: vi.fn(), useTimeseries: vi.fn(), useCampaigns: vi.fn(), useEntities: vi.fn(), useAccounts: vi.fn(), useAccountPulse: vi.fn(),
  }
})
vi.mock('./BulkCampaignActions', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, runBulk: vi.fn() }
})
vi.mock('@/features/analytics/charts', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, MetricLineChart: () => <div data-testid="chart" /> }
})

import { useAccountPulse, useAccounts, useCampaigns, useEntities, useSummary, useTimeseries } from '@/features/analytics/api'
import { runBulk } from './BulkCampaignActions'
import { CampaignsHome } from './CampaignsHome'

const ok = (data: unknown) => ({ data, isPending: false, isError: false, error: null, refetch: vi.fn() })
const totals = { spend: 447.26, impressions: 57038, clicks: 522, ctr: 0.0092, video_views: 468, conversions: 0, revenue: 0, leads: 50 }

/** ADS-MANAGER-001 — one simple page: figures over a chart, levels, a table with a switch and totals, advanced one click away. */
describe('the ads manager', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.removeItem('ch-campaigns-layout')
    signInWith(['campaigns.view', 'campaigns.create', 'campaigns.pause'])
    useProject.setState({ currentProjectId: 'p1' } as never)
    vi.mocked(useSummary).mockReturnValue(ok({ current: totals, previous: totals, currency: 'USD', delta: {}, reported: {}, rows_in_scope: true }) as never)
    vi.mocked(useTimeseries).mockReturnValue(ok([{ date: '2026-11-17', ...totals }]) as never)
    vi.mocked(useCampaigns).mockReturnValue(ok([
      { campaign_id: 'c1', campaign_name: '22Nov 2026 ــ Tamayoz Land Taif ــ LeadForm', provider: 'snapchat', objective: 'LEAD_GENERATION', status: 'active', last_active_on: '2099-01-01', ...totals, leads: 2 },
      { campaign_id: 'c2', campaign_name: 'Old', provider: 'meta', objective: 'OUTCOME_TRAFFIC', status: 'paused', last_active_on: null, ...totals },
    ]) as never)
    vi.mocked(useEntities).mockReturnValue(ok({ entities: [] }) as never)
    vi.mocked(useAccounts).mockReturnValue(ok([{ account_id: 'a1', provider: 'snapchat', account_name: 'Tamayoz', ...totals }]) as never)
    vi.mocked(useAccountPulse).mockReturnValue(ok([{ account_id: 'a1', provider: 'snapchat', state: 'running', active_campaigns: 3, daily: [] }]) as never)
  })
  afterEach(() => { signOut(); useProject.setState({ currentProjectId: null } as never) })

  it('opens on the simple page with the figures, the delivery filter and the campaigns table with totals', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    expect(await screen.findByTestId('ads-manager')).toBeInTheDocument()
    expect(screen.getByTestId('ads-manager-kpi-impressions')).toHaveTextContent('Impressions')
    expect(screen.getAllByTestId('ads-manager-row')).toHaveLength(2)
    expect(screen.getByTestId('ads-manager-totals')).toHaveTextContent('Total · 2')

    fireEvent.click(screen.getByTestId('ads-manager-delivery-serving'))
    expect(screen.getAllByTestId('ads-manager-row')).toHaveLength(1)

    fireEvent.change(screen.getByTestId('ads-manager-search'), { target: { value: 'nothing-like-this' } })
    expect(screen.getByTestId('ads-manager-empty')).toBeInTheDocument()
  })

  it('pauses a campaign on the platform only after the confirmation', async () => {
    vi.mocked(runBulk).mockResolvedValue([{ campaignId: 'c1', name: 'Taif', provider: 'snapchat', result: 'done', message: null }])
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    const first = (await screen.findAllByTestId('ads-manager-switch'))[0]!
    expect(first).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(first)
    expect(runBulk).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByTestId('ads-manager-switch-dialog').closest('[role="dialog"]') as HTMLElement).getByTestId('ads-manager-switch-confirm'))
    await waitFor(() => expect(runBulk).toHaveBeenCalledWith('p1', [{ id: 'c1', name: expect.any(String) }], 'pause'))
    expect(await screen.findByTestId('ads-manager-outcome')).toHaveTextContent('Platform confirmed')
  })

  it('lists the accounts as a level and keeps «Advanced view» one click away', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    fireEvent.click(await screen.findByTestId('ads-manager-level-accounts'))
    expect(screen.getByTestId('ads-manager-row')).toHaveTextContent('Tamayoz')
    expect(screen.getByTestId('ads-manager-row')).toHaveTextContent('Active campaigns: 3')

    fireEvent.click(screen.getByTestId('ads-manager-advanced'))
    expect(localStorage.getItem('ch-campaigns-layout')).toBe('advanced')
  })
})
