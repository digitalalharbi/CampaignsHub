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
 * AGGREGATION-TRUTH-001 — the command centre defeated its own formatters with `?? 0`.
 *
 * `percent()` and `num()` return «—» for a figure the aggregator did not state. Every call here
 * passed `x ?? 0` first, so a CTR that was never reported printed «0.0%» and results that were never
 * reported printed «0» — on the screen an operator uses to decide whether to pause a campaign. A
 * campaign that has not delivered an impression yet is not a campaign nobody clicks.
 *
 * The inconsistency was visible inside a single row: spend, CPA, ROAS and «المساهمة» all refuse
 * correctly, and the two cells between them claimed zero.
 */
const NOT_REPORTED = {
  impressions: null, clicks: null, conversions: null,
  spend: null, revenue: null, roas: null, cpa: null, cpc: null, cpm: null, ctr: null,
}

const range: Range = { from: '2026-08-01', to: '2026-08-26' }

const campaign: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'Just Launched', objective: 'sales', status: 'active',
  total_budget: 800, budget_currency: 'SAR', starts_on: '2026-08-01', ends_on: '2026-08-31',
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 0,
  created_at: null,
}

describe('a campaign that has reported nothing yet', () => {
  beforeEach(async () => {
    await signInWith(['campaigns.view', 'analytics.view'])
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/summary')) return Promise.resolve({ current: NOT_REPORTED, delta: {} })
      return Promise.resolve([])
    })
  })

  afterEach(() => {
    signOut()
    vi.clearAllMocks()
  })

  it('does not claim it delivered zero impressions', async () => {
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />)

    // CAMPAIGN-PAGE-LEAN-001 — impressions nobody reported are not drawn at all; drawn, they must never read 0.
    await screen.findByTestId('campaign-kpis-secondary')
    const label = screen.queryByText('مرات الظهور')
    if (label !== null) expect(label.closest('div')?.parentElement?.textContent).not.toMatch(/\b0\b/)
  })

  it('does not claim a click-through rate of zero', async () => {
    renderWithProviders(<CampaignKpis campaign={campaign} projectId="p1" range={range} />)

    // CAMPAIGN-PAGE-LEAN-001 — a rate nobody reported is not drawn at all; drawn, it must never read 0.0%.
    await screen.findByTestId('campaign-kpis-secondary')
    const ctr = screen.queryByText('CTR')
    if (ctr !== null) expect(ctr.closest('div')?.parentElement?.textContent).not.toMatch(/0\.0\s*%/)
    expect(screen.getByTestId('campaign-kpis-secondary').textContent).not.toMatch(/0\.0\s*%/)
  })
})
