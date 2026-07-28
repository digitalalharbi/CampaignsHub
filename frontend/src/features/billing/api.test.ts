import { describe, expect, it } from 'vitest'
import {
  formatDate, formatMoney, isPayable, paymentDisplayState,
  type Invoice, type Payment,
} from './api'

function invoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: 'i1', tenant_id: 't1', client_workspace_id: null, quote_id: 'q1', number: 'INV-1',
    currency: 'SAR', subtotal: '1000', tax: '150', discount: '0', total: '1150', amount_paid: '0',
    status: 'issued', due_date: null, issued_at: null, paid_at: null, created_at: null, ...overrides,
  }
}

function payment(overrides: Partial<Payment> = {}): Payment {
  return {
    id: 'p1', tenant_id: 't1', invoice_id: 'i1', provider: 'null', provider_session_id: null,
    provider_payment_id: null, amount: '1150', currency: 'SAR', status: 'pending',
    idempotency_key: 'k', error: null, paid_at: null, created_at: null, ...overrides,
  }
}

describe('billing/api mapping', () => {
  it('formats money with Latin digits and two fraction digits', () => {
    expect(formatMoney('1150', 'SAR')).toBe('1,150.00 SAR')
    expect(formatMoney(1150.5, 'USD')).toBe('1,150.50 USD')
    // Non-numeric falls back without crashing.
    expect(formatMoney('n/a', 'SAR')).toBe('n/a SAR')
  })

  it('formats dates as Latin YYYY-MM-DD and null as an em dash', () => {
    expect(formatDate('2026-04-01T00:00:00Z')).toBe('2026-04-01')
    expect(formatDate(null)).toBe('—')
  })

  it('treats only issued / partially_paid invoices as payable', () => {
    expect(isPayable(invoice({ status: 'issued' }))).toBe(true)
    expect(isPayable(invoice({ status: 'partially_paid' }))).toBe(true)
    expect(isPayable(invoice({ status: 'draft' }))).toBe(false)
    expect(isPayable(invoice({ status: 'paid' }))).toBe(false)
    expect(isPayable(invoice({ status: 'refunded' }))).toBe(false)
  })

  it('surfaces the honest awaiting-provider state for the Null provider — never a fake paid', () => {
    // Null provider, pending, no session id → awaiting credentials.
    expect(paymentDisplayState(payment())).toBe('awaiting_provider_credentials')
    // A real provider with an open session keeps the real status.
    expect(paymentDisplayState(payment({ provider: 'stripe', provider_session_id: 'sess_1', status: 'pending' }))).toBe('pending')
    // A verified settlement reads as paid (only ever driven by a real webhook upstream).
    expect(paymentDisplayState(payment({ provider: 'stripe', provider_session_id: 'sess_1', status: 'paid' }))).toBe('paid')
    expect(paymentDisplayState(payment({ status: 'failed', provider: 'stripe', provider_session_id: 'sess_1' }))).toBe('failed')
  })
})
