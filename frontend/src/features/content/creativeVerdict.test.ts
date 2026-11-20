import { describe, expect, it } from 'vitest'
import type { CreativeDetail } from './api'
import { aspectOf } from './CreativeDetailPage'
import { betterGap, readCreative, readingCsv } from './creativeVerdict'

/*
 * CONTENT-WHY-DETAIL-001 — the reading of a real ad: TikTok, sales path, production figures read 19 Nov 2026 of
 * «21 معمول — بنت النور» (spend 956.96, 97 orders, revenue 6,166.10, 498,856 impressions, 2,494 clicks, 486,153 views,
 * 972 completions), its 14 days as served, against a POOLED path average.
 */
const trend = [
  ['2026-11-06', 23.22, 11153, 86, 8, 595.58], ['2026-11-07', 148.01, 88534, 457, 18, 1162.7], ['2026-11-08', 127.62, 87441, 403, 12, 652.29],
  ['2026-11-09', 14.18, 4894, 30, 1, 78.86], ['2026-11-10', 174.4, 76807, 302, 15, 872.22], ['2026-11-11', 211.97, 110524, 500, 18, 1087.12],
  ['2026-11-12', 111.59, 46246, 199, 12, 984.12], ['2026-11-13', 0.18, 72, 0, 0, 0], ['2026-11-14', 0, 1, 0, 0, 0], ['2026-11-15', 0, 0, 0, 0, 0],
  ['2026-11-16', 0, 1, 0, 0, 0], ['2026-11-17', 0, 0, 0, 0, 0], ['2026-11-18', 83.53, 32186, 275, 7, 445.9], ['2026-11-19', 62.26, 40997, 242, 6, 287.31],
].map(([date, spend, impressions, clicks, conversions, revenue]) => ({ date, spend, impressions, clicks, conversions, revenue }))

const detail = (over: Partial<CreativeDetail> = {}): CreativeDetail => ({
  creative: {
    id: 'c1', name: '21 معمول -بنت النور_9iCHlYhN.MOV_5Nov 2026ــ Maamoul 21 ــ UGC Bint Alnoor', provider: 'tiktok', format: 'video', kind: 'video',
    campaign_name: '5Nov 2026ــ Karam Alnakhil Sales ــ Maamoul', duration_seconds: 43, dimensions: { width: 1080, height: 1920, aspect_ratio: null, file_size: null },
  } as unknown as CreativeDetail['creative'],
  period: { from: '2026-10-21', to: '2026-11-19', days: 30 },
  previous_period: { from: '2026-09-21', to: '2026-10-20' },
  metrics: {
    spend: 956.96, impressions: 498856, clicks: 2494, conversions: 97, orders: 97, revenue: 6166.1, video_views: 486153, video_completions: 972,
    ctr: 0.005, cpc: 0.3837, cpm: 1.9183, cpa: 9.8656, roas: 6.4434, conversion_rate: 0.0389, aov: 63.568, view_rate: 0.9745, completion_rate: 0.002,
  } as unknown as CreativeDetail['metrics'],
  previous: null,
  headline_metrics: [],
  path: 'conversion',
  peers: { count: 106, ctr: 0.009, cpc: 0.31, cpm: 2.8, cpa: 8.5, roas: 5.0, view_rate: 0.6, completion_rate: 0.01 },
  trend,
  currency: 'USD',
  ...over,
} as CreativeDetail)

describe('readCreative — why it worked, why it did not, from real figures only', () => {
  it('judges on the path’s own result: ROAS 6.44× against a pooled 5.00× is a win, and says so with both figures', () => {
    const r = readCreative(detail(), true)
    expect(r.verdict).toBe('win')
    expect(r.verdictLabel).toBe('ناجح')
    expect(r.headline).toContain('6.44')
    expect(r.basis).toContain('106')
    expect(r.strengths.map((l) => l.id)).toContain('cmp-roas')
    expect(r.weaknesses.map((l) => l.id)).toEqual(expect.arrayContaining(['cmp-ctr', 'cmp-cpa', 'cmp-cpc']))
  })

  it('explains the combination: seen but not clicked, few clicks yet buyers, cheap reach, and the order value', () => {
    const ids = readCreative(detail(), true).diagnosis.map((l) => l.id)
    expect(ids).toEqual(expect.arrayContaining(['seen-not-clicked', 'few-clicks-buyers', 'cheap-reach', 'aov']))
  })

  it('reads the creative: 9:16 fits TikTok, 0.2% watched to the end of 43 seconds, long for the platform, UGC by its name', () => {
    const r = readCreative(detail(), true)
    const byId = Object.fromEntries(r.creative.map((l) => [l.id, l]))
    expect(byId.shape.tone).toBe('good')
    expect(byId.completion.tone).toBe('bad')
    expect(byId.completion.text).toContain('0.2%')
    expect(byId.length.text).toContain('43')
    expect(byId.ugc).toBeDefined()
  })

  it('finds the five days delivery stopped (13–17 Nov) and the best day by revenue (7 Nov)', () => {
    const r = readCreative(detail(), false)
    const gap = r.timeline.find((l) => l.id === 'gap-2026-11-13')
    expect(gap?.text).toMatch(/Delivery stopped for 5 days \(13 November – 17 November\)/)
    expect(r.timeline.find((l) => l.id === 'best-day')?.text).toContain('7 November')
  })

  it('a profitable sale below its path is «متوسط», never «ضعيف»; a sale under 1× is weak whatever the path did', () => {
    expect(readCreative(detail({ peers: { count: 50, roas: 10, cpa: 5, ctr: 0.01, cpc: 0.3, cpm: 2 } }), true).verdict).toBe('par')
    const losing = detail({ metrics: { spend: 500, impressions: 100000, clicks: 500, revenue: 400, conversions: 5, roas: 0.8, ctr: 0.005 } as unknown as CreativeDetail['metrics'] })
    expect(readCreative(losing, true).verdict).toBe('lose')
  })

  it('writes nothing it cannot back: too few impressions or peers is «لا يكفي للحكم», and an absent figure writes no line', () => {
    expect(readCreative(detail({ metrics: { spend: 3, impressions: 400, roas: 2, revenue: 6 } as unknown as CreativeDetail['metrics'] }), true).verdict).toBe('insufficient')
    expect(readCreative(detail({ peers: { count: 2, roas: 5 } }), true).verdict).toBe('insufficient')
    const bare = readCreative(detail({ peers: null }), true)
    expect(bare.comparisons).toEqual([])
    expect(bare.strengths).toEqual([])
  })

  it('a lower-is-better metric reads the right way round', () => {
    expect(betterGap('cpa', 8, 10)).toBeCloseTo(0.2)
    expect(betterGap('roas', 6, 5)).toBeCloseTo(0.2)
  })

  it('downloads as a CSV Excel opens with Arabic intact', () => {
    const csv = readingCsv(detail(), readCreative(detail(), true), true)
    expect(csv.startsWith('﻿')).toBe(true)
    expect(csv).toContain('"نقاط القوة"')
    // PERIOD-LABEL-001 — the exported period reads like every other period in the product, not as two ISO dates.
    expect(csv).toContain('"21 أكتوبر – 19 نوفمبر 2026"')
  })

  it('names the ratio from the pixels when the platform did not', () => {
    expect(aspectOf(1080, 1920)).toBe('9:16')
    expect(aspectOf(1080, 1080)).toBe('1:1')
    expect(aspectOf(null, 1920)).toBeNull()
  })
})
