import { describe, expect, it } from 'vitest'

import { liveSpark } from './liveSpark'

/*
 * A live KPI card draws the shape of its own metric over the period, by the one rule the product has
 * for it (`sparkFor`): measured locally at 390px, the summary's Cost-per-result and Revenue cards drew
 * nothing though the payload carries both series daily, so half of the first screen was empty card.
 * And the old local rule read a null day as 0 — a withheld spend drawn as a day that spent nothing.
 */
const days = (values: Array<Record<string, number | null>>) =>
  values.map((v, i) => ({ date: `2026-09-${String(i + 1).padStart(2, '0')}`, ...v }))

describe('the sparkline on a live KPI card', () => {
  it('draws revenue and cost per result, which the payload carries every day', () => {
    const series = days([{ revenue: 100, cpa: 20 }, { revenue: 140, cpa: 18 }, { revenue: 90, cpa: 25 }])

    expect(liveSpark('revenue', series)).toEqual([100, 140, 90])
    expect(liveSpark('cpa', series)).toEqual([20, 18, 25])
  })

  it('never draws a withheld day as a zero', () => {
    const series = days([{ spend: 100 }, { spend: null }, { spend: null }, { spend: 120 }])

    expect(liveSpark('spend', series), 'a series mostly withheld was drawn with zeros in its holes').toBeUndefined()
  })

  it('draws nothing for a metric flat all period', () => {
    expect(liveSpark('revenue', days([{ revenue: 0 }, { revenue: 0 }, { revenue: 0 }]))).toBeUndefined()
  })
})
