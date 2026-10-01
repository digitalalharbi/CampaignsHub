import { describe, expect, it } from 'vitest'
import type { CreativeMetrics } from './api'
import { formatMetric, metricState, unavailableChip, unavailableReason } from './metrics'

/**
 * CONTENT-RESULT-AVAILABILITY-001 — «الطلبات 0» said several different things.
 *
 * A zero can mean the ad was measured and sold nothing, or that the platform answered a question
 * nobody can answer for this account, or that the figure belongs to the campaign and not to this
 * creative. The server says which — `availability` beside `reported` — and these hold the readings
 * apart at the one place every surface reads them.
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

describe('a result figure whose measurement nobody has verified', () => {
  /** Owner regression — an unverified zero does NOT render as 0. */
  it('is not a zero', () => {
    const state = metricState(
      metrics({ availability: { orders: 'measurement_unverified' } }),
      'orders',
    )

    expect(state).toEqual({ kind: 'not_provided', reason: 'measurement_unverified' })
  })

  /**
   * And it is not «not provided» either, which would be a second unsupported claim: the provider
   * DID send something, and what is unknown is whether it means anything.
   */
  it('says what is unknown rather than claiming the metric is unavailable', () => {
    const said = unavailableReason('measurement_unverified', 'ar')

    expect(said).toMatch(/لم يتم التحقق/)
    expect(said, 'the copy claims the metric cannot be measured here').not.toMatch(/غير متاح/)
  })

  /** Owner regression — a CONFIRMED zero is still a zero. */
  it('and a confirmed zero is still a zero', () => {
    const state = metricState(
      metrics({ conversions: 0, orders: 0, availability: { orders: 'real_zero_confirmed' } }),
      'orders',
    )

    expect(state).toEqual({ kind: 'value', value: 0 })
  })

  it('a reported figure is shown as itself', () => {
    const state = metricState(
      metrics({ conversions: 12, orders: 12, availability: { orders: 'reported_value' } }),
      'orders',
    )

    expect(state).toEqual({ kind: 'value', value: 12 })
  })

  /**
   * The reason reaches the reader as a SENTENCE, not as an enum.
   *
   * «MEASUREMENT_UNVERIFIED» on a card is a developer's word for a customer's problem. Each of the
   * three maps to one plain sentence about the platform, the account or the period, in both
   * languages, and none of them mentions a field, a grain or a failure.
   */
  it('says why in words a customer can read', () => {
    const said = (['not_reported', 'measurement_unverified', 'not_attributable'] as const).map(
      (reason) => unavailableReason(reason, 'ar'),
    )

    expect(new Set(said).size, 'two states share one sentence').toBe(3)

    for (const locale of ['ar', 'en'] as const) {
      for (const reason of ['not_reported', 'measurement_unverified', 'not_attributable'] as const) {
        const sentence = unavailableReason(reason, locale)

        expect(sentence, `${reason} (${locale}) has no sentence`).toBeTruthy()
        expect(sentence, `${reason} (${locale}) leaks a technical word`).not.toMatch(
          /\b(null|SQL|grain|API|mapping|backend|enum|field|pixel|tracking)\b/i,
        )
      }

      expect(unavailableChip(locale)).toBeTruthy()
    }
  })
})

/**
 * The short form has to be accurate on surfaces with no room for a tooltip.
 *
 * «غير مُرسَل» says the platform sent nothing. That is true of `not_reported` and false of the
 * other two — an unverified zero DID arrive, and an unattributable figure exists and belongs to
 * somebody else — so printing it for all three would put a wrong sentence under the figure on every
 * table and detail row in the product.
 */
describe('the short form beside a figure', () => {
  it('only claims the platform sent nothing where it did not', () => {
    expect(formatMetric({ kind: 'not_provided', reason: 'not_reported' }, 'orders', 'ar', null)).toBe('غير مُرسَل')

    for (const reason of ['measurement_unverified', 'not_attributable'] as const) {
      expect(
        formatMetric({ kind: 'not_provided', reason }, 'orders', 'ar', null),
        `${reason} is being reported as a metric the platform never sent`,
      ).toBe('—')
    }
  })
})
