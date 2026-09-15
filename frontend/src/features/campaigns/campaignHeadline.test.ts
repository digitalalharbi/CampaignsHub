import { describe, expect, it } from 'vitest'

import { campaignEfficiency, campaignHeadline, campaignReturn } from './campaignHeadline'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the row carries the result the campaign was BOUGHT for.
 *
 * The card showed a budget and a count of linked platform campaigns: two numbers that say nothing
 * about whether the money is working. An operator deciding whether to keep paying for a campaign
 * needs what it spent and what it produced, and «what it produced» is a different metric for a
 * leads campaign than for a sales one.
 *
 * The metric is chosen by `layoutFor`, the same catalogue the dashboard and Analytics headline with,
 * and read by `readMetric`, the same reader — there is no second rule here about what a campaign's
 * result is. **No opaque health score**: the card states a figure and names it.
 */
const row = (o: Record<string, unknown> = {}) => ({
  campaign_id: 'c1',
  spend: 500,
  conversions: 12,
  leads: 4,
  purchases: 9,
  revenue: 3000,
  roas: 6,
  reported: { spend: true, conversions: true, leads: true, purchases: true, revenue: true },
  ...o,
})

describe('the result a campaign row leads with', () => {
  it('leads a sales campaign with what it sold, not with a generic conversion', () => {
    const h = campaignHeadline('sales', row(), false)

    expect(h?.key).toBe('purchases')
    expect(h?.reading.kind).toBe('value')
  })

  it('leads a leads campaign with its leads', () => {
    expect(campaignHeadline('leads', row(), false)?.key).toBe('leads')
  })

  it('leads an awareness campaign with reach rather than an order it never sought', () => {
    const h = campaignHeadline('awareness', row({ reach: 90000, reported: { reach: true } }), false)

    expect(h?.key).toBe('reach')
  })

  /*
   * The whole reason `reported` had to reach campaign grain.
   *
   * A leads campaign whose connector has never sent a lead sums to a coalesced 0. «العملاء
   * المحتملون 0» on the card an operator judges the campaign by is not a measurement — it is the
   * absence of one, and it reads as a campaign that failed.
   */
  it('says the platform never sent it rather than printing the coalesced zero', () => {
    const h = campaignHeadline('leads', row({ leads: 0, reported: { spend: true, leads: false } }), false)

    expect(h?.key).toBe('leads')
    expect(h?.reading.kind).toBe('not_provided')
  })

  /** A real zero is still a real zero — a campaign that ran and produced nothing says so. */
  it('prints a genuine zero when the platform did report it', () => {
    const h = campaignHeadline('leads', row({ leads: 0, reported: { spend: true, leads: true } }), false)

    expect(h?.reading.kind).toBe('value')
  })

  /*
   * With no `reported` map at all — an older payload, or a row that never had one — nothing is
   * claimed. Assuming every key was reported would resurrect exactly the zeros this prevents.
   */
  it('claims nothing when the row carries no reported map', () => {
    const h = campaignHeadline('leads', row({ leads: 0, reported: undefined }), false)

    expect(h?.reading.kind).toBe('not_provided')
  })

  it('has no headline for an objective it does not know', () => {
    expect(campaignHeadline(null, row(), false)?.key).toBe('spend')
  })
})

/**
 * The second figure: what that result COST.
 *
 * A result on its own does not decide anything — 40 orders is good or bad depending on what was paid
 * for them. The efficiency metric is the objective's own cost-per, and it is found rather than
 * mapped: the first metric in the objective's headline row that the catalogue marks `invertGood`,
 * which is exactly the property «lower is better» that makes a metric a cost.
 *
 * A second hand-written objective→cost map would be a fourth place the taxonomy lives, and the first
 * new objective would put it out of step with the other three.
 */
describe('what the campaign returned, where returning was the point', () => {
  /**
   * The objective's own ordering decides which return figure leads, and for sales that is revenue.
   *
   * `sales` names `['purchases', 'cpa', 'revenue', 'roas']`, so the money returned comes before the
   * ratio — which is the catalogue's considered answer and not this column's to overrule. My first
   * version of this test asserted `roas` because that was the figure I had in mind; the taxonomy had
   * already decided otherwise, and deferring to it is the whole reason the column reads from it.
   */
  it('gives a sales campaign the return its objective leads with', () => {
    expect(campaignReturn('sales', row({ revenue: 9000, reported: { revenue: true } }), false)?.key).toBe('revenue')
  })

  /**
   * An awareness campaign gets NOTHING here, and that is the distinction the column exists to keep.
   *
   * «Not applicable to this objective» is not «unavailable». A «—» claims the platform had a figure
   * and could not give it; null renders no cell at all, which is the true statement about a campaign
   * that was never bought to return anything. The objective's own primary list decides — nothing
   * here knows what awareness means.
   */
  it('gives an awareness campaign no return column at all', () => {
    expect(campaignReturn('awareness', row({ roas: 3.2 }), false)).toBeNull()
  })

  /** A traffic campaign is the same case, and must not be handed a sales verdict by accident. */
  it('gives a traffic campaign no return column either', () => {
    expect(campaignReturn('traffic', row({ roas: 9 }), false)).toBeNull()
  })

  /**
   * A sales campaign whose platform never reported revenue says so rather than showing a zero.
   *
   * The absence has to be stated in the `reported` map, not by the value: `byCampaign()` sums with
   * COALESCE, so a revenue nobody sent arrives as 0 and is indistinguishable from a real zero by
   * looking at it. My first version passed `revenue: 0` against the fixture's default map, which
   * says revenue WAS reported — so it asserted a reported zero and was right to fail.
   */
  it('says a sales return was never reported instead of printing zero', () => {
    const r = campaignReturn('sales', row({ revenue: 0, reported: { spend: true } }), false)

    expect(r?.reading.kind).not.toBe('value')
  })
})

describe('what the result cost', () => {
  it('pairs a sales campaign with its cost per order', () => {
    expect(campaignEfficiency('sales', row({ cpa: 41.6 }), false)?.key).toBe('cpa')
  })

  it('pairs a leads campaign with its cost per lead', () => {
    expect(campaignEfficiency('leads', row({ cpl: 125 }), false)?.key).toBe('cpl')
  })

  /* Awareness money buys attention, and the price of attention is what a thousand views cost. */
  it('pairs an awareness campaign with its cost per thousand, not with frequency', () => {
    const e = campaignEfficiency('awareness', row({ cpm: 12, frequency: 1.4, reported: { impressions: true } }), false)

    expect(e?.key).toBe('cpm')
  })

  it('pairs a traffic campaign with its cost per click', () => {
    expect(campaignEfficiency('traffic', row({ cpc: 3.2 }), false)?.key).toBe('cpc')
  })

  it('pairs an app campaign with its cost per install', () => {
    expect(campaignEfficiency('app_installs', row({ cpi: 8 }), false)?.key).toBe('cpi')
  })

  /*
   * A cost with nothing to divide is not «0». A campaign that spent money and produced no orders has
   * no cost per order — the figure does not exist, and printing one would invent it.
   */
  it('states no cost when the campaign produced nothing to divide by', () => {
    const e = campaignEfficiency('sales', row({ cpa: null, conversions: 0, purchases: 0 }), false)

    expect(e?.reading.kind).not.toBe('value')
  })
})
