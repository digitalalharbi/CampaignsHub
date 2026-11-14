import { describe, expect, it } from 'vitest'
import { moneyFromTotals, money, moneyExact, wholeMoney } from '@/features/analytics/format'
import { formatMoneyParts, formatMoneyReading, readMoney } from '@/lib/money/contract'
import { currencyMark, withMark, NO_RATE_LABEL } from '@/lib/money/mark'

/**
 * MONEY-MARKS-001 — every money figure shows a SHORT currency mark beside the number (ر.س / $ / € / ISO code), the same in
 * Arabic and English; percentages, counts and ROAS never do.
 */
describe('currency marks', () => {
  it('USD, SAR, EUR and any other code', () => {
    expect(currencyMark('USD')).toBe('$')
    expect(currencyMark('sar')).toBe('ر.س')
    expect(currencyMark('EUR')).toBe('€')
    expect(currencyMark('aed')).toBe('AED')
  })

  it('withMark puts the mark after the number and prints nothing for an unknown currency', () => {
    expect(withMark('1,234', 'SAR')).toBe('1,234 ر.س')
    expect(withMark('1,234', null)).toBe('1,234')
    expect(withMark('1,234', undefined)).toBe('1,234')
  })

  it('the analytics formatters carry the mark, never the ISO code of a known currency', () => {
    expect(money(4850000, 'USD')).toBe('4.85M $')
    expect(money(950, 'SAR')).toBe('950 ر.س')
    expect(moneyExact(73.72, 'EUR')).toBe('73.72 €')
    expect(wholeMoney(10696.54, 'USD')).toBe('10,696.54 $')
    expect(money(12, 'AED')).toBe('12 AED')
    expect(money(12, null)).toBe('12')
  })

  it('a withheld figure keeps its OWN currency mark and never a guessed conversion', () => {
    const r = readMoney({ spend: 0, spend_withheld_rows: 1, spend_original: 100, money_original_currency: 'EUR', money_original_currencies: 1 }, 'spend', 'USD', true)
    expect(formatMoneyReading(r, money)).toBe('100.00 €')
    expect(moneyFromTotals({ spend: 0, spend_withheld_rows: 1, spend_original: 100, money_original_currency: 'EUR', money_original_currencies: 1 }, 'spend', true, 'USD').withheld).toBe(true)
    expect(formatMoneyParts({ converted: 16.46, original: 15.82, originalCurrency: 'EUR' }, 'USD', money)).toBe('16 $ + 15.82 €')
  })

  it('the no-rate chip words are the plain Arabic ones', () => {
    expect(NO_RATE_LABEL.ar).toBe('بدون سعر صرف')
  })

  it('ratios and percentages carry no currency', async () => {
    const { ratio, percent, num } = await import('@/features/analytics/format')
    expect(ratio(3.456)).toBe('3.46×')
    expect(percent(0.1234)).toBe('12.3%')
    expect(num(1234)).toBe('1,234')
  })
})
