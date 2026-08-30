import { describe, expect, it } from 'vitest'
import { efficiencyFor, returnFor } from './pathEfficiency'
import type { PlatformPathRow } from './api'

/**
 * PLATFORM-DECISION-ANALYTICS-001 — efficiency belongs to the path, and so does the refusal.
 *
 * The hard constraint of this requirement is that no comparison may be assembled across objectives.
 * A fixed column of four efficiencies breaks it one column at a time: two of them are «—» on every
 * row, and the reader compares the two that are populated.
 */
const row = (over: Partial<PlatformPathRow> = {}): PlatformPathRow => ({
  provider: 'meta',
  spend: 1000,
  impressions: 500_000,
  clicks: 4000,
  landing_page_views: 3000,
  orders: 40,
  revenue: 6000,
  campaigns: 3,
  spend_share: 0.4,
  ...over,
})

describe('the cost that names what a path was buying', () => {
  it('prices awareness by the thousand impressions', () => {
    const e = efficiencyFor('awareness', row())
    expect(e.key).toBe('cpm')
    expect(e.value).toBeCloseTo(2)
  })

  it('prices traffic by the click and consideration by the arrival', () => {
    expect(efficiencyFor('traffic', row()).key).toBe('cpc')

    const consideration = efficiencyFor('consideration', row())
    expect(consideration.key).toBe('cpv')
    expect(consideration.value).toBeCloseTo(1000 / 3000)
  })

  /** The click is the platform's claim; the arrival is the site's. Falling back says which it used. */
  it('falls back to the click when the site reported no arrival', () => {
    expect(efficiencyFor('consideration', row({ landing_page_views: 0 })).key).toBe('cpc')
  })

  it('prices everything else by the result', () => {
    const e = efficiencyFor('sales', row())
    expect(e.key).toBe('cpa')
    expect(e.value).toBeCloseTo(25)
  })

  /**
   * A zero denominator is what the aggregator writes both for «this platform publishes no
   * impressions» and for «it got none». Dividing by it produces a number that reads as a
   * measurement, so it produces nothing instead.
   */
  it('returns nothing rather than dividing by a denominator nobody reported', () => {
    expect(efficiencyFor('awareness', row({ impressions: 0 })).value).toBeNull()
    expect(efficiencyFor('sales', row({ orders: 0 })).value).toBeNull()
    expect(efficiencyFor('traffic', row({ spend: 0 })).value).toBeNull()
  })
})

describe('return, only where returning is what the path was for', () => {
  it('is stated on a conversion path', () => {
    expect(returnFor('sales', row()).value).toBeCloseTo(6)
  })

  /**
   * And refused on the two that were never buying it. Revenue attached to a brand path is an
   * accident of attribution — a sale credited to an impression nobody was asked to buy — and
   * printing it as ROAS is the cross-objective claim this requirement forbids.
   */
  it('is refused on awareness and traffic, whatever revenue was credited', () => {
    expect(returnFor('awareness', row({ revenue: 90_000 })).value).toBeNull()
    expect(returnFor('traffic', row({ revenue: 90_000 })).value).toBeNull()
  })
})
