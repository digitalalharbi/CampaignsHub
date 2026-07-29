import { describe, expect, it } from 'vitest'
import { attentionFlags, attentionRank, hasMixedResults, resultModel } from './campaignInsights'
import type { UnifiedCampaign } from './types'

const campaign = (over: Partial<UnifiedCampaign> = {}): UnifiedCampaign => ({
  id: 'c1', name: 'C', objective: 'sales', status: 'active',
  external_campaigns_count: 1, total_budget: 1000, budget_currency: 'SAR',
  ...over,
} as UnifiedCampaign)

describe('objective-aware results', () => {
  it('counts a different metric per objective and never reuses CPA outside conversion objectives', () => {
    expect(resultModel('sales')).toMatchObject({ metric: 'conversions', costKey: 'cpa' })
    expect(resultModel('awareness')).toMatchObject({ metric: 'reach', costKey: 'cpm' })
    expect(resultModel('leads')).toMatchObject({ metric: 'leads', costKey: 'cpl' })
    expect(resultModel('traffic')).toMatchObject({ metric: 'clicks', costKey: 'cpc' })
    // "other" has no agreed result definition — better null than a misleading number.
    expect(resultModel('other')).toBeNull()
    expect(resultModel(undefined)).toBeNull()
  })

  it('flags a set of campaigns as mixed when their result definitions differ', () => {
    expect(hasMixedResults(['sales', 'conversions'])).toBe(false)   // same definition, different label
    expect(hasMixedResults(['sales', 'awareness'])).toBe(true)
    expect(hasMixedResults(['awareness'])).toBe(false)
  })
})

describe('needs-attention rules', () => {
  it('reports a campaign with no linked platform campaign as unmeasurable', () => {
    const flags = attentionFlags(campaign({ external_campaigns_count: 0 }), { spend: 100, conversions: 5 })
    expect(flags.map((f) => f.code)).toContain('unlinked')
    expect(flags.find((f) => f.code === 'unlinked')?.severity).toBe('high')
  })

  it('flags spend with zero results using the objective’s own result metric', () => {
    // Sales campaign with conversions = 0 → flagged.
    expect(attentionFlags(campaign(), { spend: 500, conversions: 0 }).map((f) => f.code)).toContain('spend_no_results')
    // The SAME numbers on an awareness campaign are not a failure: reach is what counts there.
    const awareness = attentionFlags(campaign({ objective: 'awareness' }), { spend: 500, conversions: 0, reach: 90_000 })
    expect(awareness.map((f) => f.code)).not.toContain('spend_no_results')
  })

  it('flags an active campaign that spent nothing, and a paused campaign that still spent', () => {
    expect(attentionFlags(campaign(), { spend: 0 }).map((f) => f.code)).toContain('active_no_spend')
    expect(attentionFlags(campaign({ status: 'paused' }), { spend: 20, conversions: 2 }).map((f) => f.code)).toContain('paused_with_spend')
  })

  it('flags overspend against the planned budget and reports the percentage', () => {
    const flag = attentionFlags(campaign({ total_budget: 1000 }), { spend: 1250, conversions: 9 }).find((f) => f.code === 'over_budget')
    expect(flag?.ar).toContain('125%')
  })

  it('says data is missing instead of pretending a campaign is healthy', () => {
    expect(attentionFlags(campaign(), undefined).map((f) => f.code)).toContain('no_metrics')
  })

  it('leaves a healthy, linked, on-budget campaign with no flags', () => {
    expect(attentionFlags(campaign(), { spend: 400, conversions: 25 })).toEqual([])
  })

  it('ranks high-severity problems above a pile of minor ones', () => {
    const high = attentionFlags(campaign({ external_campaigns_count: 0 }), { spend: 100, conversions: 4 })
    const minor = attentionFlags(campaign({ status: 'paused' }), { spend: 5, conversions: 1 })
    expect(attentionRank(high)).toBeGreaterThan(attentionRank(minor))
  })
})
