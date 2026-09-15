import { describe, expect, it } from 'vitest'
import { bestByRoas, worstByRoas } from './campaignRoasPanels'

/**
 * MONEY-TRUTH — «best» and «worst» by ROAS must be chosen by ROAS, and only among rows that have one.
 *
 * Two defects sat side by side on the campaigns tab.
 *
 * `best` was `rows[0]`. The aggregator returns campaign rows SPEND-ordered — `orderCampaignRows()`
 * is `orderBySpendThen()` — so a panel titled «أفضل حملة (ROAS)» named the biggest spender and
 * printed its ROAS beside it, which reads as a claim the product was not making.
 *
 * `worst` sorted on `(a.roas ?? 0) - (b.roas ?? 0)`. A campaign whose platform never reported ROAS
 * arrives with it absent, and coalescing that to 0 puts it at the bottom of the ranking — so «تحتاج
 * مراجعة (أدنى ROAS)» could name a campaign nobody has a return figure for, over one that genuinely
 * returned little. That is the unreported-as-zero error the money contract exists to prevent,
 * committed in a ranking rather than in a cell, where it is harder to see and changes a decision.
 */
/*
 * `reported` carries the SUMMED columns — `roas` is derived and is never in it. A fixture that put
 * `roas` there would describe a payload the API does not send, and the first version of this suite
 * did exactly that, which is why it passed while the page went blank.
 */
const row = (over: Record<string, unknown>) => ({ campaign_id: 'c', spend: 100, revenue: 200, roas: 2, reported: { spend: true, revenue: true }, ...over }) as never

describe('the ROAS panels on the campaigns tab', () => {
  it('picks the best by ROAS, not the row that happened to be first', () => {
    const rows = [
      row({ campaign_id: 'big-spender', spend: 9000, roas: 1.2 }),
      row({ campaign_id: 'efficient', spend: 100, roas: 8.4 }),
    ]

    expect((bestByRoas(rows) as { campaign_id: string } | null)?.campaign_id).toBe('efficient')
  })

  /** A campaign with no reported ROAS is not the worst performer — it is an unanswered question. */
  it('never names a campaign whose ROAS was never reported as the worst', () => {
    const rows = [
      row({ campaign_id: 'genuinely-poor', spend: 500, roas: 0.4 }),
      row({ campaign_id: 'never-reported', spend: 500, revenue: null, roas: null, reported: { spend: true, revenue: false } }),
    ]

    expect((worstByRoas(rows) as { campaign_id: string } | null)?.campaign_id).toBe('genuinely-poor')
  })

  /**
   * A derived figure is reportable when its INPUTS are, and ROAS's input is revenue.
   *
   * Gating on `reported.roas` excludes everything, because the map lists summed columns only. That
   * mistake emptied both panels on an estate with genuine 12× returns.
   */
  it('reads the reported revenue, not a roas key the map never carries', () => {
    const rows = [row({ campaign_id: 'has-revenue', spend: 500, revenue: 4000, roas: 8 })]

    expect((bestByRoas(rows) as { campaign_id: string } | null)?.campaign_id).toBe('has-revenue')
  })

  /** A REPORTED zero is a real answer and may be the worst, which is the other half of the rule. */
  it('does name a reported zero as the worst', () => {
    const rows = [
      row({ campaign_id: 'returned-nothing', spend: 500, roas: 0 }),
      row({ campaign_id: 'returned-little', spend: 500, roas: 0.4 }),
    ]

    expect((worstByRoas(rows) as { campaign_id: string } | null)?.campaign_id).toBe('returned-nothing')
  })

  /** With nothing reported at all, both panels say nothing rather than inventing a winner. */
  it('names nobody when no campaign has a reported ROAS', () => {
    const rows = [row({ revenue: null, roas: null, reported: { spend: true, revenue: false } })]

    expect(bestByRoas(rows)).toBeNull()
    expect(worstByRoas(rows)).toBeNull()
  })

  /** Spend is still required for «worst»: a campaign that spent nothing cannot have performed badly. */
  it('ignores a campaign that spent nothing', () => {
    const rows = [
      row({ campaign_id: 'no-spend', spend: 0, roas: 0 }),
      row({ campaign_id: 'spent', spend: 500, roas: 0.4 }),
    ]

    expect((worstByRoas(rows) as { campaign_id: string } | null)?.campaign_id).toBe('spent')
  })
})
