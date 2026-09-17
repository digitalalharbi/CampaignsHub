import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveSharedReport } from '../LiveSharedReport'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchLiveShared: vi.fn(),
  fetchLiveContent: vi.fn(),
  fetchLivePlatform: vi.fn(),
}))

import { fetchLiveContent, fetchLivePlatform, fetchLiveShared } from '../api'

/**
 * REPORT-DRILLDOWN-001 — a platform opens from the comparison into a drawer, and closes back to the
 * dashboard it came from. Visual, not prose: KPI blocks, the two shares, the trend, and content.
 */
const ad = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03, roas: 3.2, cpa: spend / 12,
})

const roster = (name: string, provider: string, key: string, spend: number) => ({
  name, provider, content_key: key, format: 'image', metrics: { spend, impressions: 10_000, clicks: 300, conversions: 12, ctr: 0.03 },
})

function payloadFor(providers: string[]) {
  const all = [
    { provider: 'meta', spend: 700, impressions: 70_000, clicks: 2100, conversions: 40, cpa: 17.5, ctr: 0.03 },
    { provider: 'snapchat', spend: 300, impressions: 30_000, clicks: 900, conversions: 10, cpa: 30, ctr: 0.03 },
  ]
  const rows = providers.length === 0 ? all : all.filter((p) => providers.includes(p.provider))
  const spend = rows.reduce((a, r) => a + r.spend, 0)
  const conversions = rows.reduce((a, r) => a + r.conversions, 0)

  return {
    period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
    currency: 'SAR',
    totals: { spend, conversions, impressions: 100_000, clicks: 3000, revenue: 3000, cpa: spend / conversions, ctr: 0.03, cpc: 0.33, cpm: 10 },
    deltas: { spend: 0.1, conversions: -0.05 },
    timeseries: [{ date: '2026-09-15', spend: spend / 2, clicks: 1500, conversions: conversions / 2 }, { date: '2026-09-16', spend: spend / 2, clicks: 1500, conversions: conversions / 2 }],
    platforms: rows,
    campaigns: [],
    funnel: [{ stage: 'impressions', label: 'Impressions', reported: true, count: 100_000, from_stage: null, step_rate: null, cost_per: null }],
    objective_performance: {
      paths: [],
      direct: { label_ar: 'مباشر', label_en: 'Direct', spend: 600, orders: 40, revenue: 0, cpa: 15, roas: null, aov: null, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
      blended: { label_ar: 'مدمج', label_en: 'Blended', spend, orders: 40, revenue: 0, blended_cpa: 25, blended_roas: null, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 400 },
    },
    ads: rows.map((r) => ad(`Best on ${r.provider}`, r.provider, `key-best-${r.provider}`, r.spend)),
    ads_weakest: rows.map((r) => ad(`Weak on ${r.provider}`, r.provider, `key-weak-${r.provider}`, 5)),
    ads_groups: [],
    ads_roster: rows.map((r) => roster(`Roster ${r.provider}`, r.provider, `key-roster-${r.provider}`, r.spend)),
    creatives_in_scope: rows.length,
    budget: [{ provider: 'meta', budget: 2000, budget_currency: 'SAR', spent: 700, spent_currency: 'SAR', remaining: 1300, consumed_pct: 35, pace: 1, pacing_basis: 'comparable' }],
    objective_leaders: { paths: [{
      path: 'conversion', label_ar: 'التحويل', label_en: 'Conversion & sales', metric: 'orders', comparable: true, comparable_reason: '', campaigns: 2,
      strongest: { id: 'meta', name: 'meta', objective: 'sales', metric: 'cpa', value: 17.5 },
      weakest: { id: 'snapchat', name: 'snapchat', objective: 'sales', metric: 'cpa', value: 30 },
    }] },
    sections: { attribution: false },
    store_funnel: null,
    freshness: [],
    available: { providers: ['meta', 'snapchat'], campaigns: [], earliest: '2026-01-01', latest: '2026-09-16' },
    metrics: [],
    applied: { from: '2026-08-18', to: '2026-09-16', providers, campaigns: [] },
  }
}


const platformPayload = {
  period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
  provider: 'meta',
  objective: { key: 'sales', ranking: 'roas' },
  objectives: [{ path: 'conversion', label_ar: 'التحويل والمبيعات', label_en: 'Conversion & sales', metrics: { spend: 700, orders: 40, cpa: 17.5, revenue: 2100, roas: 3 } }],
  totals: { spend: 700, conversions: 40, impressions: 70_000, clicks: 2100 },
  timeseries: [{ date: '2026-09-15', spend: 350, clicks: 1050, conversions: 20 }, { date: '2026-09-16', spend: 350, clicks: 1050, conversions: 20 }],
  shares: { spend: { value: 700, total: 1000, share: 0.7 }, outcome: { metric: 'revenue', value: 2100, total: 3000, share: 0.7 } },
  ads: [ad('Best on meta', 'meta', 'key-best-meta', 700)],
  ads_weakest: [ad('Weak on meta', 'meta', 'key-weak-meta', 5)],
  breakdowns: { platform_drilldown: true, content_drilldown: true },
}

function answer(breakdowns?: Record<string, boolean>) {
  vi.mocked(fetchLiveShared).mockImplementation(async (_token, opts) => (
    { status: 200, envelope: { data: { ...payloadFor(opts.providers), ...(breakdowns ? { breakdowns } : {}) } } }
  ) as never)
  vi.mocked(fetchLivePlatform).mockResolvedValue({ status: 200, envelope: { data: platformPayload } } as never)
  vi.mocked(fetchLiveContent).mockResolvedValue({ status: 404, envelope: {} } as never)
}

describe('the platform drill-down', () => {
  beforeEach(() => answer())
  afterEach(() => vi.clearAllMocks())

  it('opens from the comparison row and closes back to the dashboard', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('live-platform-open-meta'))
    const drawer = await screen.findByTestId('live-platform-drawer')
    await waitFor(() => expect(drawer).toHaveAttribute('data-state', 'ready'))
    expect(fetchLivePlatform).toHaveBeenCalledWith('tok', 'meta', expect.objectContaining({ from: '2026-08-18', to: '2026-09-16' }))

    expect(within(drawer).getByTestId('live-platform-drawer-kpis-conversion')).toBeInTheDocument()
    expect(within(drawer).getByTestId('live-platform-share-spend')).toHaveTextContent('70%')
    expect(within(drawer).getByTestId('live-platform-share-outcome')).toHaveTextContent('70%')
    expect(within(drawer).getByTestId('live-platform-drawer-trend')).toBeInTheDocument()
    expect(within(drawer).getByTestId('live-platform-drawer-top')).toHaveTextContent('Best on meta')
    expect(within(drawer).getByTestId('live-platform-drawer-weakest')).toHaveTextContent('Weak on meta')
    expect(drawer.textContent ?? '').not.toMatch(/campaign/i)

    fireEvent.click(within(drawer).getByTestId('live-platform-drawer-close'))
    expect(screen.queryByTestId('live-platform-drawer')).not.toBeInTheDocument()
    expect(screen.getByTestId('live-mode-dashboard')).toBeInTheDocument()
  })

  it('Escape closes the content dialog first, then the drawer', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('live-platform-open-meta'))
    const drawer = await screen.findByTestId('live-platform-drawer')
    await waitFor(() => expect(drawer).toHaveAttribute('data-state', 'ready'))

    fireEvent.click(within(within(drawer).getByTestId('live-platform-drawer-top')).getByTestId('live-content-tile'))
    expect(await screen.findByTestId('report-ad-detail')).toBeInTheDocument()

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByTestId('report-ad-detail')).not.toBeInTheDocument())
    expect(screen.getByTestId('live-platform-drawer')).toBeInTheDocument()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByTestId('live-platform-drawer')).not.toBeInTheDocument()
  })

  it('offers nothing to open where the link switched the breakdown off', async () => {
    answer({ platform_drilldown: false, content_drilldown: true })
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { locale: 'en' })

    await screen.findByTestId('live-platform-comparison')
    expect(screen.queryByTestId('live-platform-open-meta')).not.toBeInTheDocument()
  })
})
