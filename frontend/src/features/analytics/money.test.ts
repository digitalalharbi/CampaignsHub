import { describe, expect, it } from 'vitest'
import { spendIsWithheld, spendOf } from './money'

/**
 * AGGREGATION-TRUTH-001 — money that drives a DECISION.
 *
 * The owner reports impressions surviving on a surface while Spend disappears from it. The cause is
 * not a lost metric: `MetricsAggregator` carries `spend_original` and `spend_withheld_rows` beside
 * every converted figure, and says in its own comments that production's rows are «entirely
 * withheld and entirely USD». A reader that consults the converted column alone therefore reports
 * zero for money that is entirely real.
 *
 * These pin the rule for the case the three existing readers never covered — a threshold, a filter
 * or a ranking — where a wrong zero does not merely print badly, it removes the row from a list.
 */
describe('what a row honestly spent', () => {
  it('takes the converted figure when there is one', () => {
    expect(spendOf({ spend: 1200, spend_original: 0, spend_withheld_rows: 0 })).toBe(1200)
  })

  it('falls back to the amount the sync held rather than reporting zero', () => {
    // The production shape: nothing converted, the real amount held in its own currency.
    expect(spendOf({ spend: 0, spend_original: 5000, spend_withheld_rows: 3 })).toBe(5000)
  })

  it('does not treat a sum of nothing as a held amount', () => {
    // An original with no withheld rows behind it makes no claim — zero is what summing nothing gives.
    expect(spendOf({ spend: 0, spend_original: 4200, spend_withheld_rows: 0 })).toBe(0)
    expect(spendOf({ spend: 0, spend_original: 0, spend_withheld_rows: 2 })).toBe(0)
  })

  it('reports a campaign that genuinely spent nothing as nothing', () => {
    expect(spendOf({ spend: 0, spend_original: 0, spend_withheld_rows: 0 })).toBe(0)
    expect(spendIsWithheld({ spend: 0, spend_original: 0, spend_withheld_rows: 0 })).toBe(false)
  })

  /**
   * The distinction a decision needs: «spent nothing» is a fact about the campaign, «could not be
   * converted» is a fact about our exchange rates, and treating the second as the first is what
   * emptied the needs-attention list.
   */
  it('separates money that is absent from money that could not be converted', () => {
    expect(spendIsWithheld({ spend: 0, spend_original: 5000, spend_withheld_rows: 3 })).toBe(true)
    expect(spendIsWithheld({ spend: 900, spend_original: 5000, spend_withheld_rows: 3 })).toBe(false)
  })

  it('survives a row that carries no provenance at all', () => {
    expect(spendOf({ spend: 750 })).toBe(750)
    expect(spendOf({})).toBe(0)
  })
})
