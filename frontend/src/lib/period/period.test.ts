import { describe, expect, it } from 'vitest'
import {
  addMonths, formatSpan, matchPreset, percentChange, presetPeriod, resolveComparison, riyadhToday, spanLength, weekday,
  type PresetKey,
} from './periodMath'
import {
  paramsWithPeriod, pushRecent, resolveSelection, selectionFromParams, type Selection,
} from './periodState'

/** DATE-RANGE-001 — the fixed clock is 2026-11-15 (a Sunday), Riyadh. */
const ctx = { today: '2026-11-15' }
const span = (k: PresetKey) => { const p = presetPeriod(k, ctx); return `${p.from}→${p.to}` }

describe('presets end yesterday unless their name says today', () => {
  it('last 30 days = 16 Oct → 14 Nov, the platforms\' window', () => expect(span('last_30')).toBe('2026-10-16→2026-11-14'))
  it('last 7 days = 8 → 14 Nov', () => expect(span('last_7')).toBe('2026-11-08→2026-11-14'))
  it('last 14 / 28 / 90', () => {
    expect(span('last_14')).toBe('2026-11-01→2026-11-14')
    expect(span('last_28')).toBe('2026-10-18→2026-11-14')
    expect(span('last_90')).toBe('2026-08-17→2026-11-14')
  })
  it('this month runs 1 Nov → today and is ongoing', () => {
    expect(span('this_month')).toBe('2026-11-01→2026-11-15')
    expect(presetPeriod('this_month', ctx).ongoing).toBe(true)
  })
  it('last month = the whole of October', () => expect(span('last_month')).toBe('2026-10-01→2026-10-31'))
  it('this week starts on Sunday — and 15 Nov 2026 is one', () => {
    expect(weekday('2026-11-15')).toBe(0)
    expect(span('this_week')).toBe('2026-11-15→2026-11-15')
    expect(presetPeriod('this_week', { today: '2026-11-18' }).from).toBe('2026-11-15')
  })
  it('last week is the Sunday-to-Saturday before', () => expect(span('last_week')).toBe('2026-11-08→2026-11-14'))
  it('today, yesterday, today and yesterday', () => {
    expect(span('today')).toBe('2026-11-15→2026-11-15')
    expect(span('yesterday')).toBe('2026-11-14→2026-11-14')
    expect(span('today_yesterday')).toBe('2026-11-14→2026-11-15')
  })
  it('months and year to date end yesterday', () => {
    expect(span('last_3m')).toBe('2026-08-15→2026-11-14')
    expect(span('last_6m')).toBe('2026-05-15→2026-11-14')
    expect(span('last_12m')).toBe('2025-11-15→2026-11-14')
    expect(span('ytd')).toBe('2026-01-01→2026-11-14')
  })
  it('every preset without «today»/«this» ends yesterday and is complete', () => {
    const complete: PresetKey[] = ['yesterday', 'last_7', 'last_14', 'last_28', 'last_30', 'last_90', 'last_week', 'last_month', 'last_3m', 'last_6m', 'last_12m', 'ytd', 'lifetime']
    for (const k of complete) expect(presetPeriod(k, ctx).ongoing, k).toBe(false)
    for (const k of ['today', 'today_yesterday', 'this_week', 'this_month'] as PresetKey[]) expect(presetPeriod(k, ctx).ongoing, k).toBe(true)
  })
  it('on 1 January «from the start of the year» is today, not last year', () => expect(presetPeriod('ytd', { today: '2027-01-01' })).toMatchObject({ from: '2027-01-01', to: '2027-01-01', ongoing: true }))
  it('month arithmetic clamps the day (31 May − 3 months)', () => expect(addMonths('2026-05-31', -3)).toBe('2026-02-28'))
})

describe('the clock is Riyadh, not the browser', () => {
  it('21:30 UTC is already tomorrow in Riyadh', () => expect(riyadhToday(new Date('2026-11-14T21:30:00Z'))).toBe('2026-11-15'))
  it('20:59 UTC is still today', () => expect(riyadhToday(new Date('2026-11-14T20:59:00Z'))).toBe('2026-11-14'))
})

describe('comparison', () => {
  it('previous period of «last 30 days» = 16 Sep → 15 Oct', () => {
    const p = presetPeriod('last_30', ctx)
    const c = resolveComparison(p, 'previous')!
    expect(`${c.from}→${c.to}`).toBe('2026-09-16→2026-10-15')
    expect(spanLength(c)).toBe(spanLength(p))
  })
  it('same period last year', () => expect(resolveComparison({ from: '2026-10-16', to: '2026-11-14' }, 'last_year')).toMatchObject({ from: '2025-10-16', to: '2025-11-14' }))
  it('29 Feb falls back to 28 Feb the year before', () => expect(resolveComparison({ from: '2028-02-29', to: '2028-03-02' }, 'last_year')).toMatchObject({ from: '2027-02-28', to: '2027-03-02' }))
  it('custom needs two valid dates', () => {
    expect(resolveComparison({ from: '2026-11-01', to: '2026-11-07' }, 'custom', null)).toBeNull()
    expect(resolveComparison({ from: '2026-11-01', to: '2026-11-07' }, 'custom', { from: '2026-10-01', to: '2026-10-07' })).toMatchObject({ from: '2026-10-01' })
  })
  it('percent change refuses a zero or missing baseline', () => {
    expect(percentChange(110, 100)).toBeCloseTo(10)
    expect(percentChange(5, 0)).toBeNull()
    expect(percentChange(5, null)).toBeNull()
  })
})

describe('labels', () => {
  it('states the dates in clear Arabic with Latin digits', () => expect(formatSpan({ from: '2026-10-16', to: '2026-11-14' }, true)).toBe('16 أكتوبر – 14 نوفمبر 2026'))
  it('names both years when they differ', () => expect(formatSpan({ from: '2025-12-20', to: '2026-01-10' }, true)).toBe('20 ديسمبر 2025 – 10 يناير 2026'))
})

describe('the URL carries the period', () => {
  const sel: Selection = { key: 'last_30', compare: 'previous' }

  it('writes from/to/compare and reads them back to the same preset', () => {
    const resolved = resolveSelection(sel, ctx)
    const params = paramsWithPeriod(new URLSearchParams('tab=x&days=30'), resolved, 'previous')
    expect(params.get('from')).toBe('2026-10-16')
    expect(params.get('to')).toBe('2026-11-14')
    expect(params.get('compare')).toBe('previous')
    expect(params.get('tab')).toBe('x')
    expect(params.has('days')).toBe(false)
    expect(selectionFromParams(params, ctx)).toEqual({ key: 'last_30', compare: 'previous' })
  })
  it('opens a shared custom link on the same two dates', () => {
    const got = selectionFromParams(new URLSearchParams('from=2026-09-03&to=2026-09-09&compare=custom&cfrom=2026-08-01&cto=2026-08-07'), ctx)!
    expect(got).toEqual({ key: 'custom', from: '2026-09-03', to: '2026-09-09', compare: 'custom', cfrom: '2026-08-01', cto: '2026-08-07' })
    expect(resolveSelection(got, ctx).comparison).toMatchObject({ from: '2026-08-01', to: '2026-08-07' })
  })
  it('no period in the address means the stored one applies', () => expect(selectionFromParams(new URLSearchParams('tab=x'), ctx)).toBeNull())
  it('garbage dates are not a period', () => expect(selectionFromParams(new URLSearchParams('from=2026-13-40&to=nope'), ctx)).toBeNull())
  it('an old ?days=30 link opens the last-30 preset (ending yesterday)', () => expect(selectionFromParams(new URLSearchParams('days=30'), ctx)).toEqual({ key: 'last_30', compare: 'none' }))
  it('a swapped range is put the right way round', () => expect(selectionFromParams(new URLSearchParams('from=2026-11-14&to=2026-11-08'), ctx)).toEqual({ key: 'last_7', compare: 'none' }))
  it('infers the label from the dates', () => expect(matchPreset({ from: '2026-10-01', to: '2026-10-31' }, ctx)).toBe('last_month'))
})

describe('recently used', () => {
  it('keeps the last three, newest first, without repeats', () => {
    let r: Selection[] = []
    for (const k of ['last_7', 'last_30', 'yesterday', 'last_7', 'this_month'] as const) r = pushRecent(r, { key: k, compare: 'none' })
    expect(r.map((s) => s.key)).toEqual(['this_month', 'last_7', 'yesterday'])
  })
})
