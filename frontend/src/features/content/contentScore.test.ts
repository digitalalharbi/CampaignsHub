import { describe, expect, it } from 'vitest'
import { diagnosis, monotoneSteps, scoreContent } from './contentScore'

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
    expect(diagnosis(s, true)).toBe('يفقد المشاهد قبل الرسالة')
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
})
