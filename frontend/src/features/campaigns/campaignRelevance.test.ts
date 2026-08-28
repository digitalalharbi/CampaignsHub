import { describe, expect, it } from 'vitest'

import { CAMPAIGN_RELEVANCE_ORDER, campaignRelevance, orderByRelevance, type RelevanceRow } from './campaignRelevance'

/**
 * ENTITY-RELEVANCE-ORDERING-001 — the operational ordering, in one place.
 *
 * The backend hands every campaign row `status` and `last_active_on` and orders them spend-first,
 * because that same breakdown feeds reports and the digest where «top campaigns» means largest
 * spend. An operator is asking a different question — what is running, and what needs looking at —
 * and this is the one answer to it. A second copy on the next surface would drift, and the drift
 * would be silent: two screens disagreeing about which campaigns are live.
 */
const row = (o: Partial<RelevanceRow>): RelevanceRow => ({
  campaign_id: 'c',
  status: 'active',
  last_active_on: '2026-07-30',
  spend: 0,
  ...o,
})

const WINDOW_END = '2026-07-31'

describe('what a campaign is, operationally', () => {
  it('calls a campaign that is running and reported recently «serving»', () => {
    expect(campaignRelevance(row({ status: 'active', last_active_on: '2026-07-30' }), WINDOW_END)).toBe('serving')
  })

  /*
   * Reporting lags. A campaign whose last figure is from two days ago is running, not idle, and
   * calling it idle would send an operator to fix a campaign that is working.
   */
  it('allows for the lag between a campaign spending and the platform reporting it', () => {
    expect(campaignRelevance(row({ last_active_on: '2026-07-29' }), WINDOW_END)).toBe('serving')
    expect(campaignRelevance(row({ last_active_on: '2026-07-20' }), WINDOW_END)).toBe('idle')
  })

  /*
   * «Active but dark» is the state worth surfacing: the platform says it is on and it has spent
   * nothing. It is neither serving nor finished, and folding it into either hides the one campaign
   * on the page somebody should look at.
   */
  it('keeps a campaign that is switched on but has gone dark in its own state', () => {
    expect(campaignRelevance(row({ status: 'active', last_active_on: null }), WINDOW_END)).toBe('idle')
  })

  it('calls anything not running «stopped», whatever it spent', () => {
    for (const status of ['paused', 'completed', 'archived', 'draft', 'pending']) {
      expect(campaignRelevance(row({ status, last_active_on: '2026-07-31', spend: 90000 }), WINDOW_END)).toBe('stopped')
    }
  })

  /*
   * An unknown status is not a claim that the campaign stopped. It is a campaign whose state the
   * platform did not tell us, and its activity is the only evidence there is.
   */
  it('reads an unknown status from the activity rather than assuming it stopped', () => {
    expect(campaignRelevance(row({ status: 'unknown', last_active_on: '2026-07-31' }), WINDOW_END)).toBe('serving')
    expect(campaignRelevance(row({ status: null, last_active_on: null }), WINDOW_END)).toBe('idle')
  })
})

describe('the order an operator reads them in', () => {
  it('puts what is running above what needs looking at, and both above what has finished', () => {
    expect(CAMPAIGN_RELEVANCE_ORDER).toEqual(['serving', 'idle', 'stopped'])
  })

  /*
   * The defect this exists to prevent, stated as a test: a finished campaign that outspent every
   * running one led the operational list, so the first thing an operator saw was a campaign they
   * could do nothing about.
   */
  it('never lets a big historical spender outrank a campaign that is serving', () => {
    const ordered = orderByRelevance(
      [
        row({ campaign_id: 'finished', status: 'completed', last_active_on: '2026-07-05', spend: 90000 }),
        row({ campaign_id: 'running', status: 'active', last_active_on: '2026-07-31', spend: 10 }),
      ],
      WINDOW_END,
    )

    expect(ordered.map((r) => r.campaign_id)).toEqual(['running', 'finished'])
  })

  it('orders within a state by spend, and breaks a tie on a key that cannot move', () => {
    const ordered = orderByRelevance(
      [
        row({ campaign_id: 'c-9', spend: 500 }),
        row({ campaign_id: 'c-2', spend: 900 }),
        row({ campaign_id: 'c-4', spend: 500 }),
      ],
      WINDOW_END,
    )

    expect(ordered.map((r) => r.campaign_id)).toEqual(['c-2', 'c-4', 'c-9'])
  })

  /** Inactive is never hidden — it sorts last, and it is still on the page. */
  it('keeps every campaign it was given', () => {
    const rows = [row({ campaign_id: 'a', status: 'archived' }), row({ campaign_id: 'b' })]

    expect(orderByRelevance(rows, WINDOW_END)).toHaveLength(2)
  })
})
