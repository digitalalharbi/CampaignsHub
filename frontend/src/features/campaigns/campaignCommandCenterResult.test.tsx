import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { CampaignBudgetTab, CampaignCreativesTab, CampaignExecutiveSummary, CampaignKpis } from './CampaignCommandCenter'
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

function route(current: Record<string, unknown>, sent: Record<string, boolean> = reported) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/summary')) return Promise.resolve({ current, delta: {}, reported: sent, currency: 'SAR' })
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

  it('a traffic campaign whose platform sends no landing page views reads clicks, named clicks', async () => {
    route({ ...COFFEE, landing_page_views: 0 }, { ...reported, landing_page_views: false })
    renderWithProviders(<CampaignKpis campaign={campaign('traffic')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('النقرات')
    expect(within(card('النقرات')).getByText(/18/)).toBeInTheDocument()
    expect(screen.getByText('تكلفة النقرة (CPC)')).toBeInTheDocument()
    expect(screen.queryByText('زيارات الصفحة')).not.toBeInTheDocument()
  })

  /** Owner decision: a platform that REPORTS landing page views is believed at 0 — the honest sign of a page measuring nothing. */
  it('a traffic campaign whose platform reports 0 visits shows «زيارات الصفحة 0», not its clicks', async () => {
    route({ ...COFFEE, landing_page_views: 0 })
    renderWithProviders(<CampaignKpis campaign={campaign('traffic')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('زيارات الصفحة')
    expect(within(card('زيارات الصفحة')).getByText('0')).toBeInTheDocument()
    expect(screen.queryByText('النقرات')).not.toBeInTheDocument()
  })

  /** The server's own result (it can see that Snapchat's 0 means «not tracked») is believed over the mirror. */
  it('believes the result the summary carries, with the reason it gives', async () => {
    route({ ...COFFEE, landing_page_views: 0, result_key: 'clicks', result: 18464, result_note: { ar: 'النقرات: زيارات الصفحة غير مقاسة على كل المنصات', en: 'x' } })
    renderWithProviders(<CampaignKpis campaign={campaign('traffic')} projectId="p1" range={range} />, { locale: 'ar' })

    await screen.findByText('النقرات')
    expect(card('النقرات').textContent).toContain('غير مقاسة على كل المنصات')
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

  /*
   * OBJECTIVE-RESULT-001 — one unit for every ad in the table. The header came from the first ad
   * alone, so an ad counted in clicks sat under «زيارات الصفحة».
   */
  it('counts every ad in one unit — clicks when the ads disagree — and says why', async () => {
    const ad = (id: string, metrics: Record<string, unknown>) => ({
      id, name: id, client_display_name: null, provider: 'snapchat', format: 'image', status: 'active', is_demo: false,
      preview: { state: 'unavailable', url: null, reason: null }, rank_metric: 'ctr', rank_value: null, classification: 'promising', ranking_reason: '',
      metrics: { spend: 100, impressions: 1000, clicks: 0, conversions: 0, revenue: 0, roas: null, cpa: null, ctr: null, cpm: null, view_rate: null, completion_rate: null, ...metrics },
    })
    vi.mocked(getData).mockImplementation((path: string) => path.includes('/creatives')
      ? Promise.resolve([
          ad('Visits ad', { clicks: 400, landing_page_views: 100, result_key: 'landing_page_views', result: 100 }),
          ad('Clicks ad', { clicks: 300, landing_page_views: 0, result_key: 'clicks', result: 300 }),
        ])
      : Promise.resolve([]))
    renderWithProviders(<CampaignCreativesTab campaign={campaign('traffic')} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })

    await screen.findByText('Visits ad')
    fireEvent.click(screen.getByRole('button', { name: 'جدول' }))
    const header = screen.getAllByRole('columnheader').map((h) => h.textContent)
    expect(header).toContain('النقرات')
    expect(header).not.toContain('زيارات الصفحة')
    expect(screen.getByText('400')).toBeInTheDocument()
    expect(screen.getByText('300')).toBeInTheDocument()
    expect(screen.getByTestId('ads-result-note').textContent).toContain('غير مقاسة على كل المنصات')
  })

  /** The best platform is judged in ONE unit across the platforms, through the money contract. */
  it('ranks platforms on the cost of one shared unit', async () => {
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/summary')) return Promise.resolve({ current: COFFEE, delta: {}, reported, currency: 'SAR' })
      if (path.includes('/platforms')) {
        return Promise.resolve([
          // Cheapest per VISIT, dearest per CLICK — ranking each on its own unit would name it best.
          { provider: 'snapchat', spend: 900, clicks: 300, landing_page_views: 900, impressions: 1, spend_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 },
          { provider: 'meta', spend: 300, clicks: 600, landing_page_views: 0, impressions: 1, spend_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 },
        ])
      }
      return Promise.resolve([])
    })
    renderWithProviders(<CampaignExecutiveSummary campaign={campaign('traffic')} projectId="p1" range={range} locale="ar" />, { locale: 'ar' })

    // Meta is cheapest per CLICK; Snapchat would win only if its visits were compared with Meta's clicks.
    expect(await screen.findByText(/^ميتا \(تكلفة النقرة \(CPC\) 0\.50/)).toBeInTheDocument()
    const best = screen.getByText(/^ميتا \(تكلفة النقرة/)
    expect(best.textContent).not.toMatch(/سناب|Snapchat/)
  })
})
