import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { EmailOperationsPage } from './EmailOperationsPage'
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
    expect(screen.getByText(/تعني «كُتبت في السجل»، لا «وصلت»/)).toBeInTheDocument()
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

    expect((await screen.findAllByText('قبلها خادم البريد')).length).toBeGreaterThan(0)
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
    await waitFor(() => expect(sendTestEmail).toHaveBeenCalledWith('ar'))
    const result = await screen.findByTestId('mail-test-result')
    expect(result).toHaveTextContent('owner@campaignshub.io')
    expect(result).toHaveTextContent('لم يغادر شيء')
  })

  /** «ولا تعتبر الرسائل مرسلة دون دليل» — the word for acceptance never appears without saying what it is not. */
  it('says that accepted is not delivered, in both buttons, and shows the transport\'s Message-ID', async () => {
    open()
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'sent', delivery_state: 'accepted', recipient: 'owner@campaignshub.io', provider_message_id: 'abc@mail.test' })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    const plain = await screen.findByTestId('mail-test-result')
    expect(plain).toHaveTextContent('قبلها خادم البريد')
    expect(plain).toHaveTextContent('هذا قبول خادم البريد وليس وصولها')
    expect(plain).toHaveTextContent('abc@mail.test')
    expect(plain).not.toHaveTextContent('تأكّد استلامها —')

    await waitFor(() => expect(screen.getByTestId('gallery-test-send')).not.toBeDisabled())
    fireEvent.click(screen.getByTestId('gallery-test-send'))
    expect(await screen.findByTestId('gallery-test-result')).toHaveTextContent('هذا قبول خادم البريد وليس وصولها')
    expect(sendTestEmail).toHaveBeenLastCalledWith('ar', 'alerts-bundle')
  })

  it('names why a test failed, and why it never left', async () => {
    open()
    vi.mocked(sendTestEmail).mockResolvedValue({ state: 'failed', delivery_state: 'failed', failure_code: 'auth_failed', recipient: 'o@x.test' })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result')).toHaveTextContent('رفض خادم البريد تسجيل الدخول')
  })

  /** The server refuses too; the page says so first, and sends no address either way. */
  it('holds both buttons back when the admin\'s own address was never proved', async () => {
    open()
    vi.mocked(fetchEmailPreviews).mockResolvedValue({ keys: ['alerts-bundle'], locales: ['ar', 'en'], test_send: { recipient: 'o@x.test', address_proven: false } })

    expect((await screen.findAllByTestId('mail-test-unproven')).length).toBeGreaterThan(0)
    expect(screen.getByTestId('mail-test-send')).toBeDisabled()
    expect(screen.getByTestId('gallery-test-send')).toBeDisabled()
  })

  it('says the limit in words when the server stops a test', async () => {
    open()
    vi.mocked(sendTestEmail).mockRejectedValue({ isAxiosError: true, response: { status: 429, data: {} } })

    fireEvent.click(await screen.findByTestId('mail-test-send'))
    expect(await screen.findByTestId('mail-test-result-error')).toHaveTextContent('3 في الدقيقة و20 في اليوم')
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
    expect(review).toHaveTextContent('رسالة حساب وأمان تصل دائمًا')
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
