import { describe, expect, it } from 'vitest'
import type { CreativeMetrics } from './api'
import { metricState, unavailableReason } from './metrics'

/**
 * CONTENT-RESULT-AVAILABILITY-001 — «الطلبات 0» said two different things.
 *
 * Either the creative was measured and sold nothing, or nobody is measuring purchases on that
 * account and the platform answered anyway. The server now says which — `availability` beside
 * `reported` — and these hold the three readings apart at the only place every surface reads them.
 */
const metrics = (over: Partial<CreativeMetrics>): CreativeMetrics =>
  ({
    spend: 38.36, impressions: 9400, clicks: 120, conversions: 0, orders: 0,
    revenue: null, video_views: null, video_p25: null, video_p50: null, video_p75: null,
    video_p100: null, frequency: null, ctr: null, cpc: null, cpm: null, cpa: null, roas: null,
    conversion_rate: null, view_rate: null, completion_rate: null, active_days: 1,
    reported: { conversions: true, orders: true },
    ...over,
  }) as CreativeMetrics

describe('a result figure the provider cannot actually measure', () => {
  /** Owner regression 1 — unreported purchases do NOT render 0. */
  it('is not a zero', () => {
    const state = metricState(
      metrics({ reported: { conversions: false, orders: false }, availability: { orders: 'not_reported' } }),
      'orders',
    )

    expect(state.kind).not.toBe('value')
  })

  /** Owner regression 2 — a real reported zero DOES render 0. */
  it('and a measured zero is still a zero', () => {
    const state = metricState(
      metrics({ conversions: 0, reported: { conversions: true, orders: true }, availability: { orders: 'reported' } }),
      'orders',
    )

    expect(state).toEqual({ kind: 'value', value: 0 })
  })

  /**
   * The reason reaches the reader as a SENTENCE, not as an enum.
   *
   * «METRIC_NOT_REPORTED» on a card is a developer's word for a customer's problem. Each reason maps
   * to one plain sentence about the platform and the period, in both languages, and none of them
   * mentions a field, a grain or a failure.
   */
  it('says why in words a customer can read', () => {
    const notReported = unavailableReason('not_reported', 'ar')
    const notAttributable = unavailableReason('not_attributable', 'ar')

    expect(notReported).toBeTruthy()
    expect(notAttributable).toBeTruthy()
    expect(notReported).not.toBe(notAttributable)

    for (const locale of ['ar', 'en'] as const) {
      for (const reason of ['not_reported', 'not_attributable', 'no_activity'] as const) {
        const said = unavailableReason(reason, locale)

        expect(said, `${reason} (${locale}) has no sentence`).toBeTruthy()
        expect(said, `${reason} (${locale}) leaks a technical word`).not.toMatch(
          /\b(null|SQL|grain|API|mapping|backend|enum|field)\b/i,
        )
      }
    }
  })
})
