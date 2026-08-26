import { describe, expect, it } from 'vitest'
import { aggregateMoney, moneyState, rankableMoney, readMoney, readCostPer, readRoas, resolveMoneySeries } from './contract'

/**
 * PARTIAL-WITHHELD-001 — the contract must distinguish «some converted + some withheld» from «all
 * converted», and refuse a single total for it. The prior boolean returned the converted subset the
 * moment any row converted, presenting 1,000 as the whole of a scope that also spent 500 it could not
 * convert. Every case below is a real state of the money-provenance model.
 */

// CASE A — 1,000 converted (SAR) + 500 USD withheld, single withheld currency.
const PARTIAL = {
  spend: 1000,
  spend_original: 500,
  spend_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
}

const ALL_WITHHELD = {
  spend: 0,
  spend_original: 500,
  spend_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
}

const ALL_CONVERTED = { spend: 1000, spend_original: 0, spend_withheld_rows: 0, roas: 3 }

const MIXED = {
  spend: 0,
  spend_original: 800,
  spend_withheld_rows: 2,
  money_original_currency: 'USD',
  money_original_currencies: 2, // USD and EUR, say
}

describe('moneyState', () => {
  it('names each composition, and only three are a single figure', () => {
    expect(moneyState(ALL_CONVERTED, 'spend').state).toBe('complete_converted')
    expect(moneyState(ALL_WITHHELD, 'spend').state).toBe('complete_withheld')
    expect(moneyState(PARTIAL, 'spend').state).toBe('partial')
    expect(moneyState(MIXED, 'spend').state).toBe('mixed_currency')
    expect(moneyState({ spend: null }, 'spend').state).toBe('absent')
    expect(moneyState({ spend: 0, spend_withheld_rows: 0 }, 'spend').state).toBe('zero')
  })
})

describe('readMoney', () => {
  it('CASE A — a partial spend has no single total', () => {
    const r = readMoney(PARTIAL, 'spend', 'SAR', false)
    expect(r.kind).toBe('unavailable')
    expect(r.amount).toBeNull()
  })

  it('CASE D — all converted reads as the converted figure', () => {
    expect(readMoney(ALL_CONVERTED, 'spend', 'SAR', false)).toMatchObject({ kind: 'converted', amount: 1000, currency: 'SAR' })
  })

  it('all withheld reads as the original, in its own currency', () => {
    expect(readMoney(ALL_WITHHELD, 'spend', 'SAR', false)).toMatchObject({ kind: 'withheld', amount: 500, currency: 'USD' })
  })

  it('mixed currency has no total either', () => {
    expect(readMoney(MIXED, 'spend', 'SAR', false).kind).toBe('unavailable')
  })
})

describe('readCostPer', () => {
  it('CASE A — cost-per over a partial spend is unavailable, never the converted subset ÷ denominator', () => {
    expect(readCostPer(PARTIAL, 'cpa', 100, 'SAR', false).kind).toBe('unavailable')
  })

  it('all withheld derives the cost-per from the original', () => {
    const r = readCostPer(ALL_WITHHELD, 'cpa', 10, 'SAR', false)
    expect(r).toMatchObject({ kind: 'withheld', amount: 50, currency: 'USD' })
  })
})

describe('readRoas', () => {
  it('CASE B — a partial side yields no ratio for the whole scope', () => {
    const totals = {
      spend: 1000, spend_original: 500, spend_withheld_rows: 2,
      revenue: 3000, revenue_original: 1500, revenue_withheld_rows: 2,
      money_original_currency: 'USD', money_original_currencies: 1,
    }
    expect(readRoas(totals, false).kind).toBe('unavailable')
  })

  it('a converted spend beside a withheld revenue divides unlike units — unavailable', () => {
    const totals = {
      spend: 1000, spend_original: 0, spend_withheld_rows: 0,
      revenue: 0, revenue_original: 3000, revenue_withheld_rows: 2,
      money_original_currency: 'USD', money_original_currencies: 1,
    }
    expect(readRoas(totals, false).kind).toBe('unavailable')
  })

  it('CASE C — both withheld in one currency survives the missing rate as a ratio', () => {
    const totals = {
      spend: 0, spend_original: 500, spend_withheld_rows: 2,
      revenue: 0, revenue_original: 1500, revenue_withheld_rows: 2,
      money_original_currency: 'USD', money_original_currencies: 1,
    }
    expect(readRoas(totals, false)).toMatchObject({ kind: 'withheld', value: 3 })
  })

  it('CASE D — both converted reads the reported ratio', () => {
    expect(readRoas(ALL_CONVERTED, false)).toMatchObject({ kind: 'converted', value: 3 })
  })
})

// ── aggregateMoney / rankableMoney — the scope totals and chart values ────────────────────────────

const CONVERTED_ROW = { spend: 1000, spend_original: 0, spend_withheld_rows: 0 }
const WITHHELD_USD = { spend: 0, spend_original: 500, spend_withheld_rows: 4, money_original_currency: 'USD', money_original_currencies: 1 }
const WITHHELD_EUR = { spend: 0, spend_original: 300, spend_withheld_rows: 2, money_original_currency: 'EUR', money_original_currencies: 1 }

describe('aggregateMoney — a total exists only for one comparable currency', () => {
  it('sums all-converted rows in the reporting currency', () => {
    expect(aggregateMoney([CONVERTED_ROW, { spend: 500 }], 'spend', 'SAR')).toEqual({ amount: 1500, currency: 'SAR' })
  })

  it('sums all-withheld rows sharing one currency, in that currency', () => {
    expect(aggregateMoney([WITHHELD_USD, WITHHELD_USD], 'spend', 'SAR')).toEqual({ amount: 1000, currency: 'USD' })
  })

  it('a converted row beside a withheld row is riyals-plus-dollars ⇒ null', () => {
    expect(aggregateMoney([CONVERTED_ROW, WITHHELD_USD], 'spend', 'SAR')).toBeNull()
  })

  it('two different withheld currencies ⇒ null', () => {
    expect(aggregateMoney([WITHHELD_USD, WITHHELD_EUR], 'spend', 'SAR')).toBeNull()
  })

  it('a single partial row ⇒ null (never the converted subset)', () => {
    expect(aggregateMoney([PARTIAL], 'spend', 'SAR')).toBeNull()
  })
})

describe('rankableMoney — a chart drops and discloses where a total fails closed', () => {
  it('gives per-row values for all-withheld one currency', () => {
    expect(rankableMoney([WITHHELD_USD, { spend: 0, spend_original: 200, spend_withheld_rows: 1, money_original_currency: 'USD', money_original_currencies: 1 }], 'spend', 'SAR'))
      .toEqual({ values: [500, 200], currency: 'USD', dropped: 0 })
  })

  /*
   * The rule the totals do NOT follow. `aggregateMoney` refuses the moment one row is unreadable,
   * because a sum of part of a scope printed as the scope is the defect. A chart is a different
   * claim: refusing six platforms because one is partial hides five that are known. So the partial
   * row is left out, its absence is COUNTED, and the caller must render that count.
   */
  it('drops a partial row, keeps the readable ones, and reports how many it dropped', () => {
    expect(rankableMoney([CONVERTED_ROW, PARTIAL], 'spend', 'SAR'))
      .toEqual({ values: [1000, null], currency: 'SAR', dropped: 1 })
  })

  it('drops withheld rows that would put a second currency on the same axis', () => {
    // Riyals cannot be drawn beside dollars, so the withheld row goes rather than the whole chart.
    expect(rankableMoney([CONVERTED_ROW, WITHHELD_USD], 'spend', 'SAR'))
      .toEqual({ values: [1000, null], currency: 'SAR', dropped: 1 })
  })

  it('null across two withheld currencies — nothing comparable is left to draw', () => {
    expect(rankableMoney([WITHHELD_USD, WITHHELD_EUR], 'spend', 'SAR')).toBeNull()
  })

  it('null only when EVERY row is unreadable, never for an empty set', () => {
    expect(rankableMoney([PARTIAL], 'spend', 'SAR')).toBeNull()
    // «No rows yet» is the caller's own empty state, not «this money cannot be shown».
    expect(rankableMoney([], 'spend', 'SAR')).toEqual({ values: [], currency: 'SAR', dropped: 0 })
  })
})

/**
 * PARTIAL-WITHHELD-001 (d/f) — a money TIMESERIES fails closed where a donut drops-and-discloses.
 *
 * A trend line is a claim in one currency at every point; it cannot omit a day and stay honest, so a
 * single partial/withheld/cross-currency point closes the whole series to «unavailable». Contrast
 * `rankableMoney`, which keeps the comparable rows and reports how many it dropped.
 */
describe('resolveMoneySeries — a chartable money timeseries, or null', () => {
  it('all-converted days pass through with the reporting currency', () => {
    const rows = [
      { date: 'd1', spend: 100, revenue: 300, spend_withheld_rows: 0, revenue_withheld_rows: 0 },
      { date: 'd2', spend: 200, revenue: 500, spend_withheld_rows: 0, revenue_withheld_rows: 0 },
    ]
    const r = resolveMoneySeries(rows, ['spend', 'revenue'], 'SAR')!
    expect(r.currency).toBe('SAR')
    expect(r.rows.map((x) => x.spend)).toEqual([100, 200])
  })

  it('a fully-withheld series is replaced by ORIGINAL values in their own currency, not a coalesced 0', () => {
    const rows = [
      { date: 'd1', spend: 0, spend_original: 400, spend_withheld_rows: 2, revenue: 0, revenue_original: 1200, revenue_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 1 },
    ]
    const r = resolveMoneySeries(rows, ['spend', 'revenue'], 'SAR')!
    expect(r.currency).toBe('USD')
    expect(r.rows[0].spend).toBe(400) // NOT 0
    expect(r.rows[0].revenue).toBe(1200)
  })

  it('a partial day (some rows converted, some withheld) makes the WHOLE series unavailable', () => {
    const rows = [
      { date: 'd1', spend: 1000, revenue: 3000, spend_withheld_rows: 0, revenue_withheld_rows: 0 },
      { date: 'd2', spend: 1000, spend_original: 500, spend_withheld_rows: 2, revenue: 3000, revenue_withheld_rows: 0, money_original_currency: 'USD', money_original_currencies: 1 },
    ]
    expect(resolveMoneySeries(rows, ['spend', 'revenue'], 'SAR')).toBeNull()
  })

  it('spend and revenue in different currencies cannot share one chart', () => {
    const rows = [
      { date: 'd1', spend: 100, spend_withheld_rows: 0, revenue: 0, revenue_original: 900, revenue_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 1 },
    ]
    // spend converted (SAR) beside revenue withheld (USD) → two currencies → null.
    expect(resolveMoneySeries(rows, ['spend', 'revenue'], 'SAR')).toBeNull()
  })
})
