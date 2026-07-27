import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ClientInvoiceDetailPage } from './ClientInvoiceDetailPage'
import type { PortalInvoice, PortalPayment } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

// Keep the real formatting/helpers; mock only the network calls.
vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return { ...actual, getPortalInvoice: vi.fn(), payPortalInvoice: vi.fn() }
})

import { getPortalInvoice, payPortalInvoice } from './portalAccountApi'

const invoice: PortalInvoice = {
  id: 'i1', number: 'INV-1001', currency: 'SAR',
  subtotal: '1000', tax: '150', discount: '0', total: '1150', amount_paid: '0',
  status: 'unpaid', payment_status: 'unpaid', due_date: '2026-04-01', issued_at: '2026-03-01T00:00:00Z', paid_at: null,
}

function renderPage() {
  return renderWithProviders(<ClientInvoiceDetailPage />, { route: '/client/invoices/i1', path: '/client/invoices/:id', locale: 'en' })
}

describe('ClientInvoiceDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getPortalInvoice).mockResolvedValue(invoice)
  })
  afterEach(() => vi.clearAllMocks())

  it('renders the invoice with a Latin-digit total and a Pay button', async () => {
    renderPage()
    expect(await screen.findByText('INV-1001')).toBeInTheDocument()
    expect(screen.getByText('1,150.00 SAR')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Pay now/i })).toBeInTheDocument()
  })

  it('shows the HONEST awaiting-provider state on pay — never a fake success/receipt', async () => {
    const awaiting: PortalPayment = {
      id: 'p1', status: 'pending', amount: '1150', currency: 'SAR',
      payment_state: 'awaiting_provider_credentials', message: 'Payment provider not yet configured — no charge was made.',
    }
    vi.mocked(payPortalInvoice).mockResolvedValue(awaiting)

    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /Pay now/i }))

    // The honest note (unique phrasing) is the signal — the provider isn't wired, nothing was charged.
    expect(await screen.findByText(/the provider is being connected/i)).toBeInTheDocument()
    expect(screen.getByText(/No charge was made/i)).toBeInTheDocument()
    // Honesty guard: no fabricated paid/receipt/success language.
    expect(screen.queryByText(/receipt/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/paid successfully/i)).not.toBeInTheDocument()
  })
})
