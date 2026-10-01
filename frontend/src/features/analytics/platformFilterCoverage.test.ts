import { describe, expect, it } from 'vitest'
import { ANALYTICS_PLATFORMS } from './AnalyticsPage'
import { PAID_PLATFORMS } from '@/features/requests/paidMediaFields'
import { PLATFORM_ORDER, canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'

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

  /**
   * **A report renders the NAME, and the server deliberately does not send one.**
   *
   * The shared-report payload carries `openai_ads` and the interface resolves it — which makes
   * labelling a client-side responsibility, and a report printing the raw key a client-side defect.
   * It is held here because this is where the resolution happens.
   */
  it('turns the provider key a report sends into a platform name', () => {
    expect(providerLabel('openai_ads', 'ar')).toBe('إعلانات ChatGPT')
    expect(providerLabel('openai_ads', 'en')).toBe('ChatGPT Ads')

    // The spellings that arrive from elsewhere resolve to the same name rather than rendering raw.
    for (const spelling of ['openai', 'chatgpt', 'chatgpt_ads']) {
      expect(providerLabel(spelling, 'ar')).toBe('إعلانات ChatGPT')
    }
  })

  /** And each option is a name, not a key — «openai_ads» on a form is a leaked database value. */
  it('names them rather than printing their keys', () => {
    for (const option of PAID_PLATFORMS) {
      expect(option.ar, `${option.value} has no Arabic name`).not.toBe(option.value)
      expect(option.ar).not.toMatch(/_/)
    }
  })
})
