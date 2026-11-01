import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TaxPage } from './TaxPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchTax: vi.fn(), updateTaxEdition: vi.fn(), fetchInvoiceReview: vi.fn() }
})

import { fetchInvoiceReview, fetchTax, updateTaxEdition } from './api'

/** PRICING-VAT-002 — legal treatments only, new sales only (said), and the read-only invoice review. */
describe('TaxPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('saves an edition with a reason, says existing subscriptions keep their terms, and lists invoices for review', async () => {
    vi.mocked(fetchTax).mockResolvedValue({
      treatments: [{ key: 'basic_15', rate: 0.15 }, { key: 'zero_rated', rate: 0 }, { key: 'exempt', rate: 0 }, { key: 'out_of_scope', rate: 0 }],
      editions: [{ id: 'v2', number: 2, label: '2026-11 catalogue (SAR)', currency: 'SAR', status: 'published', tax_treatment: 'basic_15', rate: 0.15,
        prices_include_vat: false, subscriptions_keeping_their_terms: 4 }],
    })
    vi.mocked(fetchInvoiceReview).mockResolvedValue({ checked: 12, flagged: 1, by_issue: { total_differs_from_charge: 1 }, rows: [{
      invoice_id: 'i1', number: 'CH-1', tenant: 'Legacy Co', status: 'paid', currency: 'USD', subtotal: '9.00', tax_total: '1.35', total: '10.35',
      amount_paid: '10.35', charged: '9.00', charge_status: 'paid', tax_treatment: 'basic_15', issued_at: '2026-10-01T00:00:00Z', issues: ['total_differs_from_charge'],
    }] })
    vi.mocked(updateTaxEdition).mockResolvedValue({ edition: {} as never })
    renderWithProviders(<TaxPage />, { locale: 'en' })

    expect(await screen.findByTestId('tax-edition-2')).toHaveTextContent('4 existing subscriptions keep their terms')
    expect(screen.queryByRole('option', { name: '5% (historical)' })).not.toBeInTheDocument()
    expect(await screen.findByTestId('tax-review-CH-1')).toHaveTextContent('Total differs from the charge')
    expect(screen.getByTestId('tax-review-summary')).toHaveTextContent('Checked 12 · need review 1')

    fireEvent.change(screen.getByTestId('tax-basis-2'), { target: { value: 'inclusive' } })
    expect(screen.getByTestId('tax-save-2')).toBeDisabled()
    fireEvent.change(screen.getByTestId('tax-reason-2'), { target: { value: 'Show all-in prices' } })
    fireEvent.click(screen.getByTestId('tax-save-2'))
    await waitFor(() => expect(updateTaxEdition).toHaveBeenCalledWith('v2', { tax_treatment: 'basic_15', prices_include_vat: true, reason: 'Show all-in prices' }))
  })
})
