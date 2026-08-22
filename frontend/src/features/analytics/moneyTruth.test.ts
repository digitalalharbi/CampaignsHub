import { describe, expect, it } from 'vitest'
import { moneyFromTotals } from './format'
import { readMetric } from './metricCatalog'

/**
 * MONEY-TRUTH-001 — the same scope must not read differently on two screens.
 *
 * The dashboard read spend through `readMetric()` and Analytics through `money(raw)`. For the same
 * account and window one showed 4,128.93 USD and the other «0 SAR», because the aggregator coalesces
 * a withheld sum to 0 and a bare number cannot carry the difference.
 */
const WITHHELD = {
  spend: 0,
  spend_original: 4128.93,
  spend_withheld_rows: 262,
  revenue: 0,
  revenue_original: 12969.03,
  revenue_withheld_rows: 262,
  money_original_currency: 'USD',
  money_original_currencies: 1,
}

const spendSpec = { format: (v: number) => `${v} SAR` } as never

describe('money truth', () => {
  it('analytics and the dashboard agree on withheld spend', () => {
    const analytics = moneyFromTotals(WITHHELD, 'spend', false)
    const dashboard = readMetric('spend', spendSpec, WITHHELD as never, undefined)

    expect(analytics.text).toBe('4,128.93 USD')
    expect(dashboard).toEqual({ kind: 'withheld', original: '4,128.93 USD' })
    // The figure a reader sees is identical on both surfaces.
    expect(analytics.text).toBe((dashboard as { original: string }).original)
  })

  it('never presents withheld money as 0', () => {
    const r = moneyFromTotals(WITHHELD, 'spend', true)

    expect(r.text).not.toBe('0 SAR')
    expect(r.text).not.toMatch(/^0\b/)
    expect(r.withheld).toBe(true)
    expect(r.note).toMatch(/التحويل/)
  })

  it('refuses to sum several unconvertible currencies into one number', () => {
    const r = moneyFromTotals({ ...WITHHELD, money_original_currencies: 2 }, 'spend', false)

    // 4,128.93 is a sum across currencies — a quantity of nothing. It must not be printed.
    expect(r.text).toBe('—')
    expect(r.text).not.toContain('4,128')
    expect(r.note).toMatch(/several currencies/)
  })

  it('a genuinely measured zero stays zero', () => {
    const r = moneyFromTotals({ spend: 0, spend_withheld_rows: 0, spend_original: 0 }, 'spend', false)

    expect(r.text).toBe('0 SAR')
    expect(r.withheld).toBe(false)
  })

  it('a converted figure is shown in the project currency', () => {
    const r = moneyFromTotals({ spend: 15480.5, spend_withheld_rows: 0 }, 'spend', false)

    expect(r.text).toContain('SAR')
    expect(r.withheld).toBe(false)
  })

  it('nothing reported is not zero', () => {
    const r = moneyFromTotals({ spend: null, spend_withheld_rows: 0 }, 'spend', false)

    expect(r.text).toBe('—')
  })
})

import { readCostPer, readMoney, readRoas } from '@/lib/money/contract'

/**
 * The derived money metrics, and the chart, obey the same provenance as the raw totals.
 */
describe('derived money and timeseries provenance', () => {
  const withheld = { ...WITHHELD, conversions: 102 }

  it('CPA is never derived as 0 from a withheld numerator', () => {
    const r = readCostPer(withheld, 'cpa', 'conversions', 'SAR', false)

    expect(r.kind).toBe('withheld')
    expect(r.amount).toBeCloseTo(4128.93 / 102, 4)
    expect(r.currency).toBe('USD')
    expect(r.amount).not.toBe(0)
  })

  it('CPA is unavailable rather than infinite when there is no denominator', () => {
    const r = readCostPer({ ...WITHHELD, conversions: 0 }, 'cpa', 'conversions', 'SAR', false)

    expect(r.kind).toBe('unavailable')
    expect(r.amount).toBeNull()
  })

  it('ROAS survives a missing rate when both sides share one currency', () => {
    const r = readRoas(WITHHELD, false)

    // 12,969.03 / 4,128.93 — the ratio is identical before and after conversion.
    expect(r.kind).toBe('withheld')
    expect(r.value).toBeCloseTo(12969.03 / 4128.93, 4)
  })

  it('ROAS refuses to divide unlike currencies', () => {
    const r = readRoas({ ...WITHHELD, money_original_currencies: 2 }, false)

    expect(r.kind).toBe('unavailable')
    expect(r.value).toBeNull()
  })

  /**
   * The card and the chart read the SAME provenance, so one cannot show a figure while the other
   * draws a zero line beneath it.
   */
  it('card and chart agree about whether spend exists', () => {
    const card = readMoney(WITHHELD, 'spend', 'SAR', false)
    const plottable = card.kind !== 'withheld' && card.kind !== 'unavailable'

    expect(card.kind).toBe('withheld')
    expect(plottable).toBe(false)

    const measured = readMoney({ spend: 1500, spend_withheld_rows: 0 }, 'spend', 'SAR', false)
    expect(measured.kind).toBe('converted')
    expect(measured.kind !== 'withheld' && measured.kind !== 'unavailable').toBe(true)
  })

  it('does not assume a reporting currency', () => {
    const r = readMoney({ spend: 900, spend_withheld_rows: 0 }, 'spend', 'AED', false)

    expect(r.currency).toBe('AED')
  })
})
