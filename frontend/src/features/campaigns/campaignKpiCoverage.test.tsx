import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignKpis } from './CampaignCommandCenter'
import type { UnifiedCampaign } from './types'
import type { Range } from '@/features/analytics/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))
import { getData } from '@/lib/api/client'

/**
 * CAMPAIGN-KPI-COVERAGE-001 — the KPI block states the window its figures cover.
 *
 * The summary carried `coverage` on both periods and the block read neither: a platform that had
 * reported through the 27th printed thirty-day KPIs with a delta against the previous period, and
 * nothing said the delta compared seventeen days with thirty.
 */
const BASE = {
  impressions: 683_984, clicks: 14_362, conversions: 688, spend: 19_388.7, revenue: 233_920,
  roas: 12.065, cpa: 28.18, cpc: 1.35, cpm: 28.35, ctr: 0.021,
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}
const COMPLETE = { state: 'complete', expected_contributors: ['meta'], included_contributors: ['meta'], excluded_contributors: [], partial_contributors: [], reported_through: {}, reasons: {} }
const TRUNCATED = {
  state: 'partial', expected_contributors: ['meta'], included_contributors: [], excluded_contributors: ['meta'],
  partial_contributors: ['meta'], reported_through: { meta: '2026-09-27' },
  reasons: { meta: 'Reported through 2026-09-27; this window ends 2026-10-10.' },
}
const STALE = { ...TRUNCATED, partial_contributors: [], stale_contributors: ['meta'], reported_through: { meta: '2026-09-20' } }

const range: Range = { from: '2026-09-11', to: '2026-10-10' }
const campaign: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'National Day', objective: 'sales', status: 'active',
  total_budget: 120_000, budget_currency: 'SAR', starts_on: null, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 1,
  created_at: null,
}

function routeSummary(current: Record<string, unknown>, previous: Record<string, unknown>) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/summary')) {
      return Promise.resolve({ current, previous, delta: { spend: 0.0688, conversions: 0.0667, roas: -0.0019 }, currency: 'SAR' })
    }
    return Promise.resolve([])
  })
}

beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
afterEach(() => signOut())

describe('CampaignKpis and the window its figures cover', () => {
  it('says nothing about coverage, and shows the delta, when both windows are complete', async () => {
    routeSummary({ ...BASE, coverage: COMPLETE }, { ...BASE, coverage: COMPLETE })
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'ar' })
    expect(await screen.findByText('688')).toBeInTheDocument()
    expect(screen.queryByTestId('campaign-kpi-coverage')).not.toBeInTheDocument()
    // The spend delta, +6.9 %, is on screen as a percentage.
    expect(screen.getAllByText(/6\.9%|7%/).length).toBeGreaterThan(0)
  })

  it('names the date the figures run through, withholds the delta, and keeps the ratios, when the platform stopped short', async () => {
    routeSummary({ ...BASE, coverage: TRUNCATED }, { ...BASE, coverage: COMPLETE })
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'ar' })
    const note = await screen.findByTestId('campaign-kpi-coverage')
    expect(note.textContent).toContain('حتى 2026-09-27 فقط')
    expect(note.textContent).toContain('ميتا')
    expect(note.textContent).toContain('المقارنة بالفترة السابقة محجوبة')
    expect(screen.queryByText(/6\.9%|7%/)).not.toBeInTheDocument()
    // Ratios over a consistently truncated window are the ratios for the covered days.
    expect(screen.getByText('12.07×')).toBeInTheDocument()
  })

  it('refuses every ratio when a contributor is missing outright, and says so in English too', async () => {
    routeSummary({ ...BASE, coverage: STALE }, { ...BASE, coverage: COMPLETE })
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'en' })
    const note = await screen.findByTestId('campaign-kpi-coverage')
    expect(note.textContent).toContain('is not synced through the end of this period')
    expect(screen.queryByText('12.07×')).not.toBeInTheDocument()
    // Sums stay: 688 results were counted, and that count is true for the days it covers.
    expect(screen.getByText('688')).toBeInTheDocument()
  })
})
