import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { TabClientInvoicing } from './TabClientInvoicing'
import { displayStatus, previousIsoWeek } from './clientInvoiceApi'
import { renderWithProviders } from '@/test/utils'

vi.mock('./clientInvoiceApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./clientInvoiceApi')>()
  return { ...actual, getBillingProfile: vi.fn(), saveBillingProfile: vi.fn(), draftInvoice: vi.fn(), invoiceAction: vi.fn() }
})
vi.mock('@/features/billing/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/billing/api')>()
  return { ...actual, listInvoices: vi.fn() }
})

import { draftInvoice, getBillingProfile, invoiceAction, saveBillingProfile } from './clientInvoiceApi'
import { listInvoices } from '@/features/billing/api'

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001, INV-IMMUTABLE-001 — the agency edits how a client is billed and walks
 * each fee invoice through review, issue, send, payment and correction; an issued invoice offers no edit.
 */
const profile = {
  fee_model: 'fixed', fixed_amount: '1000.00', percentage: null, minimum_fee: null, cadence: 'weekly', currency: 'SAR', tax_treatment: 'basic_15', due_days: 7,
  issuer: { legal_name: 'Smart Code' }, bill_to: { legal_name: 'Razzah Co.' }, payment_details: { iban: 'SA03' }, notes: null,
}

const inv = (id: number, status: string, over: Record<string, unknown> = {}) => ({
  id, client_workspace_id: 'c1', source: 'billing_profile', number: `INV-${id}`, currency: 'SAR', total: '1150.00', amount_paid: '0', status,
  due_date: '2999-01-01', period_start: '2026-10-05', period_end: '2026-10-11', ...over,
})

const mount = (canManage = true) => renderWithProviders(<TabClientInvoicing clientId="c1" canManage={canManage} />, { locale: 'en' })

describe('derived helpers', () => {
  it('reads due and overdue from the calendar and leaves finished states alone', () => {
    const today = new Date('2026-10-20T12:00:00')
    expect(displayStatus('issued', '2026-10-25', today)).toBe('due')
    expect(displayStatus('sent', '2026-10-19', today)).toBe('overdue')
    expect(displayStatus('partially_paid', '2026-10-25', today)).toBe('partially_paid')
    expect(displayStatus('paid', '2026-01-01', today)).toBe('paid')
    expect(displayStatus('draft', '2026-01-01', today)).toBe('draft')
  })

  it('finds the Monday to Sunday week before the anchor', () => {
    expect(previousIsoWeek(new Date(2026, 9, 17))).toEqual({ from: '2026-10-05', to: '2026-10-11' })   // Saturday 17 Oct
    expect(previousIsoWeek(new Date(2026, 9, 12))).toEqual({ from: '2026-10-05', to: '2026-10-11' })   // Monday 12 Oct
  })
})

describe('TabClientInvoicing', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getBillingProfile).mockResolvedValue(profile as never)
    vi.mocked(listInvoices).mockResolvedValue({ items: [], total: 0, withheld: 0 } as never)
    vi.mocked(saveBillingProfile).mockResolvedValue(profile as never)
  })

  it('shows the profile and saves it with an upper-cased currency', async () => {
    mount()
    const form = await screen.findByTestId('billing-profile')
    await waitFor(() => expect(within(form).getByDisplayValue('Smart Code')).toBeInTheDocument())

    fireEvent.change(within(form).getByDisplayValue('SAR'), { target: { value: 'usd' } })
    fireEvent.click(screen.getByTestId('billing-profile-save'))

    await waitFor(() => expect(saveBillingProfile).toHaveBeenCalledWith('c1', expect.objectContaining({ currency: 'USD', fee_model: 'fixed' })))
  })

  it('drafts an invoice for the previous week by default and surfaces a refusal in words', async () => {
    vi.mocked(draftInvoice).mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { message: 'The period\'s spend is incomplete', errors: null } } })
    mount()
    const form = await screen.findByTestId('invoice-draft')
    const week = previousIsoWeek()
    expect(within(form).getByDisplayValue(week.from)).toBeInTheDocument()

    fireEvent.click(within(form).getByRole('button', { name: 'Draft an invoice' }))

    await waitFor(() => expect(draftInvoice).toHaveBeenCalledWith('c1', { from: week.from, to: week.to }))
    expect(await screen.findByTestId('invoice-draft-error')).toHaveTextContent(/incomplete/)
  })

  it('offers only the next honest step in each state and nothing that edits an issued invoice', async () => {
    vi.mocked(listInvoices).mockResolvedValue({ items: [inv(1, 'draft'), inv(2, 'reviewed'), inv(3, 'issued'), inv(4, 'paid'), inv(9, 'draft', { client_workspace_id: 'other' }), inv(8, 'draft', { source: 'quote' })], total: 6, withheld: 0 } as never)
    mount()

    const draft = await screen.findByTestId('fee-invoice-1')
    expect(within(draft).getByRole('button', { name: 'Mark reviewed' })).toBeInTheDocument()
    expect(within(draft).queryByRole('button', { name: 'Issue' })).toBeNull()

    expect(within(screen.getByTestId('fee-invoice-2')).getByRole('button', { name: 'Issue' })).toBeInTheDocument()

    const issued = screen.getByTestId('fee-invoice-3')
    expect(within(issued).getAllByRole('button').map((b) => b.textContent)).toEqual(['Mark sent', 'Record payment', 'Correct'])
    expect(within(issued).queryByRole('button', { name: /edit/i })).toBeNull()

    expect(within(screen.getByTestId('fee-invoice-4')).queryAllByRole('button')).toHaveLength(0)
    expect(screen.queryByTestId('fee-invoice-9')).toBeNull()
    expect(screen.queryByTestId('fee-invoice-8')).toBeNull()
  })

  it('reviews, records a payment and corrects with a reason', async () => {
    vi.mocked(listInvoices).mockResolvedValue({ items: [inv(1, 'draft'), inv(3, 'issued')], total: 2, withheld: 0 } as never)
    vi.mocked(invoiceAction).mockResolvedValue({ id: 1, status: 'ok' })
    mount()

    fireEvent.click(within(await screen.findByTestId('fee-invoice-1')).getByRole('button', { name: 'Mark reviewed' }))
    await waitFor(() => expect(invoiceAction).toHaveBeenCalledWith(1, 'review', undefined))

    const issued = screen.getByTestId('fee-invoice-3')
    fireEvent.click(within(issued).getByRole('button', { name: 'Record payment' }))
    fireEvent.change(within(issued).getByLabelText('Amount received'), { target: { value: '150' } })
    fireEvent.click(within(issued).getByRole('button', { name: 'Confirm' }))
    await waitFor(() => expect(invoiceAction).toHaveBeenCalledWith(3, 'payment', { amount: 150 }))

    fireEvent.click(within(issued).getByRole('button', { name: 'Correct' }))
    fireEvent.change(within(issued).getByLabelText('Reason for the correction'), { target: { value: 'Wrong period' } })
    fireEvent.click(within(issued).getByRole('button', { name: 'Confirm' }))
    await waitFor(() => expect(invoiceAction).toHaveBeenCalledWith(3, 'correct', { reason: 'Wrong period' }))
  })

  it('links both PDFs and reads overdue from the due date', async () => {
    vi.mocked(listInvoices).mockResolvedValue({ items: [inv(3, 'sent', { due_date: '2020-01-01' })], total: 1, withheld: 0 } as never)
    mount()

    const row = await screen.findByTestId('fee-invoice-3')
    expect(within(screen.getByTestId('fee-status-3')).getByText('Overdue')).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: /PDF Arabic/ })).toHaveAttribute('href', '/api/v1/billing/invoices/3/pdf?lang=ar')
    expect(within(row).getByRole('link', { name: /PDF English/ })).toHaveAttribute('href', '/api/v1/billing/invoices/3/pdf?lang=en')
  })

  it('is read-only for someone who may not manage billing', async () => {
    vi.mocked(listInvoices).mockResolvedValue({ items: [inv(1, 'draft')], total: 1, withheld: 0 } as never)
    mount(false)

    const row = await screen.findByTestId('fee-invoice-1')
    expect(within(row).queryByRole('button', { name: 'Mark reviewed' })).toBeNull()
    expect(screen.queryByTestId('billing-profile-save')).toBeNull()
    expect(screen.queryByTestId('invoice-draft')).toBeNull()
  })
})
