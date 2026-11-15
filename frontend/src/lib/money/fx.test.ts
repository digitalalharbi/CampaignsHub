import { describe, expect, it } from 'vitest'
import { formatConversion, pendingRateLabel, type FxConversion } from './fx'

/** FX-ENGINE-001 — the frontend renders the backend's conversion; it never prints the original amount with the target's mark. */
const conv = (over: Partial<FxConversion> = {}): FxConversion => ({
  original_amount: 253, original_currency: 'EUR', date: '2026-11-10', currency: 'USD',
  converted_amount: 283.36, rate: 1.12, rate_date: '2026-11-09', source: 'manual:fx-admin@example.test', kind: 'manual',
  recorded_by: 'fx-admin@example.test', stale: false, withheld: false, pending_label: null, rates_page: null, legs: [], via: 'fx.convert',
  ...over,
})

describe('formatConversion', () => {
  it('253 EUR at 1.12 reads 283.36 $ — never 253 $', () => {
    const out = formatConversion(conv(), true)
    expect(out.text).toBe('283.36 $')
    expect(out.text).not.toContain('253')
    expect(out.basis).toContain('1 EUR = 1.12 USD')
    expect(out.basis).toContain('2026-11-09')
  })

  it('a withheld figure is the waiting label with a link, never zero and never the raw number', () => {
    const out = formatConversion(conv({ converted_amount: null, rate: null, rate_date: null, kind: null, withheld: true, pending_label: 'بانتظار سعر صرف (EUR)', rates_page: '/admin/settings/currency-rates' }), true)
    expect(out.withheld).toBe(true)
    expect(out.text).toBe('بانتظار سعر صرف (EUR)')
    expect(out.text).not.toMatch(/\d/)
    expect(out.link).toBe('/admin/settings/currency-rates')
  })

  it('a stale rate is used and flagged', () => {
    expect(formatConversion(conv({ stale: true }), true).stale).toBe(true)
  })

  it('SAR targets carry the riyal mark', () => {
    expect(formatConversion(conv({ currency: 'SAR', converted_amount: 1062.6, kind: 'cross' }), true).text).toBe('1,062.60 ر.س')
  })

  it('pendingRateLabel names the currency', () => {
    expect(pendingRateLabel('eur', true)).toBe('بانتظار سعر صرف (EUR)')
    expect(pendingRateLabel(null, false)).toBe('Awaiting exchange rate')
  })
})
