import { describe, expect, it } from 'vitest'
import { aggregateMoney, moneyState, rankableMoney, rankMoney, readMoney, readCostPer, readRoas, spendComparableAmount } from './contract'

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

describe('rankableMoney — chart values only when comparable', () => {
  it('gives per-row values for all-withheld one currency', () => {
    expect(rankableMoney([WITHHELD_USD, { spend: 0, spend_original: 200, spend_withheld_rows: 1, money_original_currency: 'USD', money_original_currencies: 1 }], 'spend', 'SAR'))
      .toEqual({ values: [500, 200], currency: 'USD' })
  })

  it('null when a partial row is present — the chart must not draw a fake share', () => {
    expect(rankableMoney([CONVERTED_ROW, PARTIAL], 'spend', 'SAR')).toBeNull()
  })

  it('null across currencies — no cross-currency ranking', () => {
    expect(rankableMoney([WITHHELD_USD, WITHHELD_EUR], 'spend', 'SAR')).toBeNull()
  })
})

// ── Required integration fixtures (reviewer A–E) ──────────────────────────────────────────────────

describe('spendComparableAmount — reporting vs target currency, never assumed equal', () => {
  const CONVERTED = { spend: 5000, spend_original: 0, spend_withheld_rows: 0 }
  const WITHHELD_USD_ROW = { spend: 0, spend_original: 500, spend_withheld_rows: 4, money_original_currency: 'USD', money_original_currencies: 1 }

  it('A — a partial scope has no comparable amount', () => {
    expect(spendComparableAmount(PARTIAL, 'spend', 'SAR', 'SAR')).toBeNull()
  })

  it('B — complete converted in USD is NOT usable against a SAR budget', () => {
    expect(spendComparableAmount(CONVERTED, 'spend', 'USD', 'SAR')).toBeNull()
  })

  it('B — complete converted is usable when reporting equals the target', () => {
    expect(spendComparableAmount(CONVERTED, 'spend', 'SAR', 'SAR')).toBe(5000)
  })

  it('C — all withheld USD against a USD budget is a valid comparison', () => {
    expect(spendComparableAmount(WITHHELD_USD_ROW, 'spend', 'SAR', 'USD')).toBe(500)
  })

  it('a withheld USD spend is not usable against a SAR budget', () => {
    expect(spendComparableAmount(WITHHELD_USD_ROW, 'spend', 'SAR', 'SAR')).toBeNull()
  })

  it('zero compares to any currency; absent/target-null do not', () => {
    expect(spendComparableAmount({ spend: 0, spend_withheld_rows: 0 }, 'spend', 'SAR', 'USD')).toBe(0)
    expect(spendComparableAmount(CONVERTED, 'spend', 'SAR', null)).toBeNull()
  })
})

describe('D — rankMoney actually ranks (not the first N rows)', () => {
  it('returns indices sorted by real spend, so top-N is the biggest', () => {
    const rows = [100, 900, 500, 700, 300, 200, 1000, 50].map((v) => ({ spend: v, spend_withheld_rows: 0 }))
    const ranked = rankMoney(rows, 'spend', 'SAR')!
    expect(ranked.order.slice(0, 3).map((i) => ranked.values[i])).toEqual([1000, 900, 700])
    expect(ranked.order[ranked.order.length - 1]).toBe(7) // the 50 is last
  })
})

describe('E — a platform with unavailable ROAS is not ranked as best', () => {
  it('readRoas returns null for a partial platform, so a caller must exclude it from a global «best»', () => {
    const partialPlatform = { spend: 1000, spend_original: 500, spend_withheld_rows: 2, revenue: 3000, revenue_original: 1500, revenue_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 1 }
    const goodPlatform = { spend: 1000, revenue: 4000, roas: 4, spend_withheld_rows: 0, revenue_withheld_rows: 0 }
    expect(readRoas(partialPlatform, false).value).toBeNull()
    expect(readRoas(goodPlatform, false).value).toBe(4)
  })
})
