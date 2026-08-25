import { describe, expect, it } from 'vitest'

import { plotSeries, type SeriesRow } from './timeseriesMoney'

/** A day whose money the platform reported and we could not convert — production's actual shape. */
const withheldDay = (date: string, spend: number, revenue: number, conversions: number): SeriesRow => ({
  date,
  spend: 0,
  revenue: 0,
  conversions,
  clicks: 100,
  impressions: 10_000,
  spend_withheld_rows: 3,
  spend_original: spend,
  revenue_withheld_rows: 3,
  revenue_original: revenue,
  money_original_currency: 'USD',
  money_original_currencies: 1,
})

describe('the daily series a chart is allowed to draw', () => {
  it('plots the platform original rather than the coalesced zero the aggregator sends', () => {
    const s = plotSeries([withheldDay('2026-08-11', 300, 900, 12)], 'SAR', true)

    expect(s.rows[0].spend).toBe(300)
    expect(s.rows[0].revenue).toBe(900)
    expect(s.basis).toBe('original')
    expect(s.currency).toBe('USD')
    expect(s.note).not.toBeNull()
  })

  it('derives ROAS and CPA from the originals, so the line agrees with the card above it', () => {
    const s = plotSeries([withheldDay('2026-08-11', 300, 960, 12)], 'SAR', true)

    expect(s.rows[0].roas).toBeCloseTo(3.2, 5)
    expect(s.rows[0].cpa).toBeCloseTo(25, 5)
  })

  it('recomputes CTR from the counts on the same row', () => {
    const s = plotSeries([withheldDay('2026-08-11', 300, 900, 12)], 'SAR', true)

    expect(s.rows[0].ctr).toBeCloseTo(1, 5) // 100 / 10,000
  })

  it('refuses a single axis when the days are denominated differently', () => {
    const usd = withheldDay('2026-08-11', 300, 900, 12)
    const eur = { ...withheldDay('2026-08-12', 300, 900, 12), money_original_currency: 'EUR' }

    const s = plotSeries([usd, eur], 'SAR', true)

    expect(s.basis).toBe('mixed')
    expect(s.currency).toBeNull()
    expect(s.note).not.toBeNull()
  })

  it('reports that a window carries no money at all rather than drawing it flat', () => {
    const s = plotSeries([{ date: '2026-08-11', spend: null, revenue: null, conversions: 4, clicks: 10, impressions: 1000 }], 'SAR', true)

    expect(s.hasMoney).toBe(false)
    expect(s.basis).toBe('none')
    expect(s.rows[0].spend).toBeNull()
  })

  it('keeps a converted figure in the reporting currency', () => {
    const s = plotSeries([{ date: '2026-08-11', spend: 500, revenue: 1500, roas: 3, conversions: 10, clicks: 50, impressions: 5000 }], 'SAR', true)

    expect(s.basis).toBe('converted')
    expect(s.currency).toBe('SAR')
    expect(s.rows[0].spend).toBe(500)
    expect(s.note).toBeNull()
  })

  it('states a measured zero as zero, which is not the same as an absence', () => {
    const s = plotSeries([{ date: '2026-08-11', spend: 0, revenue: 0, conversions: 0, clicks: 0, impressions: 0 }], 'SAR', true)

    expect(s.rows[0].spend).toBe(0)
    expect(s.hasMoney).toBe(true)
    expect(s.rows[0].ctr).toBeNull() // no impressions — not a zero rate
  })
})
