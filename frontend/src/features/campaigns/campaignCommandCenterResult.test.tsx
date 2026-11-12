import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { CampaignBudgetTab, CampaignKpis } from './CampaignCommandCenter'
import type { UnifiedCampaign } from './types'
import type { Range } from '@/features/analytics/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * OBJECTIVE-RESULT-001 — the campaign page leads with the result its OBJECTIVE is judged on.
 *
 * Production, last 30 days: 1KCOFFEE, Snapchat TRAFFIC — 2,881 landing page views, 18,464 clicks,
 * no conversions. The page read `conversions`: «النتائج 0», a cost per «CPA» of nothing, revenue and
 * ROAS cards for a campaign never bought to sell, and «لا نتائج في الفترة» as its budget risk.
 */
const COFFEE = {
  impressions: 900_000, clicks: 18_464, conversions: 0, landing_page_views: 2_881,
  spend: 1440.5, revenue: 0, roas: null, cpa: null, cpc: 0.078, cpm: 1.6, ctr: 0.0205,
  spend_withheld_rows: 0, revenue_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0,
}
const SALES = {
  impressions: 50_000, clicks: 900, conversions: 12, landing_page_views: 600,
  spend: 300, revenue: 2400, roas: 8, cpa: 25, cpc: 0.33, cpm: 6, ctr: 0.018,
  spend_withheld_rows: 0, revenue_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0,
}
const reported = { impressions: true, clicks: true, conversions: true, landing_page_views: true, spend: true, revenue: true }

const range: Range = { from: '2026-10-01', to: '2026-10-30' }

const campaign = (objective: string): UnifiedCampaign => ({
  id: 'c1', project_id: 'p1', name: '1KCOFFEE', objective, status: 'active',
  total_budget: 5000, budget_currency: 'SAR', starts_on: '2026-10-01', ends_on: '2026-10-31',
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 1,
  created_at: null,
})

function route(current: Record<string, unknown>) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/summary')) return Promise.resolve({ current, delta: {}, reported, currency: 'SAR' })
    return Promise.resolve([])
  })
}

const card = (label: string) => screen.getByText(label).closest('div.rounded-2xl') as HTMLElement

describe('the campaign page result follows the objective', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('a traffic campaign shows its visits and the cost of one, with no revenue or ROAS', async () => {
    route(COFFEE)
    renderWithProviders(<CampaignKpis campaign={campaign('traffic')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('زيارات الصفحة')
    expect(within(card('زيارات الصفحة')).getByText('2,881')).toBeInTheDocument()
    expect(card('تكلفة الزيارة').textContent).toMatch(/0\.5/)

    expect(screen.queryByText('النتائج')).not.toBeInTheDocument()
    expect(screen.queryByText('الإيرادات')).not.toBeInTheDocument()
    expect(screen.queryByText('ROAS')).not.toBeInTheDocument()
  })

  it('a traffic campaign with no landing page views reads clicks, named clicks', async () => {
    route({ ...COFFEE, landing_page_views: 0 })
    renderWithProviders(<CampaignKpis campaign={campaign('traffic')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('النقرات')
    expect(within(card('النقرات')).getByText(/18/)).toBeInTheDocument()
    expect(screen.getByText('تكلفة النقرة (CPC)')).toBeInTheDocument()
    expect(screen.queryByText('زيارات الصفحة')).not.toBeInTheDocument()
  })

  it('a sales campaign keeps orders, cost per order, revenue and ROAS', async () => {
    route(SALES)
    renderWithProviders(<CampaignKpis campaign={campaign('sales')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('الطلبات')
    expect(within(card('الطلبات')).getByText('12')).toBeInTheDocument()
    expect(card('تكلفة الطلب (CPA)').textContent).toMatch(/25/)
    expect(screen.getByText('الإيرادات')).toBeInTheDocument()
    expect(screen.getByText('ROAS')).toBeInTheDocument()
  })

  it('an unclassified campaign says its objective is not set instead of «0»', async () => {
    route(SALES)
    renderWithProviders(<CampaignKpis campaign={campaign('other')} projectId="p1" range={range} />, { locale: 'ar' })

    expect(await screen.findByText('الهدف غير محدد')).toBeInTheDocument()
    expect(screen.queryByText('الإيرادات')).not.toBeInTheDocument()
  })

  it('the budget risk never tells a traffic campaign with visits that it had no results', async () => {
    route(COFFEE)
    renderWithProviders(<CampaignBudgetTab campaign={campaign('traffic')} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })

    await screen.findByText('خطر الميزانية')
    expect(screen.queryByText(/لا نتائج/)).not.toBeInTheDocument()
  })
})
