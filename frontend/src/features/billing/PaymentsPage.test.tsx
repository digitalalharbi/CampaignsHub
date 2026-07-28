import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { PaymentsPage } from './PaymentsPage'
import type { Invoice, Payment } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

// Keep the real formatting/helpers; mock only the network calls.
vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listInvoices: vi.fn(), startPayment: vi.fn() }
})

import { listInvoices, startPayment } from './api'

const invoice: Invoice = {
  id: 'i1', tenant_id: 't1', client_workspace_id: null, quote_id: 'q1', number: 'INV-1001',
  currency: 'SAR', subtotal: '1000', tax: '150', discount: '0', total: '1150', amount_paid: '0',
  status: 'issued', due_date: null, issued_at: null, paid_at: null, created_at: null,
}

const nullPayment: Payment = {
  id: 'p1', tenant_id: 't1', invoice_id: 'i1', provider: 'null', provider_session_id: null,
  provider_payment_id: null, amount: '1150', currency: 'SAR', status: 'pending',
  idempotency_key: 'chub-inv-i1', error: null, paid_at: null, created_at: null,
}

describe('PaymentsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listInvoices).mockResolvedValue([invoice])
    vi.mocked(startPayment).mockResolvedValue(nullPayment)
  })
  afterEach(() => signOut())

  it('lists a payable invoice and starts a session showing the HONEST awaiting state — never a fake paid', async () => {
    signInWith(['billing.view', 'billing.manage'])
    renderWithProviders(<PaymentsPage />, { locale: 'en' })

    // The payable invoice appears with a Latin-digit outstanding amount.
    expect(await screen.findByText('INV-1001')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Start payment session/i }))

    // Honest state — the Null provider means credentials aren't wired; nothing is charged.
    expect(await screen.findAllByText(/Awaiting provider credentials/i)).not.toHaveLength(0)
    expect(startPayment).toHaveBeenCalledWith('i1')

    // Honesty guard: no fabricated paid/success language.
    expect(screen.queryByText(/^Paid$/)).not.toBeInTheDocument()
    expect(screen.queryByText(/paid successfully/i)).not.toBeInTheDocument()
  })

  it('drills into provider attempts for a started session', async () => {
    signInWith(['billing.view', 'billing.manage'])
    renderWithProviders(<PaymentsPage />, { locale: 'en' })
    await screen.findByText('INV-1001')

    fireEvent.click(screen.getByRole('button', { name: /Start payment session/i }))
    const toggle = await screen.findByRole('button', { name: /Show attempts/i })
    fireEvent.click(toggle)

    // The attempts table header is revealed.
    expect(await screen.findByText('Session id')).toBeInTheDocument()
  })

  it('hides the start action without billing.manage', async () => {
    signInWith(['billing.view'])
    renderWithProviders(<PaymentsPage />, { locale: 'en' })
    await screen.findByText('INV-1001')
    expect(screen.queryByRole('button', { name: /Start payment session/i })).not.toBeInTheDocument()
  })
})
