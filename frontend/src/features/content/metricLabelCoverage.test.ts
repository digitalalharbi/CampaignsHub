import { describe, expect, it } from 'vitest'
import { metricKind, metricLabel } from './metrics'

/**
 * Owner defect 94d — the label map has to cover the vocabulary, not most of it.
 *
 * `metricLabel` falls back to the KEY, which is a reasonable thing for a formatter to do and a
 * terrible thing to rely on: the fallback renders an English identifier in the middle of Arabic copy
 * on the row carrying a creative's most important figures. It has happened twice — once for the
 * sales set, then for leads, engagement and app — and the first fix was a comment saying it must not
 * happen again.
 *
 * So it is a test. The families are mirrored from `ObjectiveFamily::headlineMetrics()`; when one
 * changes there, this fails here, which is the point.
 */
const HEADLINE_METRICS: Record<string, string[]> = {
  awareness: ['spend', 'impressions', 'reach', 'frequency', 'cpm'],
  traffic: ['spend', 'clicks', 'ctr', 'cpc', 'landing_page_views', 'cost_per_lpv'],
  engagement: ['spend', 'engagements', 'engagement_rate', 'cpe', 'impressions'],
  video: ['spend', 'video_views', 'completion_rate', 'cost_per_view', 'view_rate', 'impressions'],
  leads: ['spend', 'leads', 'cpl', 'conversion_rate', 'clicks'],
  sales: ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov'],
  app: ['spend', 'installs', 'cpi', 'sign_ups', 'app_opens'],
  unknown: ['spend', 'impressions', 'clicks', 'ctr', 'cpm'],
  // Rides along on an awareness buy — see `CreativeMetrics::headline()`.
  awarenessVideoRider: ['video_views', 'view_rate', 'completion_rate', 'cost_per_view'],
  // The ad grain's own results, surfaced by CONTENT-KPI-COVERAGE-002.
  adGrain: ['leads', 'sign_ups', 'installs', 'app_opens', 'page_views'],
}

describe('the creative metric label map', () => {
  it('names every metric a family can headline, in both languages', () => {
    const untranslated: string[] = []

    for (const [family, metrics] of Object.entries(HEADLINE_METRICS)) {
      for (const key of metrics) {
        for (const locale of ['ar', 'en'] as const) {
          // The fallback IS the key, so «label equals key» is exactly the failure being caught.
          if (metricLabel(key, locale) === key) untranslated.push(`${family} → ${key} (${locale})`)
        }
      }
    }

    expect(untranslated, `these render as raw keys: ${untranslated.join(', ')}`).toEqual([])
  })

  /**
   * A cost carries its currency.
   *
   * `cpl`, `cpi` and `cpe` were classified as plain numbers, so a lead-generation card would print
   * «12.5» beside a «12.50 SAR» — which reads as a count rather than as money, and a reader
   * comparing the two has no way to know which is right.
   */
  it('classifies every cost-per-something as money', () => {
    for (const cost of ['cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_lpv', 'aov']) {
      expect(metricKind(cost), `${cost} is not money`).toBe('money')
    }
  })

  it('classifies every rate as a percentage', () => {
    for (const rate of ['ctr', 'conversion_rate', 'view_rate', 'completion_rate', 'hook_rate', 'engagement_rate']) {
      expect(metricKind(rate), `${rate} is not a percentage`).toBe('percent')
    }
  })
})
