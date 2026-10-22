import { describe, expect, it } from 'vitest'
import { sparkFor, sparkPoints } from './metricCatalog'
import type { TimePoint } from './api'

/**
 * REPORT-CHARTS-COMPLETE-001 — one sparkline rule, two readers.
 *
 * `sparkFor` hands a card the measured points only; `sparkPoints` keeps the holes for a chart with an
 * x-axis, so an unreported day is a break in the line and never a zero. Both apply the same
 * thresholds, which is the point: a card and the chart beside it cannot disagree about whether a
 * series is drawable.
 */
const rows = (values: Array<number | null | undefined>): TimePoint[] =>
  values.map((v, i) => ({ date: `2026-07-${String(i + 1).padStart(2, '0')}`, ...(v === undefined ? {} : { spend: v }) }) as unknown as TimePoint)

describe('sparkPoints keeps the holes sparkFor leaves out', () => {
  it('keeps an unreported day as null while sparkFor drops it', () => {
    const series = rows([150, undefined, 200])
    expect(sparkPoints('spend', series, undefined)).toEqual([150, null, 200])
    expect(sparkFor('spend', series, undefined)).toEqual([150, 200])
  })

  it('applies the same refusals: fewer than two points, a flat line, more than a third missing, a metric not sent', () => {
    expect(sparkPoints('spend', rows([150]), undefined)).toBeUndefined()
    expect(sparkPoints('spend', rows([150, 150, 150]), undefined)).toBeUndefined()
    expect(sparkPoints('spend', rows([150, undefined, undefined, 90]), undefined)).toBeUndefined()
    expect(sparkPoints('spend', rows([150, 200]), { spend: false })).toBeUndefined()
    expect(sparkFor('spend', rows([150, 200]), { spend: false })).toBeUndefined()
  })

  it('treats a non-finite or non-numeric value as unreported, not as zero', () => {
    const series = rows([150, Number.NaN as unknown as number, 200])
    expect(sparkPoints('spend', series, undefined)).toEqual([150, null, 200])
  })
})
