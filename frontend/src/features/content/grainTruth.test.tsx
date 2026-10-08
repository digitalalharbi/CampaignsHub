import { describe, expect, it } from 'vitest'
import { canonicalFigureKeys } from './canonicalFigures'

/**
 * CREATIVE-GRAIN-TRUTH-001 — the surface never invents a result the platform did not attribute.
 *
 * The owner's defect: a campaign reporting 109 purchases above creatives every one of which read
 * «الطلبات 0» and «0.00x». The platform reports that conversion against the CAMPAIGN and returns a
 * flat zero on each creative — the breakdown was never made, so the zero means «not at this grain».
 *
 * The decision is made on the server, where the campaign's own figures are in hand: the result
 * metrics are dropped from `headline_metrics` rather than sent as zeros. What this file holds is the
 * frontend's half of the contract — that it does not put them back.
 *
 * `canonicalFigureKeys` is the place that could: it APPENDS universal figures and `revenue`/`roas`
 * to whatever the server listed, whenever the row's own metrics bag appears to hold them. A bag
 * carrying `revenue: 0` from an unattributed campaign would walk straight back onto the card
 * through that door.
 */
describe('an unattributed result does not re-enter through the frontend', () => {
  /*
    The server has already dropped `revenue` and `roas` from the list. The bag still carries the
    platform's zeros, because they are what it sent — the row is not rewritten, only its headline.
  */
  const unattributedBag = {
    spend: 975,
    spend_original: 975,
    impressions: 120_000,
    clicks: 1_400,
    revenue: 0,
    roas: 0,
    conversions: 0,
  }

  it('does not append revenue or return that the server deliberately withheld', () => {
    const keys = canonicalFigureKeys(['spend', 'impressions', 'clicks'], unattributedBag, 'USD', false, true)

    expect(keys, 'a zero from an unattributed campaign walked back onto the card').not.toContain('revenue')
    expect(keys).not.toContain('roas')
  })

  it('still appends them where the server did list them, which is the attributed case', () => {
    const keys = canonicalFigureKeys(['spend', 'revenue', 'roas'], { ...unattributedBag, revenue: 7_000, roas: 3.1 }, 'USD', false)

    expect(keys).toContain('revenue')
    expect(keys).toContain('roas')
  })

  /*
    The delivery figures are not in question and must not be collateral damage.

    Impressions and clicks are reported per creative by every platform that reports creatives at
    all. A fix that hid those as well would answer the owner's complaint by making the card emptier
    than the truth requires.
  */
  it('leaves the delivery figures alone', () => {
    const keys = canonicalFigureKeys(['spend', 'impressions', 'clicks'], unattributedBag, 'USD', false, true)

    expect(keys).toContain('impressions')
    expect(keys).toContain('clicks')
  })
})
