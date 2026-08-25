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
  /**
   * CAMP-UNLINKED-001 — the link and the data are two different facts.
   *
   * This test used to pass `spend: 100, conversions: 5` and assert «unmeasurable», which is what
   * the rule said and the opposite of what those figures mean. It pinned the claim in place: a
   * campaign showing 176 results and a 15.36× return was labelled unmeasurable in the same view
   * that measured it.
   */
  it('reports a campaign with no link AND no figures as unmeasurable', () => {
    const flags = attentionFlags(campaign({ external_campaigns_count: 0 }), { spend: 0, conversions: 0 })

    expect(flags.map((f) => f.code)).toContain('unlinked')
    expect(flags.find((f) => f.code === 'unlinked')?.severity).toBe('high')
  })

  it('does not call a campaign unmeasurable while its figures are on the screen', () => {
    const flags = attentionFlags(campaign({ external_campaigns_count: 0 }), { spend: 100, conversions: 5 })
    const codes = flags.map((f) => f.code)

    expect(codes).not.toContain('unlinked')
    expect(codes).toContain('unlinked_but_measured')

    // A bookkeeping gap, not a data outage — so it must not shout like one.
    expect(flags.find((f) => f.code === 'unlinked_but_measured')?.severity).toBe('medium')
    expect(flags.find((f) => f.code === 'unlinked_but_measured')?.ar).not.toContain('لا يمكن قياس')
  })

  it('counts any reported metric as evidence the figures arrive, not spend alone', () => {
    // An awareness campaign with impressions and no spend recorded is still being measured.
    const codes = attentionFlags(campaign({ external_campaigns_count: 0 }), { impressions: 5000 }).map((f) => f.code)

    expect(codes).toContain('unlinked_but_measured')
  })

  it('says nothing about linking when a campaign IS linked', () => {
    const codes = attentionFlags(campaign({ external_campaigns_count: 2 }), { spend: 100, conversions: 5 }).map((f) => f.code)

    expect(codes).not.toContain('unlinked')
    expect(codes).not.toContain('unlinked_but_measured')
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
    // Reporting currency (SAR) equals the campaign budget currency, so the converted spend is comparable.
    const flag = attentionFlags(campaign({ total_budget: 1000 }), { spend: 1250, conversions: 9 }, 'SAR').find((f) => f.code === 'over_budget')
    expect(flag?.ar).toContain('125%')
  })

  it('does NOT flag overspend when the reporting currency differs from the budget currency', () => {
    // 5,000 USD reported (converted) against a 1,000 SAR budget — not a comparison anyone can make.
    const codes = attentionFlags(campaign({ total_budget: 1000, budget_currency: 'SAR' }), { spend: 5000, conversions: 9 }, 'USD').map((f) => f.code)
    expect(codes).not.toContain('over_budget')
  })

  it('says data is missing instead of pretending a campaign is healthy', () => {
    expect(attentionFlags(campaign(), undefined).map((f) => f.code)).toContain('no_metrics')
  })

  it('leaves a healthy, linked, on-budget campaign with no flags', () => {
    expect(attentionFlags(campaign(), { spend: 400, conversions: 25 })).toEqual([])
  })

  it('ranks high-severity problems above a pile of minor ones', () => {
    /*
      A campaign spending with nothing to show for it — high severity on its own merits.

      This used to use an unlinked campaign carrying spend and conversions, which CAMP-UNLINKED-001
      re-graded to medium: the figures arrive, only the mapping is missing. The ranking rule being
      tested here is unchanged; the fixture just has to be a real high-severity case.
    */
    const high = attentionFlags(campaign({ external_campaigns_count: 3 }), { spend: 500, conversions: 0 })
    const minor = attentionFlags(campaign({ status: 'paused' }), { spend: 5, conversions: 1 })
    expect(attentionRank(high)).toBeGreaterThan(attentionRank(minor))
  })
})

describe('PARTIAL-WITHHELD-001 — flags read money by provenance, not the coalesced 0', () => {
  // Money the platform spent but no rate could convert: `spend` coalesces to 0, provenance says otherwise.
  const withheld = { spend: 0, spend_withheld_rows: 4, spend_original: 500, money_original_currency: 'USD', money_original_currencies: 1, conversions: 0 }
  // Some converted (1,000) beside some withheld (500 USD) — no single figure.
  const partial = { spend: 1000, spend_withheld_rows: 4, spend_original: 500, money_original_currency: 'USD', money_original_currencies: 1, conversions: 5 }

  it('a withheld spend is NOT «active but spent nothing»', () => {
    const codes = attentionFlags(campaign({ status: 'active' }), withheld).map((f) => f.code)
    expect(codes).not.toContain('active_no_spend')
    // It DID spend and produced no results, so that flag (a presence question) still fires.
    expect(codes).toContain('spend_no_results')
  })

  it('a genuine measured zero still flags «active but spent nothing»', () => {
    const codes = attentionFlags(campaign({ status: 'active' }), { spend: 0, spend_withheld_rows: 0, conversions: 0 }).map((f) => f.code)
    expect(codes).toContain('active_no_spend')
  })

  it('a withheld spend on a paused campaign flags «paused with spend»', () => {
    const codes = attentionFlags(campaign({ status: 'paused' }), withheld).map((f) => f.code)
    expect(codes).toContain('paused_with_spend')
  })

  it('over-budget never fires on a partial scope — the converted subset is not the spend', () => {
    // 1,000 converted is below the 1,000 budget only by coincidence; the real spend is more, but it
    // is not a single comparable figure, so no over-budget verdict may be issued.
    const codes = attentionFlags(campaign({ total_budget: 900, budget_currency: 'SAR' }), partial).map((f) => f.code)
    expect(codes).not.toContain('over_budget')
  })

  it('over-budget fires when a withheld spend in the budget currency exceeds it', () => {
    const codes = attentionFlags(
      campaign({ total_budget: 400, budget_currency: 'USD' }),
      { spend: 0, spend_withheld_rows: 4, spend_original: 500, money_original_currency: 'USD', money_original_currencies: 1, conversions: 3 },
    ).map((f) => f.code)
    expect(codes).toContain('over_budget')
  })
})
