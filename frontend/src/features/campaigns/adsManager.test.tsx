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
vi.mock('@/features/content/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listCreatives: vi.fn().mockResolvedValue({ creatives: [
      { id: 'cr1', name: 'Taif reel', provider: 'snapchat', ad_account: { id: 'a1', name: 'Tamayoz' }, preview: { state: 'available', kind: 'video', thumbnail_url: 'https://x/t.jpg', image_url: null } },
    ] }),
  }
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
    localStorage.removeItem('ch-ads-manager-kpis')
    localStorage.removeItem('ch-ads-manager-preset')
    signInWith(['campaigns.view', 'campaigns.create', 'campaigns.pause'])
    useProject.setState({ currentProjectId: 'p1' } as never)
    vi.mocked(useSummary).mockReturnValue(ok({ current: totals, previous: totals, currency: 'USD', delta: {}, reported: {}, rows_in_scope: true }) as never)
    vi.mocked(useTimeseries).mockReturnValue(ok([{ date: '2026-11-17', ...totals }]) as never)
    vi.mocked(useCampaigns).mockReturnValue(ok([
      { campaign_id: 'c1', campaign_name: '22Nov 2026 ــ Tamayoz Land Taif ــ LeadForm', provider: 'snapchat', objective: 'LEAD_GENERATION', objective_family: 'leads', status: 'active', last_active_on: '2099-01-01', ...totals, leads: 2 },
      { campaign_id: 'c2', campaign_name: 'Old', provider: 'meta', objective: 'OUTCOME_SALES', objective_family: 'sales', status: 'paused', last_active_on: null, ...totals },
    ]) as never)
    vi.mocked(useEntities).mockReturnValue(ok({ entities: [] }) as never)
    vi.mocked(useAccounts).mockReturnValue(ok([{ account_id: 'a1', provider: 'snapchat', account_name: 'Tamayoz', ...totals }]) as never)
    vi.mocked(useAccountPulse).mockReturnValue(ok([{ account_id: 'a1', provider: 'snapchat', state: 'running', active_campaigns: 3, daily: [] }]) as never)
  })
  afterEach(() => { signOut(); useProject.setState({ currentProjectId: null } as never) })

  it('opens on the simple page with the figures, the delivery filter and the campaigns table with totals', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    expect(await screen.findByTestId('ads-manager')).toBeInTheDocument()
    expect(screen.getByTestId('ads-manager-kpi-1')).toHaveAttribute('data-metric', 'impressions')
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

  it('lists the accounts as a level, and there is no advanced view to switch to', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    fireEvent.click(await screen.findByTestId('ads-manager-level-accounts'))
    const table = screen.getByTestId('ads-manager-table-accounts-total').closest('table') as HTMLElement
    expect(within(table).getByTestId('ads-manager-row')).toHaveTextContent('Tamayoz')
    expect(within(table).getByTestId('ads-manager-account-campaigns')).toHaveTextContent('Active 3')
    expect(await within(table).findByTestId('ads-manager-account-creative')).toHaveAttribute('title', 'Taif reel')

    fireEvent.click(within(table).getByTestId('ads-manager-account-campaigns'))
    expect(screen.getByTestId('ads-manager-level-campaigns')).toHaveAttribute('aria-selected', 'true')
    expect(vi.mocked(useCampaigns)).toHaveBeenLastCalledWith('p1', expect.anything(), { provider: ['snapchat'] })

    expect(screen.queryByTestId('ads-manager-advanced')).toBeNull()
  })

  it('lets each figure over the chart be chosen, and the columns follow the chosen objective', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    fireEvent.change(await screen.findByTestId('ads-manager-kpi-pick-4'), { target: { value: 'leads' } })
    expect(screen.getByTestId('ads-manager-kpi-4')).toHaveAttribute('data-metric', 'leads')
    expect(JSON.parse(localStorage.getItem('ch-ads-manager-kpis') ?? '[]')[4]).toBe('leads')

    fireEvent.change(screen.getByTestId('ads-manager-preset'), { target: { value: 'sales' } })
    expect(screen.getByTestId('ads-manager-kpi-1')).toHaveAttribute('data-metric', 'purchases')
    expect(screen.getByTestId('ads-manager-kpi-1')).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByTestId('ads-manager-kpi-2')).toHaveAttribute('data-metric', 'revenue')

    fireEvent.change(screen.getByTestId('ads-manager-preset'), { target: { value: 'leads' } })
    expect(localStorage.getItem('ch-ads-manager-preset')).toBe('leads')
    expect(screen.getByTestId('ads-manager-kpi-1')).toHaveAttribute('data-metric', 'leads')
    expect(screen.getByTestId('ads-manager-table-campaigns-total').closest('table')).toHaveTextContent('Cost per lead')
  })

  it('opens a row into its details with the way down to its ad sets', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    fireEvent.click((await screen.findAllByTestId('ads-manager-row'))[0]!)
    const details = screen.getByTestId('ads-manager-details')
    expect(details).toHaveTextContent('Campaign')
    fireEvent.click(within(details).getByTestId('ads-manager-drill-ad_sets'))
    expect(screen.getByTestId('ads-manager-level-ad_sets')).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByTestId('ads-manager-parent')).toHaveTextContent('Tamayoz Land Taif')
  })

  it('offers no lead figure where no campaign in scope was bought for leads', async () => {
    vi.mocked(useCampaigns).mockReturnValue(ok([
      { campaign_id: 'c9', campaign_name: 'Sales only', provider: 'meta', objective: 'OUTCOME_SALES', objective_family: 'sales', status: 'active', last_active_on: '2099-01-01', ...totals, leads: 22 },
    ]) as never)
    localStorage.setItem('ch-ads-manager-kpis', JSON.stringify(['spend', 'leads', 'clicks', 'ctr', 'video_views']))
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    const slot = await screen.findByTestId('ads-manager-kpi-1')
    expect(slot).toHaveAttribute('data-metric', 'impressions')
    const options = [...within(screen.getByTestId('ads-manager-kpi-pick-1')).getAllByRole('option')].map((o) => o.getAttribute('value'))
    expect(options).not.toContain('leads')
    expect(options).not.toContain('cpl')
    expect(options).toContain('purchases')
    const presets = [...within(screen.getByTestId('ads-manager-preset')).getAllByRole('option')].map((o) => o.getAttribute('value'))
    expect(presets).toEqual(['performance', 'sales'])
  })

  it('keeps every platform to choose from after one is picked', async () => {
    vi.mocked(useAccountPulse).mockReturnValue(ok([
      { account_id: 'a1', provider: 'snapchat', state: 'running', active_campaigns: 3, daily: [] },
      { account_id: 'a2', provider: 'meta', state: 'running', active_campaigns: 1, daily: [] },
      { account_id: 'a3', provider: 'tiktok', state: 'stopped', active_campaigns: 0, daily: [] },
    ]) as never)
    const all = vi.mocked(useCampaigns).getMockImplementation()?.('p1' as never, {} as never) ?? ok([])
    // The campaigns answer is narrowed by the chosen platform, as the server narrows it.
    vi.mocked(useCampaigns).mockImplementation(((_p: unknown, _r: unknown, f?: { provider?: string[] }) =>
      (f?.provider?.length ? ok([]) : all)) as never)
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    const strip = await screen.findByTestId('ads-manager-platforms')
    fireEvent.click(within(strip).getByRole('button', { name: /snapchat/i }))
    const after = screen.getByTestId('ads-manager-platforms')
    expect(within(after).getByRole('button', { name: /meta/i })).toBeInTheDocument()
    expect(within(after).getByRole('button', { name: /tiktok/i })).toBeInTheDocument()
    expect(within(after).getByRole('button', { name: /all platforms/i })).toBeInTheDocument()
  })

  it('brings the advanced tools in: ticked campaigns get bulk actions and a comparison', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns' })

    const boxes = await screen.findAllByTestId('ads-manager-select')
    expect(screen.queryByTestId('ads-manager-compare-open')).toBeNull()
    fireEvent.click(boxes[0]!)
    expect(screen.getByTestId('campaigns-bulk-count')).toHaveTextContent('1')
    fireEvent.click(boxes[1]!)
    expect(screen.getByTestId('ads-manager-compare-open')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('ads-manager-select-all'))
    expect(screen.queryByTestId('campaigns-bulk')).toBeNull()
    expect(screen.queryByTestId('ads-manager-history')).toBeNull()
    expect(screen.queryByTestId('ads-manager-export')).toBeNull()
  })

  it('opens on the level an old address named', async () => {
    renderWithProviders(<CampaignsHome />, { locale: 'en', route: '/agency/campaigns?level=ad_sets' })
    expect(await screen.findByTestId('ads-manager-level-ad_sets')).toHaveAttribute('aria-selected', 'true')
  })
})
