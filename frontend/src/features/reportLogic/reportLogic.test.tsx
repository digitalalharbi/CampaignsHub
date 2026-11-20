import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import {
  basisLine, boardKpis, compareFigure, compareTotals, comparisonTone, contentLists, dailyPoints, funnelHasShape, funnelStages, headlineKeys,
  overlayDaily, platformCards, rankPlatforms, resultSource, resultsSplit, rightAxisFor, splitTitle, toggleTrend, trendOptions,
} from '@/features/reportLogic'
import { REPORT_FIXTURE as P } from './testFixture'

const reader = () => renderHook(() => useLiveMetricReader('SAR', false)).result.current

describe('reportLogic — the report headline and its source', () => {
  it('judges a payload with no defined Results on delivery, never on the platform\'s generic conversion', () => {
    expect(headlineKeys(P, reader())).toEqual({ result: 'clicks', cost: 'cpc' })
  })

  it('judges a payload with Results on its own result and cost', () => {
    const configured = { ...P, results: { ...P.results, configured: true, total: 40, by_platform: { meta: { results: 28, metric: 'purchases' }, snapchat: { results: 12, metric: 'purchases' } } } } as typeof P
    expect(headlineKeys(configured, reader())).toEqual({ result: 'conversions', cost: 'cpa' })
    const source = resultSource(configured, reader(), 'conversions', false)
    expect(source.key).toBe('results')
    expect(source.total).toBe(40)
    expect(source.of(configured.platforms[0])).toBe(28)
  })
})

describe('reportLogic — platform cards', () => {
  it('leads with «all platforms», lists only platforms that delivered, and shares add up to one', () => {
    const source = resultSource(P, reader(), 'purchases', false)
    const cards = platformCards(P, source, false)
    expect(cards.map((c) => c.key)).toEqual([null, 'meta', 'snapchat'])
    expect(cards[0]).toMatchObject({ title: 'All platforms', value: 40, impressions: 29_000, share: 1 })
    expect(cards[1].share! + cards[2].share!).toBeCloseTo(1, 10)
    expect(cards[1].rate).toBe('2.00%')
  })

  it('gives no «all platforms» card for one platform, and nothing for none', () => {
    const source = resultSource(P, reader(), 'purchases', false)
    expect(platformCards({ ...P, platforms: [P.platforms[0]] }, source, true).map((c) => c.key)).toEqual(['meta'])
    expect(platformCards({ ...P, platforms: [] }, source, true)).toEqual([])
  })
})

describe('reportLogic — sources donut', () => {
  it('splits the result by platform, largest first, shares summing to 1', () => {
    const split = resultsSplit(P, resultSource(P, reader(), 'purchases', false), false)
    expect(split.total).toBe(40)
    expect(split.rows.map((r) => [r.key, r.value])).toEqual([['meta', 28], ['snapchat', 12]])
    expect(split.rows.reduce((s, r) => s + r.share, 0)).toBeCloseTo(1, 10)
  })

  it('names the panel after what is counted', () => {
    expect(splitTitle(true, 'Clicks', true)).toBe('مصادر الزيارات')
    expect(splitTitle(false, 'Orders', false)).toBe('Sources of orders')
  })
})

describe('reportLogic — platform ranking with sort', () => {
  const source = () => resultSource(P, reader(), 'purchases', false)

  it('orders by result descending by default and ascending on request', () => {
    expect(rankPlatforms(P, source(), false).map((r) => r.key)).toEqual(['meta', 'snapchat'])
    expect(rankPlatforms(P, source(), false, 'result', 'asc').map((r) => r.key)).toEqual(['snapchat', 'meta'])
  })

  it('sorts by clicks, impressions and CTR, and by name', () => {
    expect(rankPlatforms(P, source(), false, 'clicks').map((r) => r.clicks)).toEqual([400, 150])
    expect(rankPlatforms(P, source(), false, 'impressions', 'asc').map((r) => r.key)).toEqual(['snapchat', 'meta'])
    expect(rankPlatforms(P, source(), false, 'ctr').map((r) => r.key)).toEqual(['meta', 'snapchat'])
    expect(rankPlatforms(P, source(), false, 'name', 'asc').map((r) => r.name)).toEqual(['Meta', 'Snapchat'])
  })

  it('puts a missing figure last whichever way the column runs, and scales bars to the best', () => {
    const noCtr = { ...P, platforms: [P.platforms[0], { ...P.platforms[1], ctr: null }] } as typeof P
    expect(rankPlatforms(noCtr, source(), false, 'ctr', 'desc').map((r) => r.key)).toEqual(['meta', 'snapchat'])
    expect(rankPlatforms(noCtr, source(), false, 'ctr', 'asc').map((r) => r.key)).toEqual(['meta', 'snapchat'])
    const rows = rankPlatforms(P, source(), false)
    expect(rows[0].bar).toBe(1)
    expect(rows[1].bar).toBeCloseTo(12 / 28, 10)
    expect(rows[0].movement).toBe(0.25)
    expect(rows[1].movement).toBeNull()
  })
})

describe('reportLogic — daily series and its metric switch', () => {
  it('offers the result first, then clicks and impressions', () => {
    const source = resultSource(P, reader(), 'purchases', false)
    expect(trendOptions(P, reader(), source, false).map((o) => o.key)).toEqual(['purchases', 'clicks', 'impressions'])
  })

  it('never offers the same metric twice, and keeps one metric on at least', () => {
    const clicks = resultSource({ ...P, totals: { ...P.totals, purchases: 0 } } as typeof P, reader(), 'clicks', false)
    expect(trendOptions(P, reader(), clicks, false).map((o) => o.key)).toEqual(['clicks', 'impressions'])
    expect(toggleTrend(['clicks'], 'clicks')).toEqual(['clicks'])
    expect(toggleTrend(['clicks'], 'impressions')).toEqual(['clicks', 'impressions'])
    expect(toggleTrend(['clicks', 'impressions'], 'clicks')).toEqual(['impressions'])
  })

  it('puts the larger scale on the second axis only when two are drawn', () => {
    expect(rightAxisFor(['purchases'])).toBeUndefined()
    expect(rightAxisFor(['purchases', 'clicks'])).toBe('clicks')
    expect(rightAxisFor(['purchases', 'clicks', 'impressions'])).toBe('impressions')
  })

  it('reads one metric\'s days as numbers, a missing day as null and never zero', () => {
    const series = { ...P, timeseries: [{ date: 'a', clicks: 3 }, { date: 'b' }] } as typeof P
    expect(dailyPoints(series, 'clicks')).toEqual([{ date: 'a', value: 3 }, { date: 'b', value: null }])
  })
})

describe('reportLogic — best and opportunity content', () => {
  it('keeps the server\'s order, never lists a best one as an opportunity, and narrows to a platform', () => {
    expect(contentLists(P, 'best', null).map((a) => a.content_key)).toEqual(['a1', 'a2'])
    expect(contentLists(P, 'improve', null).map((a) => a.content_key)).toEqual(['w1', 'w2'])
    expect(contentLists(P, 'improve', 'snapchat').map((a) => a.content_key)).toEqual(['w2'])
    expect(contentLists(P, 'best', 'tiktok')).toEqual([])
  })

  it('states what the order rests on', () => {
    expect(basisLine(P.ads_basis, true)).toBe('مرتّبة على أداء المبيعات')
    expect(basisLine(P.ads_basis, false)).toBe('Ranked by sales performance')
    expect(basisLine(undefined, true)).toBeNull()
  })
})

describe('reportLogic — funnel', () => {
  it('drops unreported stages and strips costs', () => {
    expect(funnelStages(P).map((s) => s.stage)).toEqual(['impressions', 'clicks', 'purchases'])
    expect(funnelStages(P).every((s) => s.cost_per === null)).toBe(true)
    expect(funnelHasShape(P)).toBe(true)
    expect(funnelHasShape({ ...P, funnel: [P.funnel![0]] } as typeof P)).toBe(false)
  })
})

describe('reportLogic — key indicators', () => {
  it('reads spend, clicks, orders, revenue and return through the report\'s own catalogue', () => {
    const kpis = boardKpis(P, reader(), false)
    expect(kpis.map((k) => k.key)).toEqual(['spend', 'clicks', 'purchases', 'revenue', 'roas'])
    expect(kpis.find((k) => k.key === 'purchases')?.value).toBe('40')
    expect(kpis.find((k) => k.key === 'spend')?.delta).toBe(0.1)
  })

  it('leaves revenue and return out when no platform reported revenue', () => {
    const noRevenue = { ...P, totals: { ...P.totals, revenue: 0, roas: null } } as typeof P
    expect(boardKpis(noRevenue, reader(), false).map((k) => k.key)).toEqual(['spend', 'clicks', 'purchases'])
  })

  it('withholds the movement when the two windows may not be compared', () => {
    const withheld = { ...P, comparison: { comparable: false, window: 'previous', contributors: [], through: null } } as typeof P
    expect(boardKpis(withheld, reader(), false).every((k) => k.delta === undefined)).toBe(true)
  })
})

describe('reportLogic — comparison', () => {
  it('states the difference and its size against the baseline', () => {
    expect(compareFigure(120, 100)).toEqual({ a: 120, b: 100, diff: 20, pct: 0.2, direction: 'up' })
    expect(compareFigure(80, 100)).toMatchObject({ diff: -20, pct: -0.2, direction: 'down' })
    expect(compareFigure(100, 100)).toMatchObject({ diff: 0, direction: 'flat' })
  })

  it('never invents a number: a missing side is unavailable and a zero baseline has no percentage', () => {
    expect(compareFigure(null, 100)).toMatchObject({ diff: null, pct: null, direction: 'na' })
    expect(compareFigure(5, undefined)).toMatchObject({ direction: 'na' })
    expect(compareFigure('7', 5)).toMatchObject({ direction: 'na' })
    expect(compareFigure(5, 0)).toMatchObject({ diff: 5, pct: null, direction: 'up' })
  })

  it('reads «good» by the metric: less cost per result is good', () => {
    expect(comparisonTone(compareFigure(10, 20), true)).toBe('good')
    expect(comparisonTone(compareFigure(30, 20), true)).toBe('bad')
    expect(comparisonTone(compareFigure(30, 20), false)).toBe('good')
    expect(comparisonTone(compareFigure(null, 20), false)).toBe('neutral')
  })

  it('compares totals key by key and overlays days by day number with gaps left null', () => {
    const rows = compareTotals({ label: 'A', totals: { spend: 10, clicks: null }, daily: [] }, { label: 'B', totals: null, daily: [] }, ['spend', 'clicks'])
    expect(rows.map((r) => [r.key, r.direction])).toEqual([['spend', 'na'], ['clicks', 'na']])
    expect(overlayDaily([{ clicks: 1 }, { clicks: 2 }], [{ clicks: 5 }], 'clicks')).toEqual([{ day: 1, a: 1, b: 5 }, { day: 2, a: 2, b: null }])
  })
})
