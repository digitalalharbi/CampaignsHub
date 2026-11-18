import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useOverviewVm } from './overview/useOverviewVm'
import type { CampaignRow } from '@/features/analytics/api'

/**
 * AGGREGATION-TRUTH-001 — the needs-attention list on an account whose money is withheld.
 *
 * FX-001 withholds a conversion when no rate exists instead of inventing one, and
 * `MetricsAggregator` says production's rows are «entirely withheld and entirely USD». The list
 * filtered on the converted column, so on exactly those accounts every campaign read as zero spend
 * and the list was permanently empty — including the campaigns that had burned money for nothing,
 * which is what it exists to surface. The alert list is built from the same rows, so both went
 * quiet together.
 *
 * The threshold is asked through `moneyState` rather than a number, because a withheld figure is in
 * the platform's own currency: comparing it to a riyal threshold would trade one defect for a
 * currency error. Money we hold and cannot state qualifies on its own — that IS the case worth a
 * look.
 */
const row = (over: Partial<CampaignRow>): CampaignRow => ({
  campaign_id: 'c1',
  campaign_name: 'حملة',
  provider: 'snapchat',
  // A sales campaign: its result is its orders, so 0 conversions is a measured zero result.
  objective: 'sales',
  spend: 0,
  impressions: 100_000,
  clicks: 400,
  conversions: 0,
  revenue: 0,
  spend_original: 0,
  spend_withheld_rows: 0,
  money_original_currency: null,
  money_original_currencies: 0,
  ...over,
} as unknown as CampaignRow)

const vm = (campaigns: CampaignRow[]) =>
  renderHook(() =>
    useOverviewVm({
      campaigns,
      platforms: [],
      freshness: [],
      budget: [],
      currency: 'SAR',
      source: 'live',
      ar: true,
    }),
  ).result.current

describe('a campaign that spent real money the sync could not convert', () => {
  it('still reaches the needs-attention list', () => {
    // The production shape: nothing converted, the real amount held in one platform currency.
    const withheld = row({
      spend: 0, spend_original: 9000, spend_withheld_rows: 4, conversions: 0,
      money_original_currency: 'USD', money_original_currencies: 1,
    })

    expect(vm([withheld]).needsAttention.length).toBe(1)
  })

  it('reaches the alert list too, since both are built from the same rows', () => {
    const withheld = row({
      spend: 0, spend_original: 9000, spend_withheld_rows: 4, conversions: 0,
      money_original_currency: 'USD', money_original_currencies: 1,
    })

    expect(vm([withheld]).alerts.length).toBeGreaterThan(0)
  })

  /**
   * And the opposite must stay true: a campaign that genuinely spent nothing is not a campaign
   * wasting money, and must not be promoted into the list by this change.
   */
  it('does not promote a campaign that truly spent nothing', () => {
    const quiet = row({ spend: 0, spend_original: 0, spend_withheld_rows: 0, conversions: 0 })

    expect(vm([quiet]).needsAttention.length).toBe(0)
  })

  it('still catches an ordinary converted overspend', () => {
    const converted = row({ spend: 7000, conversions: 0 })

    expect(vm([converted]).needsAttention.length).toBe(1)
  })
})

/*
 * OBJECTIVE-RESULT-001 — «صرف بدون نتائج» only when the campaign's OWN result is a measured zero.
 * The list read `conversions`, so every traffic and awareness campaign that spent was flagged.
 */
describe('the needs-attention list judges each campaign by its objective', () => {
  const spent = { spend: 5000, spend_withheld_rows: 0, conversions: 0 }

  it('does not flag a traffic campaign that brought visits, or an awareness one that was seen', () => {
    const traffic = row({ ...spent, objective: 'traffic', landing_page_views: 2881, clicks: 18464 } as Partial<CampaignRow>)
    const awareness = row({ ...spent, objective: 'awareness', impressions: 900_000 } as Partial<CampaignRow>)

    expect(vm([traffic, awareness]).needsAttention).toEqual([])
    expect(vm([traffic, awareness]).alerts).toEqual([])
  })

  it('does not flag a campaign whose objective is not set — there is no result to be zero', () => {
    expect(vm([row({ ...spent, objective: 'other' } as Partial<CampaignRow>)]).needsAttention).toEqual([])
  })

  it('flags a sales campaign at zero orders and names the zero', () => {
    const v = vm([row({ ...spent, objective: 'sales' } as Partial<CampaignRow>)])

    expect(v.needsAttention).toHaveLength(1)
    expect(v.needsAttention[0].reason).toBe('صرف بدون نتائج (الطلبات 0)')
  })

  it('lists the best campaigns with their own result and unit, and ROAS for sales only', () => {
    const traffic = row({ ...spent, objective: 'traffic', landing_page_views: 2881, clicks: 18464, roas: 0 } as Partial<CampaignRow>)
    const top = vm([traffic]).topCampaigns[0]

    expect(top.results).toBe(2881)
    expect(top.resultLabel?.ar).toBe('زيارات الصفحة')
    expect(top.roas).toBeNull()
  })
})
