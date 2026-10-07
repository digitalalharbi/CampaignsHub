import { describe, expect, it } from 'vitest'
import { donutOthersName } from './charts'

/**
 * CHART-LEGEND-LOCALE — the «Others» slice was an Arabic string literal.
 *
 * An English reader met one Arabic entry in an otherwise English legend. This is the same defect
 * `spendRevenueSeriesNames` was written to fix for the area chart's two series — the donut's
 * grouping label was the one name in this file that fix did not reach, because it is built inside
 * the component rather than passed in.
 *
 * Asserted through the function rather than the chart: `ResponsiveContainer` measures its parent and
 * jsdom reports every element as 0×0, so recharts draws no legend to read.
 */
describe('donutOthersName', () => {
  it('names the grouped remainder in English for an English reader', () => {
    expect(donutOthersName(false)).toBe('Others')
  })

  it('names it in Arabic for an Arabic reader', () => {
    expect(donutOthersName(true)).toBe('أخرى')
  })

  it('never hands one language the other one’s word', () => {
    expect(donutOthersName(false)).not.toMatch(/[؀-ۿ]/)
    expect(donutOthersName(true)).toMatch(/[؀-ۿ]/)
  })
})
