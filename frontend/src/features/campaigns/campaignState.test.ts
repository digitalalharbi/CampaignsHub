import { describe, expect, it } from 'vitest'

import { campaignState } from './campaignState'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «no arbitrary opaque health score», and the two states a row must keep
 * apart: examined-and-fine, and not examined at all. A row that shows the same thing for both is the
 * score this requirement forbids, wearing a word instead of a number.
 */
const REPORTED = { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true }

describe('the concise state on a campaign row', () => {
  it('reports the earliest weakness for a campaign that has one', () => {
    const s = campaignState('sales', {
      spend: 1000, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0, reported: REPORTED,
    })

    expect(s.finding?.code).toBe('not_delivering')
    expect(s.judged).toBe(true)
  })

  it('judges a healthy campaign without inventing a weakness', () => {
    const s = campaignState('sales', {
      spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000, reported: REPORTED,
    })

    expect(s.finding).toBeNull()
    expect(s.judged).toBe(true)
  })

  /** A campaign with no metrics row is not a healthy campaign. */
  it('does not call an unmeasured campaign judged', () => {
    const s = campaignState('sales', undefined)

    expect(s.finding).toBeNull()
    expect(s.judged).toBe(false)
  })

  /**
   * The coalesced-zero trap, per campaign. Every figure is 0 because nothing was ever reported, and
   * a row that printed «not delivering» here would be blaming a campaign for its connector.
   */
  it('does not diagnose a campaign whose connector reported nothing', () => {
    const s = campaignState('sales', {
      spend: 0, impressions: 0, clicks: 0, conversions: 0, reported: {},
    })

    expect(s.finding).toBeNull()
    expect(s.judged).toBe(false)
  })
})
