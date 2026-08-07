import { describe, expect, it } from 'vitest'
import { compact, money, num, percent, ratio } from './format'

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
    expect(money(0)).toBe('0 SAR')
  })

  it('says nothing when there is nothing to say', () => {
    expect(compact(null)).toBe('—')
    expect(compact(undefined)).toBe('—')
    expect(money(null)).toBe('—')
    expect(num(null)).toBe('—')
    expect(ratio(null)).toBe('—')
    expect(percent(null)).toBe('—')
  })

  it('abbreviates the large end unchanged', () => {
    expect(compact(950)).toBe('950')
    expect(compact(1_500)).toBe('1.5K')
    expect(compact(26_918)).toBe('27K')
    expect(compact(1_282_024)).toBe('1.3M')
    expect(compact(12_000_000)).toBe('12M')
  })

  /** `percent()` multiplies by 100 — the fact PERCENT-100X-001 exists because callers forgot. */
  it('percent takes a ratio, not a percentage', () => {
    expect(percent(0.021, 1)).toBe('2.1%')
    expect(percent(1)).toBe('100.0%')
  })
})
