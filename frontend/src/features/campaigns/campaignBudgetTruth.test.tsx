import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignBudgetTab, CampaignExecutiveSummary, CampaignKpis } from './CampaignCommandCenter'
import type { UnifiedCampaign } from './types'
import type { Range } from '@/features/analytics/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * CAMPAIGN-BUDGET-TRUTH-001 — a budget comparison is made only from a comparable spend figure, and
 * what it finds is said as it is.
 *
 * Observed on the preview: a campaign that had spent 17,448.75 SAR of a 16,666.67 SAR budget, every
 * platform reported through the same day, printed «المتبقي —» and «أكبر خطر: ضمن الحدود». The
 * summary's currency was in the envelope's meta and not in its body, so the comparison was refused;
 * and the refusal fell through to the all-clear.
 */
const PROVIDERS = ['google', 'meta']
const truncated = {
  state: 'partial', expected_contributors: PROVIDERS, included_contributors: [], excluded_contributors: PROVIDERS,
  partial_contributors: PROVIDERS, reported_through: { google: '2026-09-27', meta: '2026-09-27' },
  inactive_contributors: [], stale_contributors: [], failed_contributors: [], withheld_contributors: [], unsupported_contributors: [], reasons: {},
}
const missing = {
  state: 'partial', expected_contributors: PROVIDERS, included_contributors: ['google'], excluded_contributors: ['meta'],
  partial_contributors: [], reported_through: {},
  inactive_contributors: [], stale_contributors: [], failed_contributors: ['meta'], withheld_contributors: [], unsupported_contributors: [], reasons: {},
}

const spent = (spend: number, coverage: object) => ({
  impressions: 60_810, clicks: 5_170, conversions: 151,
  spend, revenue: 237_825, roas: 13.63, cpa: 115.55, cpc: 3.375, cpm: 286.94, ctr: 0.085,
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
  coverage, spend_coverage: coverage, revenue_coverage: coverage,
})

const range: Range = { from: '2026-09-11', to: '2026-10-10' }

const campaign: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'Google Search — Brand', objective: 'sales', status: 'active',
  total_budget: 16666.667, budget_currency: 'SAR', starts_on: null, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 1,
  created_at: null,
}

function serve(summary: object) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/summary')) return Promise.resolve(summary)
    return Promise.resolve([]) // performance, platforms, funnel, activity
  })
}

describe('a campaign that has spent past its budget', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    serve({ current: spent(17448.75, truncated), previous: spent(25299, truncated), delta: {}, currency: 'SAR' })
  })
  afterEach(() => signOut())

  it('KPIs: «المتبقي» states the overrun, with the utilisation beside it', async () => {
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'ar' })
    const card = (await screen.findByText('المتبقي')).closest('div.rounded-2xl') as HTMLElement
    expect(card).toHaveTextContent('−782 SAR')
    expect(card).toHaveTextContent('تجاوزت الميزانية')
    expect(card).toHaveTextContent('105%')
  })

  it('the executive summary names the overrun as the biggest risk', async () => {
    renderWithProviders(<CampaignExecutiveSummary campaign={campaign} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })
    expect(await screen.findByText('تجاوزت الميزانية')).toBeInTheDocument()
    expect(screen.queryByText('ضمن الحدود')).not.toBeInTheDocument()
  })

  it('the budget tab says the same thing in its own words', async () => {
    renderWithProviders(<CampaignBudgetTab campaign={campaign} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })
    const risk = (await screen.findByText('خطر الميزانية')).closest('div.rounded-xl') as HTMLElement
    expect(risk).toHaveTextContent('تجاوزت الميزانية')
    const remaining = screen.getByText('المتبقي').closest('div.rounded-xl') as HTMLElement
    expect(remaining).toHaveTextContent('−782 SAR')
  })
})

describe('a budget comparison nobody can make', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('a window missing a platform has no spend to subtract, and the all-clear is not implied', async () => {
    serve({ current: spent(10_000, missing), previous: spent(10_000, missing), delta: {}, currency: 'SAR' })
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'ar' })
    const card = (await screen.findByText('المتبقي')).closest('div.rounded-2xl') as HTMLElement
    expect(card).toHaveTextContent('—')
    expect(card).toHaveTextContent('بلا رقم مصروف يُقارن بالميزانية')

    renderWithProviders(<CampaignExecutiveSummary campaign={campaign} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })
    expect(await screen.findByText(/غير متاح/)).toBeInTheDocument()
    expect(screen.queryByText('ضمن الحدود')).not.toBeInTheDocument()
  })

  it('a summary that names no currency is refused the comparison, as before', async () => {
    serve({ current: spent(17448.75, truncated), previous: spent(25299, truncated), delta: {} })
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />, { locale: 'ar' })
    const card = (await screen.findByText('المتبقي')).closest('div.rounded-2xl') as HTMLElement
    expect(card).toHaveTextContent('—')
    expect(card).not.toHaveTextContent('SAR')
  })
})
