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
 * 500 USD the platform reported and no rate could convert: the spend is 500 USD, not a SAR figure,
 * and nothing may be paced against the plan from it.
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

  it('KPIs: shows the withheld spend in its own currency, and refuses to pace remaining/utilisation', async () => {
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />)

    // The spend the platform actually reported — 500 USD — never the converted subset (1,000) or a zero.
    expect(await screen.findByText('500.00 USD')).toBeInTheDocument()

    // Utilisation cannot be drawn from a withheld spend, so its «استهلاك …%» caption is absent entirely
    // rather than «استهلاك 125%» (800 vs the 1,000 subset) or «استهلاك 0%» (the coalesced zero).
    expect(screen.queryByText(/استهلاك/)).not.toBeInTheDocument()

    // «المتبقي» falls to «—», not 800 − 1,000 and not the full 800.
    const remaining = screen.getByText('المتبقي').parentElement as HTMLElement
    expect(within(remaining).getByText('—')).toBeInTheDocument()
  })

  it('Budget tab: «المصروف» states the withheld figure and the forecast it would drive reads «—»', async () => {
    renderWithProviders(<CampaignBudgetTab campaign={campaign} projectId="p1" range={range} locale="ar" />)

    // «المصروف» is the withheld original in USD — not «0 SAR» and not the SAR-labelled subset.
    expect(await screen.findByText('500.00 USD')).toBeInTheDocument()

    // The forecast is a projection off spend; with no comparable spend it is «—», not a SAR number.
    const forecast = screen.getByText('توقع نهاية الحملة').parentElement as HTMLElement
    expect(within(forecast).getByText('—')).toBeInTheDocument()
  })
})
