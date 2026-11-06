import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { defaultTestTarget, EmailOperationsPage } from './EmailOperationsPage'
import { renderWithProviders } from '@/test/utils'
import type { EmailLedger } from './api'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchEmailLedger: vi.fn(), fetchEmailPreviews: vi.fn(), fetchEmailPreview: vi.fn(), sendTestEmail: vi.fn() }
})

import { fetchEmailLedger, fetchEmailPreview, fetchEmailPreviews, sendTestEmail } from './api'

function ledger(over: Partial<EmailLedger> = {}): EmailLedger {
  return {
    deliveries: [
      {
        id: '1', source: 'transactional', at: '2026-08-08T09:00:00Z', kind: 'password_reset',
        template: 'credential', recipient: 'sara@example.com', tenant_name: 'Acme', locale: 'ar',
        status: 'sent', state: 'accepted', transport: 'log', attempts: 1, reason: null,
      },
      {
        id: '2', source: 'digest', at: '2026-08-08T08:00:00Z', kind: 'daily',
        template: 'digest', recipient: 'omar@example.com', tenant_name: 'Acme', locale: null,
        status: 'failed', state: 'failed', transport: null, attempts: 2, reason: 'SMTP 550 mailbox unavailable',
      },
    ],
    total: 2,
    page: 1,
    per_page: 50,
    by_state: { accepted: 1, failed: 1 },
    transport: { state: 'sandbox', provider_configured: false, driver: 'log' },
    available_states: ['queued', 'attempted', 'accepted', 'delivered', 'failed', 'suppressed'],
    ...over,
  }
}

function open(data: Partial<EmailLedger> = {}) {
  vi.mocked(fetchEmailLedger).mockResolvedValue(ledger(data))
  vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'] })
  vi.mocked(fetchEmailPreview).mockResolvedValue({ key: 'alerts-bundle', locale: 'ar', html: '<p>مرحبًا</p>' })
  return renderWithProviders(<EmailOperationsPage />, { locale: 'ar' })
}

/**
 * MAIL-014 — the operator console for mail.
 *
 * The assertions are about what the page must not let an operator believe: that «sent» against a log
 * mailer means delivered, or that a healthy transactional ledger means mail is working.
 */
describe('the email operations console', () => {
  beforeEach(() => vi.clearAllMocks())

  /**
   * `sandbox` is the state that looks like success in every row beneath it.
   *
   * An operator who reads «أُرسلت» against a `log` mailer and tells a customer their invoice went
   * out has been misled by their own console.
   */
  it('warns that a sandbox mailer delivers nothing, whatever the rows say', async () => {
    open()

    expect(await screen.findByText(/وضع الاختبار — لا تصل الرسائل إلى أحد/)).toBeInTheDocument()
    expect(screen.getByText('تُكتب في السجل فقط')).toBeInTheDocument()
  })

  /** EMAIL-DELIVERY-LEDGER-001 — an SMTP 250 is «accepted», and a failure names its reason and its fix. */
  it('never calls an accepted message delivered, and names why one failed', async () => {
    open({
      deliveries: [
        { ...ledger().deliveries[0], status: 'sent', state: 'accepted' },
        { ...ledger().deliveries[1], status: 'failed', state: 'failed', failure_code: 'auth_failed', reason: 'Failed to authenticate' },
        { ...ledger().deliveries[0], id: '3', status: 'sent', state: 'delivered', evidence: 'Owner read it in Gmail' },
      ],
    })

    expect((await screen.findAllByText('قبِلها الخادم')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('تأكّد الاستلام').length).toBeGreaterThan(0)
    expect(screen.getByText(/رفض خادم البريد تسجيل الدخول/)).toBeInTheDocument()
    expect(screen.getByText('Owner read it in Gmail')).toBeInTheDocument()
    expect(screen.queryByText('أُرسلت')).not.toBeInTheDocument()
  })

  it('says plainly when nothing is wired at all', async () => {
    open({ transport: { state: 'awaiting_credentials', provider_configured: false, driver: 'smtp' } })

    expect(await screen.findByText('لا يوجد مزوّد بريد مربوط')).toBeInTheDocument()
  })

  it('confirms a live transport rather than staying silent about it', async () => {
    open({ transport: { state: 'live', provider_configured: true, driver: 'ses' } })

    expect(await screen.findByText('الإرسال مفعّل')).toBeInTheDocument()
    // The one distinction the page keeps, as two chips rather than a sentence.
    expect(screen.getByTestId('mail-stage-legend')).toHaveTextContent('قبِلها الخادم≠تأكّد الاستلام')
  })

  /** Owner: «اعتمد دائمًا الاختصارات … لا حاجة لشرح» — chips and short lines, none of the old paragraphs. */
  it('carries no explanatory paragraph', async () => {
    open({ transport: { state: 'live', provider_configured: true, driver: 'smtp' } })

    expect(await screen.findByTestId('mail-page-chips')).toHaveTextContent('سجل للقراءة فقط')
    await screen.findByText('معرض الرسائل')
    for (const gone of [/سجل ما حاولت المنصة إرساله فعلًا/, /مزوّد البريد مربوط عبر/, /هذا قبول خادم البريد وليس وصولها/, /ببيانات تجريبية، وعنوانها يحمل/, /كل رسالة يمكن للمنصة إرسالها/]) {
      expect(screen.queryByText(gone)).not.toBeInTheDocument()
    }
  })

  /** Both ledgers in one table — a page reading one would answer «is mail working?» wrongly. */
  it('shows a digest failure beside a transactional success', async () => {
    open()

    expect(await screen.findByText('sara@example.com')).toBeInTheDocument()
    expect(screen.getByText('omar@example.com')).toBeInTheDocument()
    expect(screen.getByText('SMTP 550 mailbox unavailable')).toBeInTheDocument()
  })

  /** The gallery renders fixtures in a frame that can run nothing. */
  it('renders a preview inside a sandboxed frame', async () => {
    const { container } = open()

    await screen.findByText('معرض الرسائل')
    const frame = await vi.waitFor(() => {
      const f = container.querySelector('iframe')
      expect(f).not.toBeNull()
      return f as HTMLIFrameElement
    })

    expect(frame.getAttribute('sandbox')).toBe('')
    expect(frame.getAttribute('srcdoc')).toContain('مرحبًا')
  })
})

describe('the mail settings card', () => {
  beforeEach(() => vi.clearAllMocks())

  it('states the sender and the last outcomes, and sends the test to the operator only', async () => {
    open({
      transport: {
        state: 'awaiting_credentials', provider_configured: false, driver: 'smtp',
        sender: { address: 'no-reply@campaignshub.io', name: 'CampaignsHub' }, reply_to: '',
        last_success_at: null, last_failure: { at: '2026-10-17T08:00:00Z', kind: 'password_reset', error: 'refused' },
      },
    })
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'awaiting_credentials', delivery_state: 'suppressed', suppression_reason: 'no_provider', recipient: 'owner@campaignshub.io' })

    const card = await screen.findByTestId('mail-settings')
    expect(card).toHaveTextContent('no-reply@campaignshub.io')
    expect(card).toHaveTextContent('password_reset')

    fireEvent.click(screen.getByTestId('mail-test-send'))
    await waitFor(() => expect(sendTestEmail).toHaveBeenCalledWith('ar', 'self'))
    const result = await screen.findByTestId('mail-test-result')
    expect(result).toHaveTextContent('owner@campaignshub.io')
    expect(result).toHaveTextContent('لم يغادر شيء')
  })

  /** «ولا تعتبر الرسائل مرسلة دون دليل» — acceptance is its own chip and is never the word for receipt. */
  it('shows accepted as its own state, never as delivered, in both buttons, with the Message-ID', async () => {
    open()
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'sent', delivery_state: 'accepted', recipient: 'owner@campaignshub.io', provider_message_id: 'abc@mail.test' })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    const plain = await screen.findByTestId('mail-test-result')
    expect(screen.getByTestId('mail-test-result-stage')).toHaveTextContent('قبِلها الخادم')
    expect(screen.getByTestId('mail-test-result-recipient')).toHaveTextContent('owner@campaignshub.io')
    expect(plain).toHaveTextContent('abc@mail.test')
    expect(plain).not.toHaveTextContent('تأكّد الاستلام')

    await waitFor(() => expect(screen.getByTestId('gallery-test-send')).not.toBeDisabled())
    fireEvent.click(screen.getByTestId('gallery-test-send'))
    expect(await screen.findByTestId('gallery-test-result-stage')).toHaveTextContent('قبِلها الخادم')
    expect(screen.getByTestId('gallery-test-result')).not.toHaveTextContent('تأكّد الاستلام')
    expect(sendTestEmail).toHaveBeenLastCalledWith('ar', 'self', 'alerts-bundle')
  })

  it('shows a confirmed receipt as the other chip', async () => {
    open()
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'already_sent', delivery_state: 'delivered', recipient: 'owner@campaignshub.io' })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result-stage')).toHaveTextContent('تأكّد الاستلام')
    expect(screen.getByTestId('mail-test-result')).toHaveTextContent('محاولة سابقة')
  })

  it('names why a test failed, and why it never left', async () => {
    open()
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'failed', delivery_state: 'failed', failure_code: 'auth_failed', recipient: 'o@x.test' })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result')).toHaveTextContent('رفض خادم البريد تسجيل الدخول')
  })

  /** The server refuses too; the page says so first, and sends no address either way. */
  it('holds both buttons back when the admin\'s own address was never proved and there is no mailbox', async () => {
    open()
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'], test_send: { recipient: 'o@x.test', address_proven: false, platform_mailbox: null } })

    expect((await screen.findAllByTestId('mail-test-unproven')).length).toBe(2)
    expect(screen.getAllByTestId('mail-test-no-mailbox').length).toBe(2)
    expect(screen.getByTestId('mail-test-send')).toBeDisabled()
    expect(screen.getByTestId('gallery-test-send')).toBeDisabled()
  })

  it('says the limit in words when the server stops a test', async () => {
    open()
    vi.mocked(sendTestEmail).mockRejectedValue({ isAxiosError: true, response: { status: 429, data: {} } })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result-error')).toHaveTextContent('3 في الدقيقة و20 في اليوم')
  })

  it('says which refusal it was', async () => {
    open()
    vi.mocked(sendTestEmail).mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { success: false, message: 'x', data: null, errors: { code: 'platform_mailbox_not_configured' } } } })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result-error')).toHaveTextContent('بريد المنصة غير مضبوط')
  })
})

/**
 * EMAIL-TEST-SEND-002 — Owner, Production 2026-11-06: «ولا وصل البريد … عندي انفو فقط».
 *
 * The test went, correctly, to a sign-in address with no mailbox behind it, and the page offered nowhere else.
 */
describe('where a test goes', () => {
  beforeEach(() => vi.clearAllMocks())

  const owner = { recipient: 'admin@campaignshub.io', address_proven: true, platform_mailbox: 'info@campaignshub.io' }

  it('defaults to the platform mailbox only when the admin is on the platform\'s own domain and is not that mailbox', () => {
    expect(defaultTestTarget(owner)).toBe('platform_mailbox')
    expect(defaultTestTarget({ ...owner, recipient: 'Admin@CampaignsHub.IO' })).toBe('platform_mailbox')
    expect(defaultTestTarget({ ...owner, recipient: 'info@campaignshub.io' })).toBe('self')
    expect(defaultTestTarget({ ...owner, recipient: 'me@gmail.com' })).toBe('self')
    expect(defaultTestTarget({ ...owner, recipient: 'x@notcampaignshub.io' })).toBe('self')
    expect(defaultTestTarget({ ...owner, platform_mailbox: null })).toBe('self')
    expect(defaultTestTarget({ recipient: 'admin@campaignshub.io', address_proven: true })).toBe('self')
    expect(defaultTestTarget(undefined)).toBe('self')
    // An address nobody proved cannot be tested, so the usable one is offered.
    expect(defaultTestTarget({ ...owner, recipient: 'me@gmail.com', address_proven: false })).toBe('platform_mailbox')
  })

  it('shows both addresses as two choices, sends to the chosen one and names where it went', async () => {
    open()
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'], test_send: owner })
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'sent', delivery_state: 'accepted', to: 'platform_mailbox', recipient: 'info@campaignshub.io' })

    const mailbox = await screen.findByTestId('mail-test-target-platform_mailbox')
    const mine = screen.getByTestId('mail-test-target-self')
    expect(mailbox).toHaveTextContent('بريد المنصة')
    expect(mailbox).toHaveTextContent('info@campaignshub.io')
    expect(mine).toHaveTextContent('بريدي')
    expect(mine).toHaveTextContent('admin@campaignshub.io')
    expect(mailbox).toHaveAttribute('aria-checked', 'true')
    expect(mine).toHaveAttribute('aria-checked', 'false')
    // A choice of two, and nowhere to type an address.
    expect(screen.getByTestId('mail-settings').querySelector('input')).toBeNull()

    fireEvent.click(screen.getByTestId('mail-test-send'))
    await waitFor(() => expect(sendTestEmail).toHaveBeenCalledWith('ar', 'platform_mailbox'))
    expect(await screen.findByTestId('mail-test-result-recipient')).toHaveTextContent('info@campaignshub.io')

    // One choice for both buttons.
    fireEvent.click(mine)
    expect(screen.getByTestId('gallery-test-target-self')).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(screen.getByTestId('gallery-test-send'))
    await waitFor(() => expect(sendTestEmail).toHaveBeenLastCalledWith('ar', 'self', 'alerts-bundle'))
  })

  it('keeps an outside address on itself', async () => {
    open()
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'], test_send: { ...owner, recipient: 'me@gmail.com' } })
    expect(await screen.findByTestId('mail-test-target-self')).toHaveAttribute('aria-checked', 'true')
  })

  it('offers an unproven admin the mailbox and not their own address', async () => {
    open()
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'], test_send: { ...owner, address_proven: false } })

    expect(await screen.findByTestId('mail-test-target-self')).toBeDisabled()
    expect(screen.getByTestId('mail-test-target-platform_mailbox')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByTestId('mail-test-send')).not.toBeDisabled()
  })
})

describe('reviewing a template', () => {
  beforeEach(() => vi.clearAllMocks())

  it('names it in words and shows the subject, the test subject, the text part and whether it can be stopped', async () => {
    vi.mocked(fetchEmailLedger).mockResolvedValue(ledger())
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['account-password-reset'], locales: ['ar', 'en'], test_send: { recipient: 'o@x.test', address_proven: true } })
    vi.mocked(fetchEmailPreview).mockResolvedValue({
      key: 'account-password-reset', locale: 'ar', html: '<p>x</p>', subject: 'CampaignsHub — إعادة تعيين كلمة المرور',
      test_subject: 'CampaignsHub — [اختبار] إعادة تعيين كلمة المرور', text: 'CampaignsHub النص', mandatory: true,
    })
    renderWithProviders(<EmailOperationsPage />, { locale: 'ar' })

    const review = await screen.findByTestId('gallery-review')
    expect(review).toHaveTextContent('CampaignsHub — إعادة تعيين كلمة المرور')
    expect(review).toHaveTextContent('[اختبار]')
    expect(review).toHaveTextContent('لا · حساب وأمان')
    expect(screen.getByRole('option', { name: 'استعادة كلمة المرور' })).toBeInTheDocument()
    expect(screen.getByTestId('gallery-text-part')).toHaveTextContent('CampaignsHub النص')
  })

  it('shows a test in the ledger as a test, and counts it apart', async () => {
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: [], locales: ['ar', 'en'] })
    vi.mocked(fetchEmailLedger).mockResolvedValue(ledger({
      by_state: {}, test_sends: 1,
      deliveries: [{ ...ledger().deliveries[0], kind: 'test', recipient: 'o***@x.test', is_test: true }],
    }))
    renderWithProviders(<EmailOperationsPage />, { locale: 'ar' })

    expect(await screen.findByText('رسالة اختبار')).toBeInTheDocument()
    expect(screen.getByTestId('mail-test-count')).toHaveTextContent('لا تُحسب')
    expect(screen.getByText('o***@x.test')).toBeInTheDocument()
  })
})
