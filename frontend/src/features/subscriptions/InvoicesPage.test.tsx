import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { InvoicesPage } from './InvoicesPage'
import type { SubscriptionInvoice } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listSubscriptionInvoices: vi.fn(),
    shareSubscriptionInvoice: vi.fn(),
    revokeSubscriptionInvoiceShare: vi.fn(),
  }
})

import { listSubscriptionInvoices, shareSubscriptionInvoice } from './api'

const invoice = (over: Partial<SubscriptionInvoice> = {}): SubscriptionInvoice => ({
  id: 'inv-1', number: 'CH-2026-00001', status: 'paid',
  bill_to: { name: 'Acme', email: 'a@acme.test', tax_number: null },
  currency: 'SAR', subtotal: '499.00', discount_total: '0.00',
  tax_treatment: 'basic_15', tax_rate: '0.1500', tax_total: '74.85',
  total: '573.85', amount_paid: '573.85', outstanding: '0.00',
  issued_at: '2026-08-01T10:00:00+00:00', due_at: null, paid_at: '2026-08-01T10:05:00+00:00',
  refunded_at: null, void_reason: null, is_shared: false, share_url: null,
  lines: [],
  ...over,
})

/**
 * The customer's own CampaignsHub invoices (SUBINV-001).
 *
 * These are OURS to them. Their invoices to their own clients are a different surface behind a
 * different permission, and the page says so rather than leaving somebody to work it out.
 */
describe('InvoicesPage', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['subscriptions.view', 'subscriptions.manage']) })
  afterEach(() => signOut())

  it('lists an invoice with its tax treatment named, not only its amount', async () => {
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({ invoices: [invoice()] })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    const row = await screen.findByTestId('subscription-invoice-row')
    expect(row).toHaveAttribute('data-status', 'paid')
    expect(row).toHaveTextContent('573.85')
    // `zero_rated` and `exempt` both compute to zero and are different statements to a tax
    // authority, so the treatment is shown alongside the number.
    expect(row).toHaveTextContent('basic_15')
  })

  /** The distinction the page exists to make: these are not the agency's invoices to its clients. */
  it('says whose invoices these are', async () => {
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({ invoices: [invoice()] })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    expect(await screen.findByText(/What CampaignsHub billed you/i)).toBeInTheDocument()
    expect(screen.getByText(/own invoices to your clients are under Billing/i)).toBeInTheDocument()
  })

  it('shows an unpaid balance rather than implying everything is settled', async () => {
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({
      invoices: [invoice({ status: 'issued', amount_paid: '0.00', outstanding: '573.85' })],
    })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    const row = await screen.findByTestId('subscription-invoice-row')
    expect(row).toHaveAttribute('data-status', 'issued')
    expect(row).toHaveTextContent('573.85 SAR')
  })

  it('shares a document for somebody who has no account here', async () => {
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({ invoices: [invoice()] })
    vi.mocked(shareSubscriptionInvoice).mockResolvedValue({
      share_url: 'https://app.test/invoices/tok', invoice: invoice({ is_shared: true }),
    })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('invoice-share-CH-2026-00001'))

    // `expect.anything()` for the second argument: react-query hands a mutationFn its own context.
    await waitFor(() => expect(shareSubscriptionInvoice).toHaveBeenCalledWith('inv-1', expect.anything()))
  })

  /** Sharing is a decision, so somebody without the permission is not offered it. */
  it('does not offer sharing to a member who cannot manage the subscription', async () => {
    signOut()
    signInWith(['subscriptions.view'])
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({ invoices: [invoice()] })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    await screen.findByTestId('subscription-invoice-row')
    expect(screen.queryByTestId('invoice-share-CH-2026-00001')).not.toBeInTheDocument()
    // …but reading and downloading their own document is still theirs.
    expect(screen.getByTestId('invoice-download-CH-2026-00001')).toBeInTheDocument()
  })

  it('says plainly when there is nothing to show', async () => {
    vi.mocked(listSubscriptionInvoices).mockResolvedValue({ invoices: [] })

    renderWithProviders(<InvoicesPage />, { locale: 'en' })

    expect(await screen.findByTestId('subscription-invoices-empty')).toBeInTheDocument()
  })
})
