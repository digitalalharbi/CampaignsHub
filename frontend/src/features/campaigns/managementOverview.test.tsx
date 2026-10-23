import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ManagementOverview } from './ManagementOverview'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/features/analytics/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useSummary: vi.fn(), useTimeseries: vi.fn(), usePlatforms: vi.fn() }
})
import { usePlatforms, useSummary, useTimeseries } from '@/features/analytics/api'

const totals = (over: Record<string, number | null> = {}) => ({
  impressions: 1000, clicks: 50, conversions: 10, spend: 200, revenue: 800, roas: 4, cpa: 20, ctr: 0.05, cpc: 4, cpm: 200,
  reach: 900, video_views: 0, video_completions: 0, landing_page_views: 0, leads: 0, qualified_leads: 0, purchases: 10, installs: 0,
  registrations: 0, in_app_events: 0, engagements: 0, frequency: 1.1, cpl: null, cpi: null, cpe: null, aov: 80, conversion_rate: 0.2,
  engagement_rate: null, video_completion_rate: null, ...over,
})
const ok = (data: unknown) => ({ data, isPending: false, isLoading: false, isError: false, error: null, refetch: vi.fn() })

/** MGMT-PAGES-001 — the management pages carry the report's blocks over the same metrics as their table. */
describe('the management overview', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['analytics.view'])
    vi.mocked(useSummary).mockReturnValue(ok({ current: totals(), previous: totals({ spend: 100 }), delta: { spend: 1 }, currency: 'SAR', reported: {}, rows_in_scope: true, previous_rows_in_scope: true, previous_range: { from: '2026-09-01', to: '2026-09-30' } }) as never)
    vi.mocked(useTimeseries).mockReturnValue(ok([{ date: '2026-10-01', ...totals() }, { date: '2026-10-02', ...totals({ spend: 300 }) }]) as never)
    vi.mocked(usePlatforms).mockReturnValue(ok([{ provider: 'meta', spend_share: 0.6, ...totals({ conversions: 6 }) }, { provider: 'snapchat', spend_share: 0.4, ...totals({ conversions: 4 }) }]) as never)
  })
  afterEach(() => signOut())

  it('draws the KPI strip, the trend and the results share from the same filtered project metrics', async () => {
    renderWithProviders(<ManagementOverview projectId="p1" range={{ from: '2026-10-01', to: '2026-10-30' }} filters={{ provider: ['meta'] }} testid="entity-overview-ad" />, { locale: 'ar' })

    expect(await screen.findByTestId('entity-overview-ad-trend')).toBeInTheDocument()
    expect(screen.getByTestId('entity-overview-ad-share')).toBeInTheDocument()
    expect(screen.getByText('من أين جاءت النتائج')).toBeInTheDocument()
    expect(useSummary).toHaveBeenCalledWith('p1', { from: '2026-10-01', to: '2026-10-30' }, { provider: ['meta'] })
    expect(usePlatforms).toHaveBeenCalledWith('p1', { from: '2026-10-01', to: '2026-10-30' }, { provider: ['meta'] })
  })

  it('says when no results exist instead of drawing an empty ring', async () => {
    vi.mocked(usePlatforms).mockReturnValue(ok([{ provider: 'meta', spend_share: 1, ...totals({ conversions: 0 }) }]) as never)
    renderWithProviders(<ManagementOverview projectId="p1" range={{ from: '2026-10-01', to: '2026-10-30' }} filters={{}} testid="entity-overview-ad_set" />, { locale: 'en' })

    expect(await screen.findByTestId('entity-overview-ad_set-share-empty')).toHaveTextContent('No results recorded')
    expect(screen.queryByTestId('entity-overview-ad_set-share')).toBeNull()
  })
})
