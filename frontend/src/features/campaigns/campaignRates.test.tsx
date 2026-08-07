import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignKpis, CampaignFunnelTab } from './CampaignCommandCenter'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { UnifiedCampaign } from './types'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * PERCENT-100X-001 — a rate is printed once, not squared.
 *
 * Every rate here was `percent(x * 100)` while `percent()` already multiplies by 100, so the command
 * centre published each one a hundredfold. Found by driving a real campaign: CTR read **210.0%**,
 * معدل التحويل **479.6%**, استهلاك **3028%** and the funnel's first step **210%** — impossible
 * statements (more clicks than impressions, a budget spent thirty times over) on the page an agency
 * reads before it talks to its client.
 *
 * The fixture uses figures whose correct and incorrect renderings cannot be confused: 2.1% either
 * reads as «2.1%» or as «210.0%», and there is no rounding that turns one into the other.
 */

const RANGE = { from: '2026-07-07', to: '2026-08-05' }

const CAMPAIGN = {
  id: 'c1', name: 'National Day Sale', objective: 'sales', status: 'active',
  total_budget: 120_000, budget_currency: 'SAR',
} as unknown as UnifiedCampaign

/** impressions 1,282,024 · clicks 26,918 → CTR 2.1% · conversions 1,291 → conv rate 4.8% */
const SUMMARY = {
  current: {
    impressions: 1_282_024, clicks: 26_918, conversions: 1_291, spend: 36_000, revenue: 439_000,
    roas: 12.08, cpa: 28, ctr: 0.021, cpc: 1.34, cpm: 28,
  },
  previous: {},
  delta: {},
}

const FUNNEL = [
  { stage: 'impressions', label: 'Impressions', reported: true, count: 1_282_024, from_stage: null, step_rate: null, drop_off: null, cost_per: 0.03 },
  { stage: 'clicks', label: 'Clicks', reported: true, count: 26_918, from_stage: 'impressions', step_rate: 0.021, drop_off: 0.979, cost_per: 1.34 },
]

beforeEach(() => {
  signInWith(['campaigns.view'])
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/funnel')) return Promise.resolve(FUNNEL)
    if (path.includes('/summary')) return Promise.resolve(SUMMARY)
    return Promise.resolve([])
  })
})
afterEach(() => {
  signOut()
  vi.clearAllMocks()
})

describe('the campaign command centre prints a rate once', () => {
  it('shows CTR and the conversion rate as themselves, not a hundred times over', async () => {
    renderWithProviders(<CampaignKpis campaign={CAMPAIGN} projectId="p1" range={RANGE} />, { locale: 'ar' })

    // 26,918 clicks on 1,282,024 impressions is 2.1%. It read 210.0%.
    expect(await screen.findByText('2.1%')).toBeInTheDocument()
    expect(screen.queryByText('210.0%')).not.toBeInTheDocument()

    // 1,291 results on 26,918 clicks is 4.8%. It read 479.6%.
    expect(screen.getByText('4.8%')).toBeInTheDocument()
    expect(screen.queryByText('479.6%')).not.toBeInTheDocument()

    // 36,000 of a 120,000 budget is 30%. It read 3028%.
    expect(screen.getByText(/استهلاك 30%/)).toBeInTheDocument()
  })

  it('shows a funnel step rate as itself', async () => {
    renderWithProviders(<CampaignFunnelTab campaign={CAMPAIGN} projectId="p1" range={RANGE} />, { locale: 'ar' })

    expect(await screen.findByText(/تحويل 2%/)).toBeInTheDocument()
    expect(screen.queryByText(/تحويل 210%/)).not.toBeInTheDocument()
  })

  /**
   * No rate the command centre prints may exceed 100% — every one of them is a share of a step above
   * it, and a share cannot be larger than the whole. This catches the class rather than the four
   * call sites that happened to be found, so a new `percent(x * 100)` cannot slip back in unseen.
   */
  it('prints no share above 100% anywhere on the KPI strip', async () => {
    const { container } = renderWithProviders(
      <CampaignKpis campaign={CAMPAIGN} projectId="p1" range={RANGE} />, { locale: 'ar' },
    )
    await screen.findByText('2.1%')

    const impossible = (container.textContent ?? '').match(/\d{3,}(\.\d+)?%/g) ?? []
    expect(impossible, 'a share of a step cannot be larger than the step').toEqual([])
  })
})
