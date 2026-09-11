import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { TabAnalytics } from './TabAnalytics'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), getClientAnalytics: vi.fn() }))

import { getClientAnalytics } from './api'

/**
 * AGGREGATION-TRUTH-001 — a share of nothing is not a bar at zero.
 *
 * `byProvider()` divided by `array_sum(...) ?: 1`, so a window with no spend gave every platform a
 * share of exactly 0%. It sends null now — and this row drew `p.spend_share * 100` unguarded, which
 * put an empty fill beside the «—» the same row prints for the figure: one saying «contributed
 * nothing», the other «nothing to take a share of», about the same platform, on the same line.
 */
const analytics = (share: number | null) => ({
  currency_mode: 'single', currency: 'SAR', money_blended: false, objective_mix: [], roas_is_primary: false,
  totals: {}, previous: null, delta: null,
  platforms: [{ provider: 'meta', spend: 0, spend_share: share, impressions: 10, clicks: 1, conversions: 0, revenue: 0, roas: null, ctr: null, cpc: null, cpm: null, cpa: null }],
  projects: [], timeseries: [], best_campaign: null, worst_campaign: null,
  freshness: { state: 'fresh', last_sync_at: null, missing_days: 0, sync_failed: false },
  attribution: { windows: [] },
})

describe('the platform contribution bar', () => {
  it('draws no measured bar when there is no spend to take a share of', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics(null) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />)

    expect(await screen.findByTestId('spend-share-unmeasured')).toBeInTheDocument()
  })

  it('draws the bar when the share is real', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics(0.75) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />)

    expect(await screen.findByText('meta')).toBeInTheDocument()
    expect(screen.queryByTestId('spend-share-unmeasured')).toBeNull()
  })
})
