import { describe, expect, it } from 'vitest'
import { diagnosis, monotoneSteps, rating, scoreContent, scorePath } from './contentScore'

/** CONTENT-SCORE-001 — no verdict without data, no stage above the one before it, CTR judged on its own platform. */
describe('the content scorecard', () => {
  it('grades nothing under the impression floor', () => {
    const s = scoreContent({ impressions: 400, clicks: 50, video_views: 200 })

    expect(s.sufficient).toBe(false)
    expect(s.score).toBeNull()
    expect(s.criteria.every((c) => c.grade === 'na')).toBe(true)
  })

  it('grades a strong video and names the weak link', () => {
    const s = scoreContent({ provider: 'snapchat', impressions: 100_000, video_views: 35_000, video_p50: 7_000, video_p100: 3_000, clicks: 1_400 })
    const g = Object.fromEntries(s.criteria.map((c) => [c.key, c.grade]))

    expect(g.hook).toBe('good')
    expect(g.hold).toBe('weak')
    expect(g.completion).toBe('weak')
    expect(g.ctr).toBe('good')
    expect(s.score).not.toBeNull()
    expect(diagnosis(s, true)).toBe('يطلعون قبل ما توصل الرسالة')
  })

  it('never grades orders above carts', () => {
    const s = scoreContent({ impressions: 50_000, clicks: 500, add_to_cart: 40, purchases: 90 })

    expect(s.criteria.find((c) => c.key === 'cart_order')?.grade).toBe('na')
  })

  it('judges a search click by search, a story click by story', () => {
    const ad = { impressions: 10_000, clicks: 150 }

    expect(scoreContent({ ...ad, provider: 'google' }).criteria.find((c) => c.key === 'ctr')?.grade).toBe('weak')
    expect(scoreContent({ ...ad, provider: 'snapchat' }).criteria.find((c) => c.key === 'ctr')?.grade).toBe('good')
  })

  it('treats a figure the platform did not report as not graded', () => {
    const s = scoreContent({ impressions: 20_000, clicks: 300, video_views: 5_000, reported: { video_views: false } })

    expect(s.criteria.find((c) => c.key === 'hook')?.grade).toBe('na')
  })

  it('stops a funnel before a stage that grows', () => {
    const steps = [{ v: 1000 }, { v: 40 }, { v: 12 }, { v: 30 }, { v: 5 }]

    expect(monotoneSteps(steps).map((s) => s.v)).toEqual([1000, 40, 12])
  })

  /** CONTENT-SCORE-002 — a report row: figures under `metrics`, orders not purchases, no impressions in its settings. */
  it('grades a report row on clicks and money when its settings show no impressions', () => {
    const s = scoreContent({ provider: 'tiktok', metrics: { spend: 143.3, clicks: 553, revenue: 3425.25, purchases: null, orders: 49 } })

    expect(s.sufficient).toBe(true)
    expect(s.criteria.find((c) => c.key === 'roas')?.grade).toBe('good')
    expect(s.criteria.find((c) => c.key === 'ctr')?.grade).toBe('na')
    expect(s.criteria.find((c) => c.key === 'hook')?.grade).toBe('na')
    expect(diagnosis(s, true)).toBe('ممتاز جداً')
  })

  /** CONTENT-SCORE-003 — each criterion carries its normal band; click-through takes its platform's. */
  it('carries the normal band of each criterion', () => {
    const s = scoreContent({ provider: 'google_ads', metrics: { clicks: 553, spend: 100, revenue: 300, orders: 12 } })

    expect(s.criteria.find((c) => c.key === 'hook')?.norm).toEqual([0.2, 0.3])
    expect(s.criteria.find((c) => c.key === 'ctr')?.norm).toEqual([0.02, 0.05])
    expect(s.criteria.find((c) => c.key === 'roas')?.grade).toBe('ok')
  })

  /** CONTENT-ANALYSIS-BY-OBJECTIVE-001 — a lead ad is judged on leads, never on carts or return. */
  it('judges a lead ad on click to lead and engagement, not on carts and return', () => {
    const s = scoreContent({ provider: 'meta', objective: 'leads', impressions: 40_000, clicks: 600, leads: 72, engagements: 900, add_to_cart: 20, purchases: 5 })
    const keys = s.criteria.map((c) => c.key)

    expect(keys).toContain('click_lead')
    expect(keys).not.toContain('cart_order')
    expect(keys).not.toContain('roas')
    expect(s.criteria.find((c) => c.key === 'click_lead')?.grade).toBe('good')
    expect(s.criteria.find((c) => c.key === 'engagement')?.grade).toBe('ok')
  })

  it('names the lead gap when clicks do not become leads', () => {
    const s = scoreContent({ provider: 'snapchat', objective: 'leads', impressions: 50_000, clicks: 700, leads: 7 })

    expect(diagnosis(s, true)).toBe('يضغطون وما يسجلون')
  })

  it('reads a conversion ad that only states leads as a lead ad', () => {
    expect(scorePath({ objective: 'conversions', leads: 12, purchases: 0 })).toBe('leads')
    expect(scorePath({ objective: 'conversions', leads: 0, purchases: 9 })).toBe('sales')
    expect(scorePath({ objective: 'traffic' })).toBe('reach')
  })

  it('rates a sound item «ممتاز جداً / ممتاز / جيد» instead of «متفوق»', () => {
    const base = { score: 0, sufficient: true, weakest: null, criteria: [] }

    expect(rating({ ...base, score: 92 }).ar).toBe('ممتاز جداً')
    expect(rating({ ...base, score: 75 }).ar).toBe('ممتاز')
    expect(rating({ ...base, score: 60 }).ar).toBe('جيد')
  })
})
