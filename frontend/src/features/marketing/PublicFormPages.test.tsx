import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ContactForm, DataRequestForm, SupportForm } from './PublicFormPages'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./publicFormsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./publicFormsApi')>()),
  sendContact: vi.fn(),
  openSupportTicket: vi.fn(),
  submitDataRequest: vi.fn(),
}))

import { openSupportTicket, sendContact, submitDataRequest } from './publicFormsApi'

/**
 * LEGAL-002 — three forms that actually send somewhere, and say what happened.
 *
 * The failure these guard against is a form that looks complete and does nothing: no error surfaced
 * when the server refuses, no confirmation when it accepts, and — worst on a deletion request — a
 * cheerful «submitted» hiding the fact that an unpaid invoice will stop it.
 */

function fill(testId: string, field: string, value: string) {
  fireEvent.change(screen.getByTestId(`${testId}-${field}`), { target: { value } })
}

describe('the contact form', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('sends what was typed and confirms receipt', async () => {
    vi.mocked(sendContact).mockResolvedValue({ received: true })

    renderWithProviders(<ContactForm />, { locale: 'ar' })

    fill('contact-form', 'name', 'سارة')
    fill('contact-form', 'email', 'sara@example.test')
    fill('contact-form', 'subject', 'سؤال')
    fill('contact-form', 'message', 'نص الرسالة كامل وواضح.')
    fireEvent.click(screen.getByTestId('contact-form-submit'))

    await waitFor(() => expect(sendContact).toHaveBeenCalled())
    expect(vi.mocked(sendContact).mock.calls[0]?.[0]).toMatchObject({ name: 'سارة', email: 'sara@example.test' })
    expect(await screen.findByTestId('contact-form-success')).toBeInTheDocument()
  })

  /** No reference — there is no queue to chase, and offering a code would promise otherwise. */
  it('offers no tracking reference, because there is nothing to track', async () => {
    vi.mocked(sendContact).mockResolvedValue({ received: true })

    renderWithProviders(<ContactForm />, { locale: 'ar' })
    fill('contact-form', 'name', 'س')
    fill('contact-form', 'email', 's@e.test')
    fill('contact-form', 'subject', 'x')
    fill('contact-form', 'message', 'رسالة كافية.')
    fireEvent.click(screen.getByTestId('contact-form-submit'))

    await screen.findByTestId('contact-form-success')
    expect(screen.queryByTestId('reference-code')).toBeNull()
  })

  /**
   * A refusal is shown, and what the visitor typed survives it.
   *
   * Clearing the fields on error is the cruellest possible response to somebody who has just written
   * three paragraphs.
   */
  it('shows why a send was refused without discarding what was typed', async () => {
    vi.mocked(sendContact).mockRejectedValue({ response: { data: { message: 'تعذّر الإرسال.' } } })

    renderWithProviders(<ContactForm />, { locale: 'ar' })
    fill('contact-form', 'message', 'نص طويل كتبه الزائر ولا يجوز فقدانه.')
    fireEvent.click(screen.getByTestId('contact-form-submit'))

    expect(await screen.findByTestId('contact-form-error')).toBeInTheDocument()
    expect((screen.getByTestId('contact-form-message') as HTMLTextAreaElement).value)
      .toBe('نص طويل كتبه الزائر ولا يجوز فقدانه.')
  })
})

describe('the support form', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('returns a reference the sender can keep', async () => {
    vi.mocked(openSupportTicket).mockResolvedValue({ reference: 'CH-7GXHUK' })

    renderWithProviders(<SupportForm />, { locale: 'ar' })
    fill('support-form', 'name', 'خالد')
    fill('support-form', 'email', 'k@example.test')
    fill('support-form', 'subject', 'المزامنة متوقفة')
    fill('support-form', 'message', 'لم تصل أرقام منذ يومين رغم أن الحساب مربوط.')
    fireEvent.click(screen.getByTestId('support-form-submit'))

    expect(await screen.findByTestId('reference-code')).toHaveTextContent('CH-7GXHUK')
  })
})

describe('the data-request form', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  /**
   * The case that matters most: a deletion that cannot proceed says so immediately.
   *
   * Telling somebody «submitted» and leaving them to discover weeks later that an unpaid invoice
   * stopped it is the failure this whole path exists to prevent.
   */
  it('shows what is standing in the way of a deletion, in the readers language', async () => {
    vi.mocked(submitDataRequest).mockResolvedValue({
      reference: 'DR-7GXHUK',
      status: 'blocked',
      blockers: [
        { code: 'open_invoices', count: 2, ar: 'توجد 2 فاتورة غير مسددة.', en: 'There are 2 unsettled invoices.' },
      ],
    })

    renderWithProviders(<DataRequestForm />, { locale: 'ar' })
    fill('data-request-form', 'type', 'delete_account')
    fill('data-request-form', 'name', 'نورة')
    fill('data-request-form', 'email', 'n@example.test')
    fireEvent.click(screen.getByTestId('data-request-form-submit'))

    const blockers = await screen.findByTestId('data-request-blockers')
    expect(blockers.textContent).toMatch(/فاتورة غير مسددة/)
    // And it is explicit that the request is kept rather than dropped.
    expect(blockers.textContent).toMatch(/لن يُهمل/)
    // The reference is still returned, so the requester can follow it up.
    expect(screen.getByTestId('reference-code')).toHaveTextContent('DR-7GXHUK')
  })

  it('says nothing is in the way when a deletion is clear to proceed', async () => {
    vi.mocked(submitDataRequest).mockResolvedValue({ reference: 'DR-ACDEFG', status: 'pending', blockers: [] })

    renderWithProviders(<DataRequestForm />, { locale: 'ar' })
    fill('data-request-form', 'type', 'delete_account')
    fill('data-request-form', 'name', 'نورة')
    fill('data-request-form', 'email', 'n@example.test')
    fireEvent.click(screen.getByTestId('data-request-form-submit'))

    await screen.findByTestId('reference-code')
    expect(screen.queryByTestId('data-request-blockers')).toBeNull()
    expect(screen.getByText(/لا يوجد ما يمنع التنفيذ/)).toBeInTheDocument()
  })

  it('defaults to an export rather than a deletion', async () => {
    vi.mocked(submitDataRequest).mockResolvedValue({ reference: 'DR-ACDEFG', status: 'pending', blockers: [] })

    renderWithProviders(<DataRequestForm />, { locale: 'ar' })
    fill('data-request-form', 'name', 'نورة')
    fill('data-request-form', 'email', 'n@example.test')
    fireEvent.click(screen.getByTestId('data-request-form-submit'))

    await waitFor(() => expect(submitDataRequest).toHaveBeenCalled())
    expect(vi.mocked(submitDataRequest).mock.calls[0]?.[0].type).toBe('export')
  })
})
