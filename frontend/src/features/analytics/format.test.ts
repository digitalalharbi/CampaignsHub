import { describe, expect, it } from 'vitest'
import { compact, money, moneyExact, num, percent, ratio } from './format'

/**
 * COMPACT-ZERO-001 — the formatters may abbreviate a figure; they may not deny it.
 *
 * `compact()` rounded everything under 1000 with `Math.round`, so a cost of 0.028 SAR per impression
 * printed «0 SAR» on the funnel — «this step is free», beside a bar that cost thirty-six thousand
 * riyals. `CreativeDetailPage` had already met this and worked around it by refusing to put money on
 * its bars at all; the funnel had no such guard and stated the falsehood outright.
 */
describe('compact never rounds a real figure away to nothing', () => {
  it('keeps a small non-zero value visible', () => {
    expect(compact(0.028)).toBe('0.03')
    expect(compact(0.5)).toBe('0.50')
    expect(compact(0.0028)).toBe('0.0028')
    expect(compact(-0.028)).toBe('-0.03')
  })

  it('still says zero when the figure IS zero', () => {
    expect(compact(0)).toBe('0')
    expect(money(0, 'SAR')).toBe('0 SAR')
  })

  it('says nothing when there is nothing to say', () => {
    expect(compact(null)).toBe('—')
    expect(compact(undefined)).toBe('—')
    expect(money(null, 'SAR')).toBe('—')
    expect(num(null)).toBe('—')
    expect(ratio(null)).toBe('—')
    expect(percent(null)).toBe('—')
  })

  /*
   * The large end, under NUMBER-PRESENTATION-001's rule.
   *
   * Three of these expectations moved, and the movement is the point: 26,918 read «27K» and
   * 1,282,024 read «1.3M» because the old rule chose decimals by magnitude and threw away the digits
   * that tell two rows apart. They now read «26.9K» and «1.28M». The unchanged ones — 950, 1.5K,
   * 12M — are the cases where three significant digits and the old rule agree.
   */
  it('abbreviates the large end to three significant digits', () => {
    expect(compact(950)).toBe('950')
    expect(compact(1_500)).toBe('1.5K')
    expect(compact(26_918)).toBe('26.9K')
    expect(compact(1_282_024)).toBe('1.28M')
    expect(compact(12_000_000)).toBe('12M')
  })

  /** `percent()` multiplies by 100 — the fact PERCENT-100X-001 exists because callers forgot. */
  it('percent takes a ratio, not a percentage', () => {
    expect(percent(0.021, 1)).toBe('2.1%')
    expect(percent(1)).toBe('100.0%')
  })
})

/**
 * COMPACT-ZERO-001's other half — one order of magnitude up.
 *
 * The strip these figures live in exists to carry the PRECISE value into the PDF, and it rounded
 * every one of them. On a five-figure total that is invisible; on a cost-per it is the figure.
 */
describe('moneyExact', () => {
  it('keeps the decimals on a cost-per, where they are the number', () => {
    expect(moneyExact(29.71, 'SAR')).toBe('29.71 SAR')
    expect(moneyExact(73.72, 'SAR')).toBe('73.72 SAR')
    expect(moneyExact(1.83, 'SAR')).toBe('1.83 SAR')
  })

  it('leaves every large total reading exactly as it did', () => {
    expect(moneyExact(96121, 'SAR')).toBe('96,121 SAR')
    expect(moneyExact(96121.37, 'SAR')).toBe('96,121 SAR')
    expect(moneyExact(8900, 'SAR')).toBe('8,900 SAR')
  })

  it('does not dress a whole number in decimals it does not have', () => {
    expect(moneyExact(30, 'SAR')).toBe('30 SAR')
    expect(moneyExact(0, 'SAR')).toBe('0 SAR')
  })

  it('still says nothing rather than zero for an absent figure', () => {
    expect(moneyExact(null, 'SAR')).toBe('—')
    expect(moneyExact(undefined, 'SAR')).toBe('—')
  })
})

/**
 * NUMBER-PRESENTATION-001 — the compact rule, at every boundary that used to lose information.
 *
 * The old rule chose decimals by magnitude: one below ten thousand, none above. So 32,400 printed
 * «32K» and 4,850,000 printed «4.9M» — accurate roundings that are useless for comparison, because
 * two rows reading «32K» can be a thousand results apart with nothing on screen to say so.
 */
describe('compact — three significant digits, trailing zeros dropped', () => {
  const cases: [number, string][] = [
    [0, '0'],
    [7, '7'],
    [30, '30'],
    [90, '90'],
    [999, '999'],
    [1_000, '1K'],
    [1_049, '1.05K'],
    [1_300, '1.3K'],
    [9_990, '9.99K'],
    [32_400, '32.4K'],
    [32_999, '33K'],
    [999_499, '999K'],
    [1_000_000, '1M'],
    [1_990_000, '1.99M'],
    [4_850_000, '4.85M'],
    [12_300_000, '12.3M'],
    [1_000_000_000, '1B'],
    [2_470_000_000, '2.47B'],
    [-32_400, '-32.4K'],
  ]

  it.each(cases)('formats %d as %s', (input, expected) => {
    expect(compact(input)).toBe(expected)
  })

  /* The examples the requirement itself names, asserted as a set so none can be quietly dropped. */
  it('formats the requirement’s own examples', () => {
    expect([1_000, 1_300, 32_400, 1_990_000, 4_850_000].map(compact))
      .toEqual(['1K', '1.3K', '32.4K', '1.99M', '4.85M'])
  })

  /* A compact figure is never the whole story, so the exact one has to stay available. */
  it('keeps the exact figure reachable through num()', () => {
    expect(compact(4_850_321)).toBe('4.85M')
    expect(num(4_850_321)).toBe('4,850,321')
  })

  /* Currency is appended, never folded into the abbreviation. */
  it('states the currency beside the compact value', () => {
    expect(money(4_850_000, 'SAR')).toBe('4.85M SAR')
  })
})
