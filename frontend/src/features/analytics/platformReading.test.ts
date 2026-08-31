import { describe, expect, it } from 'vitest'
import { platformReadings } from './platformReading'
import type { PlatformPath, PlatformPathRow } from './api'

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the reading over the platform block, and the two things it refuses.
 *
 * A platform whose efficiency could not be computed is not the cheapest platform: reading its
 * missing denominator as zero crowns the one nobody measured, which is the most confident wrong
 * answer this block could give. And a path fewer than two platforms spent on has no range at all —
 * «Meta is the cheapest awareness buyer», said of the only platform that ran awareness, is a
 * sentence with no evidence behind it.
 */
const row = (provider: string, over: Partial<PlatformPathRow> = {}): PlatformPathRow => ({
  provider,
  spend: 1000,
  impressions: 500_000,
  clicks: 4000,
  landing_page_views: 3000,
  orders: 40,
  revenue: 6000,
  campaigns: 2,
  spend_share: 0.5,
  ...over,
})

const path = (over: Partial<PlatformPath> = {}): PlatformPath => ({
  path: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  headline_metrics: ['spend', 'impressions'],
  platforms: [row('meta'), row('tiktok', { impressions: 250_000 })],
  spend: 2000,
  comparable: true,
  comparable_reason: 'two_or_more_platforms_spent',
  ...over,
})

describe('the reading over a path’s platforms', () => {
  it('names the cheapest and dearest on the metric that path was buying', () => {
    const [reading] = platformReadings([path()])

    expect(reading).toMatchObject({ path: 'awareness', metric: 'cpm' })
    if (reading && 'cheapest' in reading) {
      // meta: 1000 over 500k → 2 per thousand; tiktok: 1000 over 250k → 4.
      expect(reading.cheapest.provider).toBe('meta')
      expect(reading.dearest.provider).toBe('tiktok')
      expect(reading.spread).toBeCloseTo(2)
    }
  })

  /** A platform with no denominator is not the cheapest one. */
  it('leaves out a platform whose cost could not be computed', () => {
    const [reading] = platformReadings([
      path({ platforms: [row('meta'), row('x', { impressions: 0 }), row('tiktok', { impressions: 250_000 })] }),
    ])

    if (reading && 'cheapest' in reading) {
      expect(reading.cheapest.provider).toBe('meta')
      expect(reading.dearest.provider).toBe('tiktok')
    } else {
      throw new Error('expected a reading')
    }
  })

  it('says nothing where the path is not comparable', () => {
    const [reading] = platformReadings([path({ comparable: false, comparable_reason: 'only_one_platform_spent' })])

    expect(reading).toEqual({ path: 'awareness', metric: null, silentReason: 'not_comparable' })
  })

  /** Two platforms, only one of which reported the cost, is not a range either. */
  it('says nothing where only one platform reported the cost', () => {
    const [reading] = platformReadings([
      path({ platforms: [row('meta'), row('x', { impressions: 0 })] }),
    ])

    expect(reading).toEqual({ path: 'awareness', metric: null, silentReason: 'no_platform_reported_this_cost' })
  })

  /** Return is read only where returning is what the path was for. */
  it('names a return on a conversion path and never on awareness', () => {
    const [awareness] = platformReadings([path()])
    if (awareness && 'returns' in awareness) expect(awareness.returns).toBeNull()

    const [conversion] = platformReadings([path({ path: 'conversion', label_en: 'Conversion' })])
    if (conversion && 'returns' in conversion) {
      expect(conversion.returns?.value).toBeCloseTo(6)
    }
  })
})
