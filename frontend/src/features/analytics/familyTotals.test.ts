import { describe, expect, it } from 'vitest'

import { familyMoney, familyTotal, isDerived, sumBase } from './familyTotals'
import { SPECS } from './metricCatalog'

const campaign = (over: Record<string, unknown>) => ({
  spend: 0, revenue: 0, impressions: 0, clicks: 0, conversions: 0, reach: 0,
  spend_withheld_rows: 0, spend_original: 0, revenue_withheld_rows: 0, revenue_original: 0,
  money_original_currency: null, money_original_currencies: 0,
  ...over,
})

describe('totalling a family of campaigns', () => {
  it('sums a count', () => {
    expect(sumBase([campaign({ clicks: 120 }), campaign({ clicks: 80 })], 'clicks')).toBe(200)
  })

  /**
   * The defect: `reduce((t, r) => t + r.roas)` printed 8 for two campaigns returning 3× and 5×.
   * The family earned 8,000 on 2,000 — which is 4×, and is not the sum of the two ratios.
   */
  it('rebuilds ROAS from the totals instead of adding the ratios together', () => {
    const rows = [
      campaign({ spend: 1000, revenue: 3000, roas: 3 }),
      campaign({ spend: 1000, revenue: 5000, roas: 5 }),
    ]

    expect(familyTotal(rows, 'roas')).toBe(4)
  })

  it('rebuilds CTR rather than adding percentages', () => {
    const rows = [
      campaign({ clicks: 100, impressions: 10_000, ctr: 0.01 }),
      campaign({ clicks: 300, impressions: 10_000, ctr: 0.03 }),
    ]

    expect(familyTotal(rows, 'ctr')).toBeCloseTo(0.02, 6)
  })

  it('applies CPM’s per-thousand factor', () => {
    expect(familyTotal([campaign({ spend: 500, impressions: 250_000 })], 'cpm')).toBe(2)
  })

  it('refuses a rate whose denominator nobody reported', () => {
    expect(familyTotal([campaign({ impressions: 5000, reach: 0 })], 'frequency')).toBeNull()
  })

  /** «No platform sends this» is not «the platforms sent zero» — FUNNEL-NULL-001, one level up. */
  it('returns null for a base no row reported, rather than seeding at zero', () => {
    expect(sumBase([campaign({ leads: null }), campaign({ leads: null })], 'leads')).toBeNull()
  })

  describe('when the money was withheld for want of a rate', () => {
    const withheld = [
      campaign({ spend: 0, revenue: 0, roas: 0, conversions: 100, spend_withheld_rows: 5, spend_original: 2000, revenue_withheld_rows: 5, revenue_original: 8000, money_original_currency: 'USD', money_original_currencies: 1 }),
      campaign({ spend: 0, revenue: 0, roas: 0, conversions: 100, spend_withheld_rows: 5, spend_original: 2000, revenue_withheld_rows: 5, revenue_original: 8000, money_original_currency: 'USD', money_original_currencies: 1 }),
    ]

    it('reads the originals rather than summing the coalesced zeros', () => {
      expect(familyTotal(withheld, 'roas')).toBe(4)
      expect(familyTotal(withheld, 'cpa')).toBe(20)
    })

    it('names the one currency the family agrees on', () => {
      expect(familyMoney(withheld).money_original_currency).toBe('USD')
    })

    it('names none when the family does not agree, so no total pretends to a unit', () => {
      const mixed = [withheld[0], campaign({ spend_withheld_rows: 1, spend_original: 10, money_original_currency: 'EUR', money_original_currencies: 1 })]

      expect(familyMoney(mixed).money_original_currency).toBeNull()
      expect(familyMoney(mixed).money_original_currencies).toBe(2)
    })
  })

  it('knows which keys are rates, so a caller can format them apart from quantities', () => {
    expect(isDerived('roas')).toBe(true)
    expect(isDerived('ctr')).toBe(true)
    expect(isDerived('conversions')).toBe(false)
    expect(isDerived('impressions')).toBe(false)
  })
})

/**
 * OBJECTIVE-TOTALS-002 — a family must not name a metric the catalogue does not define.
 *
 * The Video family asked for `completion_rate`; the catalogue and the aggregator both call it
 * `video_completion_rate`. So it rendered «—» under its own key: a figure that looked unreported
 * when it was only misspelt, and no test could see the difference because both states are «—».
 *
 * The list mirrors `FAMILIES` in `AnalyticsPage.tsx`.
 */
describe('the objective families name metrics that exist', () => {
  const FAMILY_KPIS = [
    ['awareness', ['impressions', 'reach', 'frequency', 'cpm']],
    ['traffic', ['clicks', 'ctr', 'cpc']],
    ['engagement', ['engagements', 'engagement_rate']],
    ['video', ['video_views', 'video_completion_rate']],
    ['leads', ['leads', 'conversion_rate']],
    ['sales', ['conversions', 'revenue', 'roas']],
    ['app', ['installs']],
    ['unknown', ['impressions', 'clicks']],
  ] as const

  it.each(FAMILY_KPIS)('%s names only metrics the catalogue defines', (_family, kpis) => {
    for (const key of kpis) {
      expect(SPECS[key], `${key} is not a metric this product has`).toBeDefined()
    }
  })

  it('can total every metric the families ask for', () => {
    const rows = [{ impressions: 1000, clicks: 10, spend: 50, reach: 800, engagements: 5, video_views: 100, video_completions: 40, leads: 2, conversions: 3, revenue: 200, installs: 1, purchases: 3 }]

    for (const [, kpis] of FAMILY_KPIS) {
      for (const key of kpis) {
        expect(() => familyTotal(rows as never, key)).not.toThrow()
      }
    }
  })
})
