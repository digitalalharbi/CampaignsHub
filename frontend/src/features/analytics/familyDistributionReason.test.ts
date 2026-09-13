import { describe, expect, it } from 'vitest'
import { distributionFor } from './familyDistribution'

/**
 * AGGREGATION-TRUTH-001 — a family with no distribution says WHY it has none.
 *
 * A family whose spend the sync could not convert arrives here identical to a family that spent
 * nothing: both carry `spend: 0`. The reader was shown a bare list of campaign names either way — a
 * metric disappearing with no state at all, which is the shape the owner reports across surfaces.
 *
 * The distribution is still not drawn for withheld money, and that is correct: those shares would
 * be computed by summing unconverted originals from several currencies under one reporting label,
 * which is a fabricated figure rather than a withheld one.
 */
describe('why a family shows no distribution', () => {
  it('names withheld money as the reason', () => {
    const d = distributionFor([
      { name: 'أ', spend: 0, spend_original: 5000, spend_withheld_rows: 3 },
      { name: 'ب', spend: 0, spend_original: 2000, spend_withheld_rows: 1 },
    ])

    expect(d.meaningful).toBe(false)
    expect(d.reason).toBe('spend_withheld')
    // And refuses to invent shares out of currencies it could not convert.
    expect(d.slices).toEqual([])
  })

  it('still says «too few» when the money simply is not there', () => {
    const d = distributionFor([
      { name: 'أ', spend: 0, spend_original: 0, spend_withheld_rows: 0 },
      { name: 'ب', spend: 0, spend_original: 0, spend_withheld_rows: 0 },
    ])

    expect(d.meaningful).toBe(false)
    expect(d.reason).toBe('too_few_spending')
  })

  it('a single spending campaign is not a distribution, and says so', () => {
    const d = distributionFor([{ name: 'أ', spend: 900 }, { name: 'ب', spend: 0 }])

    expect(d.meaningful).toBe(false)
    expect(d.reason).toBe('too_few_spending')
  })

  it('draws the distribution when the money is real and converted', () => {
    const d = distributionFor([{ name: 'أ', spend: 600 }, { name: 'ب', spend: 400 }])

    expect(d.meaningful).toBe(true)
    expect(d.reason).toBeUndefined()
    expect(d.total).toBe(1000)
    expect(d.slices[0].share).toBeCloseTo(0.6, 5)
  })
})
