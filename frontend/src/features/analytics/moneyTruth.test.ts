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
    const r = moneyFromTotals({ spend: 0, spend_withheld_rows: 0, spend_original: 0 }, 'spend', false, 'SAR')

    expect(r.text).toBe('0 SAR')
    expect(r.withheld).toBe(false)
  })

  it('a converted figure is shown in the currency the scope states', () => {
    const sar = moneyFromTotals({ spend: 15480.5, spend_withheld_rows: 0 }, 'spend', false, 'SAR')
    expect(sar.text).toContain('SAR')
    expect(sar.withheld).toBe(false)

    // The same figure in a USD scope is USD. It used to be «SAR» either way.
    expect(moneyFromTotals({ spend: 15480.5, spend_withheld_rows: 0 }, 'spend', false, 'USD').text).toContain('USD')
  })

  /**
   * MONEY-USD-001 — a scope that states no currency gets no currency, not the market's.
   *
   * `money()` defaulted to `'SAR'`, so a reading with no currency was stamped with the market this
   * product sells in — «تكلفة النتيجة 18.05 SAR» on an account denominated in USD, with nothing
   * converted and no rate claimed. A bare figure is incomplete; a figure wearing the wrong unit is
   * a different number, and the reader cannot tell.
   */
  it('states no currency where the scope stated none, rather than the market’s', () => {
    const r = moneyFromTotals({ spend: 18.05, spend_withheld_rows: 0 }, 'spend', false)

    expect(r.text).not.toContain('SAR')
    expect(r.text).toContain('18')
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

import { money, rowCostPer, rowMoney, rowRoas } from './format'

/**
 * MONEY-TRUTH-002 — breakdown rows obey the same contract as the summary cards.
 *
 * Platform comparison and campaign ranking were the last surfaces calling `money()` on a raw figure.
 * A platform that spent 4,128.93 USD ranked as having spent nothing, in a table sitting directly
 * beneath a card that showed the real amount.
 */
describe('breakdown rows', () => {
  const withheldRow = {
    provider: 'snapchat',
    spend: 0, revenue: 0, roas: 0, cpa: 0, conversions: 102,
    spend_original: 4128.93, spend_withheld_rows: 262,
    revenue_original: 12969.03, revenue_withheld_rows: 262,
    money_original_currency: 'USD', money_original_currencies: 1,
  }

  it('a row shows the withheld original, not a zero', () => {
    expect(rowMoney(withheldRow, 'spend')).toBe('4,128.93 USD')
    expect(rowMoney(withheldRow, 'spend')).not.toContain('0 SAR')
  })

  it('a row CPA is derived from the original rather than the coalesced zero', () => {
    const text = rowCostPer(withheldRow, 'cpa', 'conversions')

    expect(text).toContain('USD')
    expect(text).not.toBe('0 SAR')
  })

  it('a row ROAS survives one shared currency and is refused across two', () => {
    expect(rowRoas(withheldRow)).toMatch(/3\.1/)
    expect(rowRoas({ ...withheldRow, money_original_currencies: 2 })).toBe('—')
  })

  it('a converted row is untouched', () => {
    const converted = { spend: 15480.5, spend_withheld_rows: 0, roas: 2.97, conversions: 102, cpa: 151.77 }

    // 15,480.5 reads «15.5K» under NUMBER-PRESENTATION-001's three significant digits; what this
    // line is really asserting is that the figure is PRESENT and not withheld.
    expect(rowMoney(converted, 'spend')).toContain('15.5K')
    expect(rowRoas(converted)).toBe('2.97×')
  })

  /** A row that genuinely spent nothing must still say zero — this is a measurement. */
  it('a row that spent nothing still reads zero', () => {
    expect(rowMoney({ spend: 0, spend_withheld_rows: 0 }, 'spend', 'SAR')).toBe('0 SAR')

    // And a row in a scope that stated no currency reads «0», not «0 SAR» (MONEY-USD-001).
    expect(rowMoney({ spend: 0, spend_withheld_rows: 0 }, 'spend')).toBe('0')
  })
})

/**
 * MONEY-TRUTH-003 — campaign details read the same contract, and budget deliberately does not.
 */
describe('campaign detail money', () => {
  const k = {
    spend: 0, revenue: 0, roas: 0, cpa: 0, cpc: 0, cpm: 0,
    conversions: 102, clicks: 21802, impressions: 2884062,
    spend_original: 4128.93, spend_withheld_rows: 262,
    revenue_original: 12969.03, revenue_withheld_rows: 262,
    money_original_currency: 'USD', money_original_currencies: 1,
  }

  it('spend, CPA, CPC and CPM all follow the withheld provenance', () => {
    expect(rowMoney(k, 'spend', 'SAR')).toBe('4,128.93 USD')
    expect(rowCostPer(k, 'cpa', 'conversions', 'SAR')).toContain('USD')
    expect(rowCostPer(k, 'cpc', 'clicks', 'SAR')).toContain('USD')

    // CPM divides by impressions per THOUSAND — 4128.93 / 2884.062 ≈ 1.43
    const cpm = rowCostPer(k, 'cpm', k.impressions / 1000, 'SAR')
    expect(cpm).toContain('USD')
    expect(cpm).toMatch(/1\.4/)
  })

  /**
   * A campaign BUDGET is set by the advertiser in the campaign's own currency and is never withheld.
   * Routing it through the provider-money contract would invent a provenance question it does not
   * have — so it must keep reading as an ordinary amount.
   */
  it('budget is not a provider figure and keeps its own currency', () => {
    expect(money(50000, 'SAR')).toContain('SAR')
    expect(money(50000, 'SAR')).not.toContain('USD')
  })
})
