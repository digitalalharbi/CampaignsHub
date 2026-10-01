import { describe, expect, it } from 'vitest'
import { spendRevenueSeriesNames } from './charts'

/**
 * A chart's LEGEND is its only key, and it has to be in the reader's language.
 *
 * `SpendRevenueAreaChart` named its two series with Arabic string literals, so an English reader met
 * «الإنفاق · الإيرادات» under an English title — observed on the campaigns overview at
 * `/agency/campaigns`. A legend in another language does not make a chart untidy; it makes it
 * unreadable, because nothing else on the chart says which line is which.
 *
 * It survived the Arabic-leak E2E guard twice over: `recharts` renders legend text in a `<span>`,
 * which that guard's chrome selector did not match until this change, and the rail walk it performs
 * never reaches a campaigns page with a project selected — so the chart does not render on it at
 * all. Asserted here as the mapping rather than through a render, because `ResponsiveContainer`
 * measures its parent and jsdom reports every element as 0×0, so recharts draws nothing and the
 * assertion would pass or fail for reasons unrelated to the names.
 */
describe('the spend and revenue series names', () => {
  it('read in English when the interface is English', () => {
    expect(spendRevenueSeriesNames(false)).toEqual({ spend: 'Spend', revenue: 'Revenue' })
  })

  it('read in Arabic when the interface is Arabic', () => {
    expect(spendRevenueSeriesNames(true)).toEqual({ spend: 'الإنفاق', revenue: 'الإيرادات' })
  })
})
