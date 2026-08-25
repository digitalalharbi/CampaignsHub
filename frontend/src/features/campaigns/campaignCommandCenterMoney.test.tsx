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
 * PARTIAL-WITHHELD-001 — the Command Center paced a budget off the aggregator's coalesced spend.
 *
 * The spend KPI already read the contract, but «المتبقي», «استهلاك» and the whole budget tab were
 * computed from `k.spend` — which is 1,000 (the converted SUBSET) here, or 0 on a fully-withheld
 * scope, and was rendered under the BUDGET's currency. The fixture below is 1,000 converted plus
 * 500 USD the platform reported and no rate could convert.
 *
 * That scope is `partial`, and a partial scope has NO single spend figure: 1,000 SAR is not the
 * spend (it omits the 500) and 500 USD is not the spend either (it omits the 1,000), and the two
 * cannot be added without a rate. A total fails closed — «—» — and nothing may be paced from it.
 * Stating either half would be the same defect wearing the other half's clothes.
 */
const PARTIAL_WITHHELD = {
  impressions: 50_000, clicks: 800, conversions: 40,
  spend: 1000, revenue: 0, roas: 0, cpa: 0, cpc: 0, cpm: 0, ctr: 0.02,
  spend_original: 500, spend_withheld_rows: 3,
  revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: 'USD', money_original_currencies: 1,
}

const range: Range = { from: '2026-08-01', to: '2026-08-26' }

const campaign: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'Always-On', objective: 'sales', status: 'active',
  total_budget: 800, budget_currency: 'SAR', starts_on: '2026-08-01', ends_on: '2026-08-31',
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 2,
  created_at: null,
}

function routeSummary() {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/summary')) {
      return Promise.resolve({ current: PARTIAL_WITHHELD, delta: {} })
    }
    return Promise.resolve([]) // performance, platforms, funnel, activity
  })
}

describe('the campaign command centre over a partially-withheld spend', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    routeSummary()
  })
  afterEach(() => signOut())

  it('KPIs: refuses a single spend figure for a partial scope, and paces nothing from it', async () => {
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />)

    // Neither half may be printed as the spend: not the converted subset in SAR, not the withheld
    // original in USD, and certainly not the coalesced zero.
    const spend = (await screen.findByText('المصروف')).parentElement as HTMLElement
    expect(within(spend).getByText('—')).toBeInTheDocument()
    expect(screen.queryByText('500.00 USD')).not.toBeInTheDocument()
    expect(screen.queryByText(/1,000/)).not.toBeInTheDocument()

    // Utilisation cannot be drawn from a withheld spend, so its «استهلاك …%» caption is absent entirely
    // rather than «استهلاك 125%» (800 vs the 1,000 subset) or «استهلاك 0%» (the coalesced zero).
    expect(screen.queryByText(/استهلاك/)).not.toBeInTheDocument()

    // «المتبقي» falls to «—», not 800 − 1,000 and not the full 800.
    const remaining = screen.getByText('المتبقي').parentElement as HTMLElement
    expect(within(remaining).getByText('—')).toBeInTheDocument()
  })

  it('Budget tab: «المصروف» refuses a partial figure, and the forecast it would drive reads «—»', async () => {
    renderWithProviders(<CampaignBudgetTab campaign={campaign} projectId="p1" range={range} locale="ar" />)

    // «المصروف» is «—» — not «0 SAR», not the SAR-labelled subset, not the USD half on its own.
    const spent = (await screen.findByText('المصروف')).parentElement as HTMLElement
    expect(within(spent).getByText('—')).toBeInTheDocument()
    expect(screen.queryByText('500.00 USD')).not.toBeInTheDocument()

    // The forecast is a projection off spend; with no comparable spend it is «—», not a SAR number.
    const forecast = screen.getByText('توقع نهاية الحملة').parentElement as HTMLElement
    expect(within(forecast).getByText('—')).toBeInTheDocument()
  })
})
