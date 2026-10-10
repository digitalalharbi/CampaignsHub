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

  /*
   * CAMPAIGN-VIEWS-001 changed the second half of this: a paused record is «paused» whatever its
   * age, because the operator who paused it is the one asking where it went. What still ends is a
   * campaign whose record says so, or one that went quiet without ever being paused.
   */
  it('keeps a paused campaign paused whatever its age, and ends a quiet one that was never paused', () => {
    expect(campaignBand(row({ campaign_id: 'a', status: 'paused', last_active_on: '2026-09-01' }), END)).toBe('paused')
    expect(campaignBand(row({ campaign_id: 'b', status: 'paused', last_active_on: '2026-01-01' }), END)).toBe('paused')
    expect(campaignBand(row({ campaign_id: 'c', status: 'completed', last_active_on: '2026-01-01' }), END)).toBe('ended')
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
      scheduled: 0,
      drafts: 0,
      ended: 1,
    })
  })
})

/*
 * CAMPAIGN-VIEWS-001 — record states are their own bands.
 */
describe('record-state bands', () => {
  const end = '2026-10-10'
  const base = { campaign_id: 'c', spend: null, last_active_on: null, needs_attention: false }

  it('files a draft under drafts, never under ended', () => {
    expect(campaignBand({ ...base, status: 'draft' }, end)).toBe('drafts')
  })

  it('files a scheduled status, or a future start, under scheduled', () => {
    expect(campaignBand({ ...base, status: 'scheduled' }, end)).toBe('scheduled')
    expect(campaignBand({ ...base, status: 'active', starts_on: '2026-11-01' }, end)).toBe('scheduled')
    expect(campaignBand({ ...base, status: 'completed', starts_on: '2026-11-01' }, end)).toBe('ended')
  })

  it('keeps a long-paused campaign paused — it is the record\'s state, not a reading of the window', () => {
    expect(campaignBand({ ...base, status: 'paused', last_active_on: '2026-07-01' }, end)).toBe('paused')
  })

  it('files a record by its own state even when a flag was raised; the flag still outranks a running campaign', () => {
    expect(campaignBand({ ...base, status: 'draft', needs_attention: true }, end)).toBe('drafts')
    expect(campaignBand({ ...base, status: 'active', starts_on: '2026-11-01', needs_attention: true }, end)).toBe('scheduled')
    expect(campaignBand({ ...base, status: 'paused', needs_attention: true }, end)).toBe('paused')
    expect(campaignBand({ ...base, status: 'active', needs_attention: true }, end)).toBe('attention')
  })

  it('counts every band, including the two new ones at zero', () => {
    const counts = bandCounts([{ ...base, status: 'draft' }, { ...base, status: 'scheduled' }], end)
    expect(counts.drafts).toBe(1)
    expect(counts.scheduled).toBe(1)
    expect(counts.ended).toBe(0)
  })
})
