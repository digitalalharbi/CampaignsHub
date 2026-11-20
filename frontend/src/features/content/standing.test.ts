import { describe, expect, it } from 'vitest'
import { classLabel, compareOnObjective, indicatorOf, rankLabel, standingQuery, whyById, whyReading, whySentence, type StandingItem } from './standing'

/**
 * CONTENT-CARDS-001 — the logic behind the library's cards: the class, the position, the objective's own indicator, the
 * shared-objective comparison and the filters that decide WHICH content is ranked together. None of it ranks anything: the
 * server did, with the report's own ranker, so these pin only how that answer is read and worded.
 */
const item = (over: Partial<StandingItem> = {}): StandingItem => ({
  id: 'a', objective: 'sales', provider: 'meta', family: 'sales', metric: 'roas', metric_label_ar: 'العائد', metric_label_en: 'ROAS', higher_is_better: true,
  rank: 2, of: 12, class: null,
  result: { key: 'conversions', value: 40, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: null, missing_en: null },
  ...over,
})

describe('class and position', () => {
  it('words the two classes the report uses, and nothing for content in neither', () => {
    expect(classLabel('best', true)).toBe('الأفضل أداءً')
    expect(classLabel('improve', true)).toBe('فرصة للتحسين')
    expect(classLabel('best', false)).toBe('Best performing')
    expect(classLabel(null, true)).toBeNull()
  })

  it('states the position among content of the same objective, in Latin digits', () => {
    expect(rankLabel(item(), true)).toBe('2 من 12')
    expect(rankLabel(item(), false)).toBe('2 of 12')
    expect(rankLabel(item({ rank: null }), true)).toBeNull()
    expect(rankLabel(undefined, true)).toBeNull()
  })
})

describe('the objective indicator', () => {
  it('is the objective’s own result: orders for sales, leads for leads, visits for traffic', () => {
    expect(indicatorOf(item(), true)).toEqual({ label: 'الطلبات', text: '40', measured: true })
    expect(indicatorOf(item({ family: 'leads', result: { key: 'leads', value: 7, label_ar: 'المهتمين', label_en: 'Leads', missing_ar: null, missing_en: null } }), false)).toEqual({ label: 'Leads', text: '7', measured: true })
    expect(indicatorOf(item({ family: 'traffic', result: { key: 'landing_page_views', value: 1234, label_ar: 'زيارات الصفحة', label_en: 'Landing page views', missing_ar: null, missing_en: null } }), false)?.text).toBe('1,234')
  })

  it('is «غير مُقاس» — never a zero — when nothing was reported', () => {
    const none = item({ result: { key: 'conversions', value: null, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: 'بدون بيانات', missing_en: 'No data' } })
    expect(indicatorOf(none, true)).toEqual({ label: 'الطلبات', text: 'غير مُقاس', measured: false })
    expect(indicatorOf(none, false)?.text).toBe('Not measured')
    // A measured zero is a figure.
    expect(indicatorOf(item({ result: { key: 'conversions', value: 0, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: null, missing_en: null } }), true)?.text).toBe('0')
    // An objective nobody set has no result at all.
    expect(indicatorOf(item({ family: 'unknown', result: { key: null, value: null, label_ar: null, label_en: null, missing_ar: 'الهدف غير محدد', missing_en: 'Objective not set' } }), true)?.measured).toBe(false)
  })
})

describe('comparing content with content', () => {
  it('compares on the shared indicator against the first item, with the percent change', () => {
    const cmp = compareOnObjective([item({ id: 'a' }), item({ id: 'b', result: { ...item().result, value: 60 } }), item({ id: 'c', result: { ...item().result, value: 30 } })])

    expect(cmp.comparable).toBe(true)
    expect(cmp.resultKey).toBe('conversions')
    expect(cmp.columns.map((c) => [c.id, c.isReference, c.delta])).toEqual([['a', true, null], ['b', false, 0.5], ['c', false, -0.25]])
  })

  it('refuses to rank across objectives — it says so and produces no delta', () => {
    const cmp = compareOnObjective([item({ id: 'a' }), item({ id: 'b', family: 'leads', result: { key: 'leads', value: 9, label_ar: 'المهتمين', label_en: 'Leads', missing_ar: null, missing_en: null } })])

    expect(cmp.comparable).toBe(false)
    expect(cmp.reason).toBe('objective_mismatch')
    expect(cmp.columns.every((c) => c.delta === null)).toBe(true)
  })

  it('refuses the same objective measured in two units, and an objective nobody set', () => {
    const lpv = { key: 'landing_page_views', value: 100, label_ar: 'زيارات الصفحة', label_en: 'Landing page views', missing_ar: null, missing_en: null }
    const clicks = { key: 'clicks', value: 900, label_ar: 'النقرات', label_en: 'Clicks', missing_ar: null, missing_en: null }
    expect(compareOnObjective([item({ family: 'traffic', result: lpv }), item({ id: 'b', family: 'traffic', result: clicks })]).reason).toBe('unit_mismatch')
    expect(compareOnObjective([item({ family: 'unknown' }), item({ id: 'b', family: 'unknown' })]).reason).toBe('objective_mismatch')
  })

  it('has no delta against a reference that was not measured or is zero', () => {
    const zero = compareOnObjective([item({ result: { ...item().result, value: 0 } }), item({ id: 'b' })])
    expect(zero.comparable).toBe(true)
    expect(zero.columns[1].delta).toBeNull()
    expect(compareOnObjective([item({ result: { ...item().result, value: null } }), item({ id: 'b', result: { ...item().result, value: null } })]).reason).toBe('not_measured')
  })
})

describe('which content is ranked together', () => {
  it('keys the ranking on the scope, never on the page, the sort, the search or the health filter', () => {
    const q = standingQuery({ from: '2026-11-01', to: '2026-11-07', page: 3, per_page: 24, sort: 'spend', search: 'x', health: 'fatigued', providers: ['meta'], kinds: ['text'], campaign_ids: ['c1'], objectives: ['sales'] })

    expect(q).toEqual({ from: '2026-11-01', to: '2026-11-07', providers: ['meta'], kinds: ['text'], campaign_ids: ['c1'], objectives: ['sales'] })
  })
})

describe('«ليش نجح / ليش ما نجح» — the one contentWhy, over the whole scope', () => {
  const row = (id: string, clicks: number, spend: number) => ({
    id, name: id, provider: 'meta', objective: 'sales', metrics: { impressions: 10000, clicks, spend, frequency: 2.4 },
    fatigue: { status: 'watch' as const, signals: [{ metric: 'ctr', direction: 'down', change: -0.2 }] },
  })

  it('reads the gap against the same objective + platform average, the spend share and the fatigue signals', () => {
    const rows = [row('a', 300, 100), row('b', 100, 100), row('c', 200, 200)]
    const why = whyById(rows)
    const a = whyReading(why.get('a'))

    // CTRs 3%, 1%, 2% → average 2%; a is 50% above it and carried 25% of the group's spend.
    expect(a).toMatchObject({ verdict: 'above', gapPercent: 50, sharePercent: 25, frequency: 2.4, ctrChangePercent: -20, fatigue: 'watch', groupSize: 3 })
    expect(whyReading(why.get('b'))).toMatchObject({ verdict: 'below', gapPercent: -50 })
    expect(whySentence(a, true)).toBe('ليش نجح: نسبة الضغط أعلى من المتوسط بـ 50%.')
    expect(whySentence(whyReading(why.get('b')), true)).toBe('ليش ما نجح: نسبة الضغط أقل من المتوسط بـ 50%.')
    expect(whySentence(a, false)).toBe('Why it worked: click rate is 50% above the average.')
  })

  it('is «غير مُقاس» for a group too small to compare — never a verdict from two points', () => {
    const why = whyById([row('a', 300, 100), row('b', 100, 100)])

    expect(whyReading(why.get('a'))).toBeNull()
    expect(whySentence(null, true)).toContain('غير مُقاس')
  })
})
