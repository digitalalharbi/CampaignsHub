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

    expect(a).toEqual({ needsAttention: 0, healthy: 0, unexamined: 0, overpacing: null })
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
