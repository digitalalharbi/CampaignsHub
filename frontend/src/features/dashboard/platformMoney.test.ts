import { describe, expect, it } from 'vitest'
import { displaySpend, withheldCurrencyOf } from './platformMoney'

/**
 * DASH-PLATFORM-MONEY-001 — the figures below are what `integrations:diagnose` read off production
 * on 2026-08-25, not a plausible-looking fixture:
 *
 *   spend                  : 0            ← FX-001 withheld it; no USD→SAR rate exists
 *   spend_withheld_rows    : 163
 *   spend_original         : 4768.84
 *   money_original_currency: USD
 *
 * The KPI card rendered «4,768.84 USD» from exactly this. The platform comparison beneath it
 * rendered «0 SAR», the donut came out empty, and the spend/revenue chart drew a flat line on zero.
 */
const PRODUCTION = {
  spend: 0,
  spend_withheld_rows: 163,
  spend_original: 4768.84,
  money_original_currency: 'USD',
}

describe('what a platform row may show as its spend', () => {
  it('shows the withheld original rather than the coalesced zero', () => {
    expect(displaySpend(PRODUCTION)).toBeCloseTo(4768.84, 2)
  })

  it('prefers a converted figure when one exists', () => {
    expect(displaySpend({ spend: 3667.5, spend_withheld_rows: 0, spend_original: 0 })).toBeCloseTo(3667.5, 2)
  })

  /**
   * A summed original with no withheld rows behind it makes no claim: zero is what a sum of nothing
   * produces, and `*_withheld_rows` is the sync saying it HELD a real amount and refused to convert.
   */
  it('does not treat an original with no withheld rows as a figure', () => {
    expect(displaySpend({ spend: 0, spend_withheld_rows: 0, spend_original: 500 })).toBe(0)
  })

  it('does not invent a figure from a zero original', () => {
    expect(displaySpend({ spend: 0, spend_withheld_rows: 12, spend_original: 0 })).toBe(0)
  })
})

describe('the currency the comparison is denominated in', () => {
  it('is the original currency when every withheld row agrees', () => {
    expect(withheldCurrencyOf([PRODUCTION, { spend_withheld_rows: 4, money_original_currency: 'USD' }])).toBe('USD')
  })

  /** Two unconvertible currencies cannot be added, and a label over their sum would fit neither. */
  it('is refused when the originals disagree', () => {
    expect(withheldCurrencyOf([
      { spend_withheld_rows: 5, money_original_currency: 'USD' },
      { spend_withheld_rows: 5, money_original_currency: 'AED' },
    ])).toBeNull()
  })

  it('is refused when nothing was withheld at all', () => {
    expect(withheldCurrencyOf([{ spend_withheld_rows: 0, money_original_currency: 'USD' }])).toBeNull()
  })
})
