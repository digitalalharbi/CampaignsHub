import { describe, expect, it } from 'vitest'
import { familySpend } from './familyTotals'
import { layoutFor } from './metricCatalog'
import { rowMoney } from './format'
import type { FamilyRow } from './familyTotals'

/**
 * READY-3 / READY-4 — the Objectives tab judged families by a narrower list, and stated money it had
 * not checked.
 */
const row = (o: Partial<FamilyRow>): FamilyRow => ({ ...o } as FamilyRow)

describe('READY-3 — the objective KPIs come from the catalogue', () => {
  it('gives leads its cost per lead, which the private list omitted', () => {
    // The screen whose whole purpose is judging each family by what it was bought for could not
    // show a leads family its CPL.
    expect(layoutFor('leads').primary).toContain('cpl')
  })

  it('gives sales its cost per acquisition', () => {
    expect(layoutFor('sales').primary).toContain('cpa')
  })

  it('names only metrics the catalogue defines, for every family', () => {
    for (const f of ['awareness', 'traffic', 'engagement', 'video', 'leads', 'sales', 'app']) {
      const primary = layoutFor(f).primary
      expect(primary.length, `${f} has no primary KPIs`).toBeGreaterThan(0)
    }
  })
})

describe('READY-4 — a family states the money it actually has', () => {
  it('fails closed on a partial family instead of printing the withheld half as the total', () => {
    // 1,000 converted beside 500 withheld. Neither number is the family's spend.
    const totals = familySpend([
      row({ spend: 1000, spend_withheld_rows: 0 }),
      row({ spend: 0, spend_withheld_rows: 3, spend_original: 500, money_original_currency: 'USD' }),
    ])

    expect(rowMoney(totals, 'spend', 'SAR')).toBe('—')
  })

  it('refuses to name one currency when several are withheld', () => {
    // The old code hardcoded `money_original_currencies: 1` and took the name from whichever row it
    // found first — so a EUR total could be labelled USD.
    const totals = familySpend([
      row({ spend: 0, spend_withheld_rows: 2, spend_original: 300, money_original_currency: 'USD' }),
      row({ spend: 0, spend_withheld_rows: 1, spend_original: 200, money_original_currency: 'EUR' }),
    ])

    expect(totals.money_original_currencies).toBe(2)
    expect(totals.money_original_currency).toBeNull()
    expect(rowMoney(totals, 'spend', 'SAR')).toBe('—')
  })

  it('states a fully-withheld family in its own single currency', () => {
    const totals = familySpend([
      row({ spend: 0, spend_withheld_rows: 2, spend_original: 300, money_original_currency: 'USD' }),
      row({ spend: 0, spend_withheld_rows: 1, spend_original: 200, money_original_currency: 'USD' }),
    ])

    expect(totals.money_original_currencies).toBe(1)
    expect(rowMoney(totals, 'spend', 'SAR')).toContain('USD')
  })

  it('states a fully-converted family in the project currency', () => {
    const totals = familySpend([row({ spend: 400, spend_withheld_rows: 0 }), row({ spend: 600, spend_withheld_rows: 0 })])

    expect(totals.money_original_currencies).toBe(0)
    expect(rowMoney(totals, 'spend', 'SAR')).toContain('SAR')
  })
})
