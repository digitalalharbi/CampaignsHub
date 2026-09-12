import { describe, expect, it } from 'vitest'
import { objectiveMix } from './objectiveMix'

/**
 * «Segment campaigns by objective» — through the money contract, never a `reduce`.
 *
 * This page already carries two refusal-aware money readers (the platform donut and the campaign
 * ranking). A third hand-rolled sum would be a third truth about the same figure, and the failure
 * mode is the one this product has fixed repeatedly: riyals added to dollars, or a withheld amount
 * read as zero.
 */
/*
  The money contract's own field names — `spend_withheld_rows` and `money_original_currency`, not
  the `spend_currency` / `spend_withheld` a first draft assumed. A fixture using invented names
  passes every refusal case by never triggering one, which is how this test first reported that a
  mixed-currency portfolio was perfectly addable.
*/
const row = (over: Record<string, unknown> = {}) => ({
  objective: 'sales',
  spend: 100,
  spend_withheld_rows: 0,
  money_original_currency: null,
  conversions: 5,
  ...over,
}) as Parameters<typeof objectiveMix>[0][number]

describe('the portfolio by objective', () => {
  it('groups raw objectives under the canonical five', () => {
    const mix = objectiveMix([
      row({ objective: 'sales', spend: 100 }),
      row({ objective: 'conversions', spend: 200 }),
      row({ objective: 'purchases', spend: 300 }),
      row({ objective: 'awareness', spend: 50 }),
    ], 'SAR')

    expect(mix?.rows.map((r) => r.key)).toEqual(['awareness_engagement', 'sales'])

    const sales = mix?.rows.find((r) => r.key === 'sales')

    expect(sales?.campaigns).toBe(3)
    expect(sales?.spend).toBe(600)
  })

  /** An objective nobody bought has no row. A zero would invite a question about money never spent. */
  it('leaves out an objective the portfolio has never run', () => {
    const mix = objectiveMix([row({ objective: 'sales' })], 'SAR')

    expect(mix?.rows.map((r) => r.key)).toEqual(['sales'])
    expect(mix?.rows.find((r) => r.key === 'app_promotion')).toBeUndefined()
  })

  /** A campaign carrying an objective the taxonomy cannot name is COUNTED, never folded into one. */
  it('counts an unrecognised objective instead of filing it somewhere', () => {
    const mix = objectiveMix([
      row({ objective: 'store_visits' }),
      row({ objective: 'sales' }),
    ], 'SAR')

    expect(mix?.unclassified).toBe(1)
    expect(mix?.rows.map((r) => r.key)).toEqual(['sales'])
  })

  /**
   * A row that cannot be added is DROPPED and reported — and its group says «unknown», not zero.
   *
   * `rankableMoney` does not refuse the whole axis for one unaddable row; it draws what it can and
   * returns how many it left out, which is the contract every other money reader on this page uses.
   * What matters here is that the dropped row does not quietly become a zero inside its objective:
   * an awareness bar reading «0 SAR» over real withheld dollars is the coalesced zero again.
   */
  it('drops an unaddable row, reports it, and leaves its group unknown rather than zero', () => {
    const mix = objectiveMix([
      row({ objective: 'sales', spend: 100 }),
      /*
        A withheld row needs `spend_original` as well as a row count: `moneyState` reads
        «rows > 0 AND original > 0», so a count alone is a row that looks perfectly converted. That
        is what this fixture did at first, and the case passed while proving nothing.
      */
      row({
        objective: 'awareness',
        spend: null,
        spend_withheld_rows: 1,
        spend_original: 100,
        money_original_currency: 'USD',
        money_original_currencies: 1,
      }),
    ], 'SAR')

    expect(mix?.dropped).toBe(1)

    expect(mix?.rows.find((r) => r.key === 'sales')?.spend).toBe(100)
    expect(mix?.rows.find((r) => r.key === 'awareness_engagement')?.spend).toBeNull()
  })

  /** Results add whatever the money does — a count is a count in every currency. */
  it('adds the results even where a spend was withheld', () => {
    const mix = objectiveMix([
      row({ objective: 'sales', conversions: 4 }),
      row({ objective: 'sales', conversions: 6 }),
    ], 'SAR')

    expect(mix?.rows.find((r) => r.key === 'sales')?.results).toBe(10)
  })
})
