import { describe, expect, it } from 'vitest'
import { bandCounts, byPriority, campaignBand, type PriorityRow } from './campaignPriority'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — «a campaign the user can act on now must rank above historical noise».
 *
 * The page sorted by relevance and then by spend, which put a finished campaign that outspent every
 * running one near the top and left one overspending this morning below it. The owner's order is
 * five bands, not one column: attention, spending, weak, paused, ended.
 */
const END = '2026-09-11'

const row = (over: Partial<PriorityRow> & { campaign_id: string }): PriorityRow => ({
  status: 'active',
  last_active_on: END,
  spend: 0,
  ...over,
})

describe('the band a campaign is read in', () => {
  it('puts a campaign that needs attention first, however little it spent', () => {
    expect(campaignBand(row({ campaign_id: 'a', needs_attention: true, spend: 1 }), END)).toBe('attention')
  })

  /* Attention outranks EVERYTHING, including a campaign that is also spending well. */
  it('bands an attention campaign as attention even while it is serving', () => {
    expect(campaignBand(row({ campaign_id: 'a', needs_attention: true, spend: 90_000, efficient: true }), END)).toBe('attention')
  })

  it('separates a serving campaign that is earning from one that is not', () => {
    expect(campaignBand(row({ campaign_id: 'a', spend: 100, efficient: true }), END)).toBe('spending')
    expect(campaignBand(row({ campaign_id: 'b', spend: 100, efficient: false }), END)).toBe('weak')
  })

  /**
   * An UNJUDGED campaign is not a weak one.
   *
   * `efficient: null` means the server had nothing comparable to judge it on. Banding that as weak
   * would be the coalesced-zero mistake in another costume — a verdict invented out of an absence.
   */
  it('does not call an unjudged campaign weak', () => {
    expect(campaignBand(row({ campaign_id: 'a', spend: 100, efficient: null }), END)).toBe('spending')
    expect(campaignBand(row({ campaign_id: 'b', spend: 100 }), END)).toBe('spending')
  })

  it('keeps a recently paused campaign above one that ended long ago', () => {
    expect(campaignBand(row({ campaign_id: 'a', status: 'paused', last_active_on: '2026-09-01' }), END)).toBe('paused')
    expect(campaignBand(row({ campaign_id: 'b', status: 'paused', last_active_on: '2026-01-01' }), END)).toBe('ended')
  })

  it('treats a campaign that never ran as history rather than as something to restart', () => {
    expect(campaignBand(row({ campaign_id: 'a', status: 'archived', last_active_on: null }), END)).toBe('ended')
  })
})

describe('the portfolio in reading order', () => {
  /** The defect, as data: history that outspent everything, beside a live problem. */
  const portfolio: PriorityRow[] = [
    row({ campaign_id: 'history', name: 'Finished big spender', status: 'completed', last_active_on: '2026-02-01', spend: 900_000 }),
    row({ campaign_id: 'live', name: 'Overspending today', needs_attention: true, spend: 300 }),
    row({ campaign_id: 'ok', name: 'Running fine', spend: 5_000, efficient: true }),
    row({ campaign_id: 'poor', name: 'Running badly', spend: 4_000, efficient: false }),
    row({ campaign_id: 'rested', name: 'Paused last week', status: 'paused', last_active_on: '2026-09-04' }),
  ]

  it('reads attention, then spending, then weak, then paused, then ended', () => {
    expect(byPriority(portfolio, END).map((c) => c.campaign_id)).toEqual([
      'live',
      'ok',
      'poor',
      'rested',
      'history',
    ])
  })

  /** Spend decides inside a band — the money already committed is what makes one more urgent. */
  it('puts the larger spend first within a band', () => {
    const two = [
      row({ campaign_id: 'small', name: 'A', needs_attention: true, spend: 10 }),
      row({ campaign_id: 'large', name: 'B', needs_attention: true, spend: 10_000 }),
    ]

    expect(byPriority(two, END).map((c) => c.campaign_id)).toEqual(['large', 'small'])
  })

  /**
   * And the order is STABLE — a list that reshuffles on refresh cannot be scanned.
   *
   * Two campaigns identical in band and spend fall back to their name rather than to whichever order
   * the array happened to arrive in.
   */
  it('breaks a full tie by name rather than by arrival order', () => {
    const tied = [
      row({ campaign_id: 'z', name: 'Zulu', spend: 100 }),
      row({ campaign_id: 'a', name: 'Alpha', spend: 100 }),
    ]

    expect(byPriority(tied, END).map((c) => c.name)).toEqual(['Alpha', 'Zulu'])
    expect(byPriority([...tied].reverse(), END).map((c) => c.name)).toEqual(['Alpha', 'Zulu'])
  })

  it('counts the portfolio by band for the classification strip', () => {
    expect(bandCounts(portfolio, END)).toEqual({
      attention: 1,
      spending: 1,
      weak: 1,
      paused: 1,
      ended: 1,
    })
  })
})
