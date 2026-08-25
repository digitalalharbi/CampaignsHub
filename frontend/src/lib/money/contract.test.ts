import { describe, expect, it } from 'vitest'
import { moneyState, readMoney, readCostPer, readRoas } from './contract'

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
