import { describe, expect, it } from 'vitest'
import { readMetricValue } from './metricValue'

/**
 * NUMBER-PRESENTATION-001 §58 — one law for how a figure is written, everywhere it is written.
 *
 * The owner's screenshot is the case this exists for: «29,210», «4,127,676» and «5.54K USD» in one
 * row of six cards on the page a client reads. Three notations for one idea, because the cards took a
 * pre-formatted string and each surface chose its own.
 */
describe('the product’s one value law', () => {
  it('compacts a count and keeps the exact figure a hover away', () => {
    expect(readMetricValue('number', 4127676)).toMatchObject({ text: '4.13M', exact: '4,127,676' })
    expect(readMetricValue('number', 1723184)).toMatchObject({ text: '1.72M', exact: '1,723,184' })
    expect(readMetricValue('number', 29210)).toMatchObject({ text: '29.2K', exact: '29,210' })
    expect(readMetricValue('number', 1400)).toMatchObject({ text: '1.4K', exact: '1,400' })
  })

  /** A tooltip repeating what is already on screen teaches a reader to stop opening tooltips. */
  it('offers no exact figure where compacting changed nothing', () => {
    expect(readMetricValue('number', 42).exact).toBeNull()
  })

  it('compacts money and keeps its exact amount', () => {
    const read = readMetricValue('money', 5535, { currency: 'USD' })

    expect(read.text).toBe('5.54K USD')
    expect(read.exact).toContain('5,535')
  })

  /**
   * **Precision IS the decision, and these are never compacted.**
   *
   * A CPC of «0.19 USD» is a decision; «0.2 USD» is a different one. The owner's correction names
   * this exception outright — CPC, CPL, CPA, ROAS and percentages where precision matters — so it is
   * asserted rather than left to the formatter's own rounding to preserve by luck.
   */
  it('never abbreviates a ratio or a percentage', () => {
    expect(readMetricValue('ratio', 7.98).text).toBe('7.98×')
    expect(readMetricValue('percent', 0.0071, { digits: 2 }).text).toBe('0.71%')
    expect(readMetricValue('ratio', 7.98).exact).toBeNull()
  })

  /** A cost-per is money, and small money is already exact — the rule holds without an exception. */
  it('leaves a small cost-per figure as it is', () => {
    const read = readMetricValue('money', 0.19, { currency: 'USD' })

    expect(read.text).toBe('0.19 USD')
    expect(read.exact).toBeNull()
  })

  /** A missing figure is the product's one dash: not a zero, not a blank. */
  it('prints the one dash for a figure nobody reported', () => {
    for (const kind of ['number', 'money', 'percent', 'ratio'] as const) {
      expect(readMetricValue(kind, null).text, `${kind} invented a figure`).toBe('—')
      expect(readMetricValue(kind, null).value).toBeNull()
    }
  })

  /** A real zero is a measurement — «this campaign spent nothing» — and survives. */
  it('keeps a real zero', () => {
    expect(readMetricValue('number', 0).text).toBe('0')
    expect(readMetricValue('number', 0).value).toBe(0)
  })
})
