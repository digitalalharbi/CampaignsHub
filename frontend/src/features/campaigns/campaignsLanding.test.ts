import { describe, expect, it } from 'vitest'

import type { BudgetRow } from '@/features/analytics/api'

import { landingAnswer } from './campaignsLanding'
import { attentionFlags } from './campaignInsights'
import type { UnifiedCampaign } from './types'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the landing answers, and the counts that must not absorb each other.
 *
 * The requirement forbids an «arbitrary opaque health score». Three confident numbers above a list
 * are that score split into three, so what matters here is which rows each count REFUSES:
 * unexamined campaigns are not healthy ones, and budgets nobody could pace are not budgets that are
 * pacing fine.
 */
const REPORTED = { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true }

const broken = { reported: REPORTED, spend: 1000, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0 }
const fine = { reported: REPORTED, spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000 }
const silent = { reported: {}, spend: 0, impressions: 0, clicks: 0 }

const budget = (over: Partial<BudgetRow>): BudgetRow => ({
  campaign_id: 'b', campaign_name: 'B', status: 'active', budget: 1000, budget_currency: 'SAR',
  spent: 500, spent_currency: 'SAR', spend_withheld: false, remaining: 500, consumed_pct: 0.5,
  pace: 1.0, projected_spend: 1000, pacing_basis: 'comparable', ...over,
})

describe('what the campaigns workspace answers before the list', () => {
  it('separates a weakness, a clean bill of health, and nothing to say', () => {
    const a = landingAnswer(
      [
        { id: 'broken', objective: 'sales' },
        { id: 'fine', objective: 'sales' },
        { id: 'silent', objective: 'sales' },
      ],
      new Map([['broken', broken], ['fine', fine], ['silent', silent]]),
      [],
      // The flags decide attention now; this spec is about the two buckets that are left.
      new Set(['broken']),
    )

    expect(a.needsAttention).toBe(1)
    expect(a.healthy).toBe(1)
    expect(a.unexamined).toBe(1)
  })

  /**
   * The collapse this forbids. A campaign whose connector reported nothing is not a healthy campaign,
   * and counting it as one publishes an absence of evidence as evidence of health — on the first
   * figure a reader sees.
   */
  it('never counts an unexamined campaign as healthy', () => {
    const a = landingAnswer(
      [{ id: 'silent', objective: 'sales' }],
      new Map([['silent', silent]]),
      [],
      new Set(),
    )

    expect(a.healthy).toBe(0)
    expect(a.unexamined).toBe(1)
  })

  it('counts budgets running hot, from the backend’s own pacing', () => {
    const a = landingAnswer([], new Map(), [
      budget({ campaign_id: '1', pace: 1.4 }),
      budget({ campaign_id: '2', pace: 0.9 }),
    ], new Set())

    expect(a.overpacing).toBe(1)
  })

  /**
   * «No budget is overspending» and «no budget could be measured» are different answers.
   *
   * `pacing_basis` names every reason a row could not be paced — a currency mismatch, no budget set,
   * a partial or mixed-currency spend. Counting those as «not overpacing» answers the question with a
   * confident no derived entirely from rows nobody could measure.
   */
  it('says nothing about pacing when no row could be paced', () => {
    const a = landingAnswer([], new Map(), [
      budget({ campaign_id: '1', pacing_basis: 'currency_mismatch', pace: null }),
      budget({ campaign_id: '2', pacing_basis: 'no_budget', pace: null }),
      budget({ campaign_id: '3', pacing_basis: 'mixed_currency', pace: null }),
    ], new Set())

    expect(a.overpacing).toBeNull()
  })

  /** An empty workspace answers zero for what it examined and null for what it could not measure. */
  it('answers an empty project without inventing anything', () => {
    const a = landingAnswer([], new Map(), undefined, new Set())

    expect(a).toEqual({ needsAttention: 0, healthy: 0, unexamined: 0, overpacing: null, opportunity: null, inefficient: null })
  })
})

/**
 * NEEDS-ATTENTION-ONE-DEFINITION-001 — one phrase, one engine.
 *
 * `/app/campaigns` said «needs attention» twice on one screen, from two different engines. The KPI
 * card, the band chip and the attention LIST came from `attentionFlags()` — operational evidence a
 * reader can open: unlinked, over budget, no results, stale. The landing strip came from
 * `landingAnswer()`, which asked `campaignState()` for a metrics weakness instead.
 *
 * Two numbers under the same words disagree the moment either engine changes, and a reader cannot
 * tell which of them is wrong. The flags win the phrase, because theirs is the count that can be
 * opened and read back as reasons.
 */
describe('the phrase «needs attention» has one owner', () => {
  const campaign = (over: Partial<UnifiedCampaign> = {}): UnifiedCampaign => ({
    id: 'c', project_id: 'p', name: 'C', objective: 'sales', status: 'active',
    total_budget: null, budget_currency: 'SAR', starts_on: null, ends_on: null,
    primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
    owner_id: null, target_kpi: null, audience: null, regions: null, created_at: null,
    external_campaigns_count: 1, ...over,
  })

  /**
   * The disagreement itself.
   *
   * This campaign is healthy by its metrics — spend, impressions, clicks, conversions, revenue all
   * reported and all fine — and is linked to no platform, which the flags call out. The old landing
   * strip called it healthy while the card beside it called it attention-needing.
   */
  it('counts a campaign the flags raised, even when its metrics read fine', () => {
    const c = campaign({ id: 'unlinked', external_campaigns_count: 0 })
    const flags = attentionFlags(c, fine, 'SAR')
    expect(flags.length).toBeGreaterThan(0)

    const a = landingAnswer(
      [{ id: 'unlinked', objective: 'sales' }],
      new Map([['unlinked', fine]]),
      [],
      new Set(['unlinked']),
    )

    expect(a.needsAttention).toBe(1)
    expect(a.healthy).toBe(0)
  })

  /** And the strip's number IS the card's number, for the same rows. */
  it('reports the same count the attention list would show', () => {
    const rows = [
      campaign({ id: 'unlinked', external_campaigns_count: 0 }),
      campaign({ id: 'fine' }),
    ]
    const metrics = new Map([['unlinked', fine], ['fine', fine]])
    const flagged = new Set(rows.filter((c) => attentionFlags(c, metrics.get(c.id), 'SAR').length > 0).map((c) => c.id))

    const a = landingAnswer(rows.map((c) => ({ id: c.id, objective: c.objective })), metrics, [], flagged)

    expect(a.needsAttention).toBe(flagged.size)
    expect(a.needsAttention + a.healthy + a.unexamined).toBe(rows.length)
  })

  /**
   * The guarantee that survives the change: an absence of evidence is still not evidence of health.
   * A campaign nothing could be said about is unexamined whether or not a flag was raised on it.
   */
  it('still never folds an unexamined campaign into healthy', () => {
    const a = landingAnswer(
      [{ id: 'silent', objective: 'sales' }],
      new Map([['silent', silent]]),
      [],
      new Set(),
    )

    expect(a.healthy).toBe(0)
    expect(a.unexamined).toBe(1)
  })
})

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «where is the opportunity, which budget is inefficient», against each objective's OWN peers and only from
 * results the platform reported. Every expected count is derived from the rows below, never typed as a literal.
 */
describe('opportunity and inefficient budget', () => {
  const leadRow = (spend: number, leads: number | null) => ({ reported: { spend: true, leads: leads !== null }, spend, leads: leads ?? 0, clicks: 100, impressions: 10000 })
  const rows = {
    cheap: leadRow(100, 20), // 5 per lead
    usual1: leadRow(100, 10), // 10
    usual2: leadRow(100, 9), // ~11.1
    dear: leadRow(300, 10), // 30
    zero: leadRow(200, 0), // spent, reported zero leads
    unreported: leadRow(200, null), // spent, leads never reported
  }
  const campaigns = Object.keys(rows).map((id) => ({ id, objective: 'leads' }))
  const metrics = new Map(Object.entries(rows) as Array<[string, Record<string, unknown>]>)
  const costs = Object.values(rows).filter((r) => r.reported.leads && r.leads > 0).map((r) => r.spend / r.leads).sort((a, b) => a - b)
  const median = costs.length % 2 === 0 ? (costs[costs.length / 2 - 1] + costs[costs.length / 2]) / 2 : costs[Math.floor(costs.length / 2)]

  it('counts a campaign clearly cheaper than its peers as the opportunity, and one at twice their cost or with a reported zero as inefficient', () => {
    const a = landingAnswer(campaigns, metrics, undefined, new Set())

    expect(a.opportunity).toBe(costs.filter((c) => c <= median * 0.75).length)
    const inefficient = costs.filter((c) => c >= median * 2).length + 1 // + the reported zero
    expect(a.inefficient).toBe(inefficient)
  })

  it('never reads an unreported result as a zero', () => {
    const only = landingAnswer([{ id: 'unreported', objective: 'leads' }], new Map([['unreported', rows.unreported]]), undefined, new Set())

    expect(only.inefficient).toBeNull()
  })

  it('compares nothing with fewer than three measured peers, and says so with null rather than zero', () => {
    const two = landingAnswer([{ id: 'cheap', objective: 'leads' }, { id: 'dear', objective: 'leads' }], new Map([['cheap', rows.cheap], ['dear', rows.dear]]), undefined, new Set())

    expect(two.opportunity).toBeNull()
  })

  it('never compares a lead\'s cost with an order\'s', () => {
    const mixed = [{ id: 'cheap', objective: 'leads' }, { id: 'usual1', objective: 'sales' }, { id: 'dear', objective: 'awareness' }]
    const a = landingAnswer(mixed, metrics, undefined, new Set())

    expect(a.opportunity).toBeNull()
  })
})
