import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { FxAmount, FxCoverageNote, FxSpendNote, FxTotalAmount } from './FxAmount'
import { conversionTooltip, type FxConversion, type FxTotal } from '@/lib/money/fx'

/** FX-APPLY-001 (builder G) — the Owner's display spec for a converted figure, on the one component every screen uses. */
const eur: FxConversion = {
  original_amount: 253, original_currency: 'EUR', date: '2026-11-09', currency: 'USD', converted_amount: 283.36, rate: 1.12,
  rate_date: '2026-11-09', source: 'manual:ops@example.test', kind: 'manual', recorded_by: 'ops@example.test', stale: false, withheld: false,
  pending_label: null, rates_page: null, legs: [], via: 'fx.convert',
}

describe('FxAmount', () => {
  it('shows the converted figure with «محوّل» and the arithmetic in its tooltip — never «253 $»', () => {
    render(<FxAmount c={eur} ar testId="x" />)
    const el = screen.getByTestId('x')
    expect(el).toHaveTextContent('283.36 $')
    expect(el).not.toHaveTextContent('253.00 $')
    expect(screen.getByTestId('fx-converted-icon')).toHaveTextContent('محوّل')
    expect(el.getAttribute('title')).toBe('253.00 EUR × 1.12 (2026-11-09، يدوي: ops@example.test) = 283.36 USD')
  })

  it('shows BOTH currencies on the campaign page', () => {
    render(<FxAmount c={eur} ar showOriginal testId="x" />)
    expect(screen.getByTestId('fx-original')).toHaveTextContent('(253.00 €)')
  })

  it('a same-currency figure carries no «محوّل» icon and no tooltip arithmetic', () => {
    const usd = { ...eur, original_currency: 'USD', original_amount: 283.36, rate: 1, kind: 'identity' as const }
    render(<FxAmount c={usd} ar testId="x" />)
    expect(screen.queryByTestId('fx-converted-icon')).toBeNull()
    expect(conversionTooltip(usd, true)).toBeNull()
  })

  it('a withheld figure is «—» + ⚠ + a link to the rates page, never zero and never the raw number', () => {
    const w = { ...eur, converted_amount: null, rate: null, rate_date: null, kind: null, withheld: true, pending_label: 'بانتظار سعر صرف (EUR)', rates_page: '/admin/settings/currency-rates' }
    render(<FxAmount c={w} ar testId="x" />)
    const el = screen.getByTestId('x')
    expect(el).toHaveTextContent('—')
    expect(el).not.toHaveTextContent('253')
    expect(el).not.toHaveTextContent('0.00')
    expect(screen.getByTestId('fx-withheld-link').getAttribute('href')).toBe('/admin/settings/currency-rates')
  })

  /** Owner 2026-11-19 — an old rate is USED and never worded to the client: no mark, no «سعر قديم»; the alert lives in /admin. */
  it('a rate older than 7 days is used and shows no stale mark', () => {
    const { container } = render(<FxAmount c={{ ...eur, stale: true }} ar testId="x" />)
    expect(screen.getByTestId('x')).toHaveTextContent('283.36 $')
    expect(screen.queryByTestId('fx-stale')).toBeNull()
    expect(container).not.toHaveTextContent('سعر قديم')
    expect(container).not.toHaveTextContent('older than 7 days')
  })
})

describe('FxTotalAmount and FxCoverageNote', () => {
  const total: FxTotal = { currency: 'USD', converted_amount: 483.36, withheld: false, includes_converted: true, currencies: ['EUR', 'SAR', 'USD'], stale: false, pending_label: null, rates_page: null, via: 'fx.convert' }

  it('a total of several currencies says «يشمل عملات محوّلة»', () => {
    render(<FxTotalAmount t={total} ar testId="t" />)
    expect(screen.getByTestId('t')).toHaveTextContent('483.36 $')
    expect(screen.getByTestId('fx-includes-converted')).toHaveTextContent('يشمل عملات محوّلة')
  })

  it('a total with a withheld part is withheld, not the smaller sum', () => {
    render(<FxTotalAmount t={{ ...total, converted_amount: null, withheld: true, pending_label: 'بانتظار سعر صرف (GBP)', rates_page: '/admin/settings/currency-rates' }} ar testId="t" />)
    expect(screen.getByTestId('t')).toHaveTextContent('بانتظار سعر صرف (GBP)')
    expect(screen.getByTestId('t')).not.toHaveTextContent('483')
  })

  it('the scope note names converted currencies and withheld rows, and says nothing about an old rate', () => {
    render(<FxCoverageNote fx={{ reporting_currency: 'USD', includes_converted: true, currencies: ['EUR', 'USD'], stale: true, withheld_rows: 2, withheld_currencies: ['GBP'], rates_page: '/admin/settings/currency-rates' }} ar />)
    expect(screen.getByTestId('fx-includes-converted')).toBeTruthy()
    expect(screen.queryByTestId('fx-stale')).toBeNull()
    expect(screen.getByTestId('fx-coverage-withheld')).toHaveTextContent('GBP')
  })
})

/** PROJECT-LIFECYCLE-001 §7 (N4) — a missing rate never zeroes a total: the converted part is shown, the rest is named. */
describe('FxSpendNote and a partial total', () => {
  const excluded = [{ project_id: 'p', account_id: 'a', account_name: 'Account EUR', provider: 'google', currency: 'EUR', original_amount: 400, days: 4, first_day: '2026-11-05', last_day: '2026-11-08' }]
  const statement = 'سعر صرف غير متوفر: EUR — Account EUR (400.00 EUR، 4 أيام) — مستبعد من الإجمالي.'

  it('a partial total shows the converted part and marks the excluded part — never «—», never zero', () => {
    const t: FxTotal = { currency: 'USD', converted_amount: 648, withheld: false, partial: true, includes_converted: true, currencies: ['EUR'], stale: false,
      pending_label: 'سعر صرف غير متوفر (EUR)', rates_page: '/admin/settings/currency-rates', via: 'fx.convert', excluded, statement }
    render(<FxTotalAmount t={t} ar testId="t" />)
    expect(screen.getByTestId('t')).toHaveTextContent('648.00 $')
    expect(screen.getByTestId('fx-excluded').getAttribute('title')).toBe(statement)
  })

  it('the note states each excluded part with its account and says nothing about the old rate used', () => {
    render(<FxSpendNote ar note={{ complete: false, excluded, excluded_label: 'سعر صرف غير متوفر', statement, rates_page: '/admin/settings/currency-rates',
      stale: [{ currency: 'EUR', rate_date: '2026-11-09', expired: true, days: 3 }] }} />)
    expect(screen.getByTestId('fx-spend-note-excluded')).toHaveTextContent('Account EUR')
    expect(screen.getByTestId('fx-spend-note-excluded')).toHaveTextContent('مستبعد من الإجمالي')
    expect(screen.queryByTestId('fx-spend-note-stale')).toBeNull()
    expect(screen.getByTestId('fx-spend-note')).not.toHaveTextContent('سعر قديم')
  })

  it('a complete reading with no old rate renders nothing', () => {
    const { container } = render(<FxSpendNote ar note={{ complete: true, excluded: [], excluded_label: null, statement: null, rates_page: null, stale: [] }} />)
    expect(container.textContent).toBe('')
  })
})
