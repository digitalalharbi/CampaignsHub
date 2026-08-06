import { describe, expect, it } from 'vitest'
import { formatMetric, metricState } from './metrics'
import { formatBytes, formatClock, imageLoading } from './format'
import type { CreativeMetrics } from './api'

/**
 * The one rule this feature cannot get wrong: a metric nobody reported is not zero (§15.15).
 *
 * These are unit tests rather than component tests on purpose. The distinction lives in two small
 * pure functions precisely so it can be pinned exhaustively — «0 renders as 0» and «missing renders
 * as Not provided» are one `??` apart in every call site that reads a figure, and a rendering test
 * would only ever cover the call sites that happen to exist today.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics =>
  ({
    spend: 900,
    impressions: 40000,
    clicks: 200,
    conversions: null,
    revenue: 0,
    video_views: null,
    video_p100: null,
    ctr: 0.005,
    cpa: null,
    roas: 0,
    completion_rate: null,
    reported: {
      spend: true,
      impressions: true,
      clicks: true,
      conversions: false,
      revenue: true,
      video_views: false,
      video_p100: false,
    },
    ...over,
  }) as CreativeMetrics

describe('metricState', () => {
  it('reads a genuine zero as a measured value, not as missing', () => {
    // The platform reported revenue and it really was zero. Rendering «No data» here would hide a
    // campaign that spent 900 and earned nothing — the single most important thing on the card.
    expect(metricState(metrics(), 'revenue')).toEqual({ kind: 'value', value: 0 })
    expect(formatMetric(metricState(metrics(), 'revenue'), 'revenue', 'en')).toBe('0 SAR')
  })

  it('reads a metric the platform never sent as Not provided, not as zero', () => {
    // A text ad has no video completions. «0%» beside 40,000 impressions reads as a catastrophic
    // video rather than as a metric that was never applicable.
    expect(metricState(metrics(), 'video_views')).toEqual({ kind: 'not_provided' })
    expect(formatMetric(metricState(metrics(), 'video_views'), 'video_views', 'en')).toBe('Not provided')
    expect(formatMetric(metricState(metrics(), 'video_views'), 'video_views', 'ar')).toBe('غير مُرسَل')
  })

  it('distinguishes a ratio with no denominator from one the platform omitted', () => {
    // ROAS is 0 honestly: revenue really is 0 and spend is 900, so the return really was nothing.
    // CPA is null because there are no orders to divide by — «0 per order» would be a lie, and
    // «Not provided» would blame the platform for a division we chose not to do.
    expect(metricState(metrics(), 'roas')).toEqual({ kind: 'value', value: 0 })
    expect(metricState(metrics(), 'cpa')).toEqual({ kind: 'no_data' })
    expect(formatMetric(metricState(metrics(), 'cpa'), 'cpa', 'en')).toBe('No data')
  })

  it('treats a creative with no figures at all as no data on every metric', () => {
    expect(metricState(null, 'spend')).toEqual({ kind: 'no_data' })
  })

  it('formats rates, money and multiples in Latin digits in both languages', () => {
    expect(formatMetric(metricState(metrics(), 'ctr'), 'ctr', 'ar')).toBe('0.50%')
    expect(formatMetric(metricState(metrics(), 'spend'), 'spend', 'ar')).toBe('900 SAR')
    expect(formatMetric({ kind: 'value', value: 3.5 }, 'roas', 'ar')).toBe('3.50×')
  })
})

describe('format helpers', () => {
  it('clocks a duration and refuses to render NaN', () => {
    expect(formatClock(0)).toBe('0:00')
    expect(formatClock(75)).toBe('1:15')
    expect(formatClock(Number.NaN)).toBe('0:00')
    expect(formatClock(-4)).toBe('0:00')
  })

  it('never marks an inline asset lazy, because a lazy data URI never loads at all', () => {
    // Found in the browser: ten cards, ten blank frames, no error. The same URI loaded through
    // `new Image()` and through an identical `eager` element, so only the attribute was at fault.
    expect(imageLoading('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=')).toBe('eager')

    // Remote thumbnails keep it — that is where deferring a request actually saves anything.
    expect(imageLoading('https://cdn.example.com/a.jpg')).toBe('lazy')
    expect(imageLoading(null)).toBe('lazy')
  })

  it('leaves an unreported file size null rather than calling it zero bytes', () => {
    expect(formatBytes(null)).toBeNull()
    expect(formatBytes(900)).toBe('900 B')
    expect(formatBytes(2048)).toBe('2 KB')
    expect(formatBytes(3_145_728)).toBe('3.0 MB')
  })
})
