import { describe, expect, it } from 'vitest'
import { ANALYTICS_PLATFORMS } from './AnalyticsPage'
import { PAID_PLATFORMS } from '@/features/requests/paidMediaFields'
import { PLATFORM_ORDER, canonicalPlatform } from '@/lib/platforms'

/**
 * PLATFORM-FILTER-001 — a platform the product reads must be one a reader can FILTER by.
 *
 * These lists were written out by hand, each one a separate decision about which platforms exist.
 * The failure is silent in the worst way: the filter renders, every chip in it works, and the only
 * symptom is that one platform's spend can never be isolated — which looks like missing data rather
 * than a missing control.
 *
 * Asserting against `PLATFORM_ORDER` rather than against a literal is the point. A literal here
 * would be the eighth copy of the same list.
 */
describe('the platform filters', () => {
  it('offers every canonical platform in the analytics filter', () => {
    expect(ANALYTICS_PLATFORMS.map(canonicalPlatform)).toEqual([...PLATFORM_ORDER])
  })

  it('offers every canonical platform on the public request form', () => {
    expect(PAID_PLATFORMS.map((o) => canonicalPlatform(o.value))).toEqual([...PLATFORM_ORDER])
  })

  /** And each option is a name, not a key — «openai_ads» on a form is a leaked database value. */
  it('names them rather than printing their keys', () => {
    for (const option of PAID_PLATFORMS) {
      expect(option.ar, `${option.value} has no Arabic name`).not.toBe(option.value)
      expect(option.ar).not.toMatch(/_/)
    }
  })
})
