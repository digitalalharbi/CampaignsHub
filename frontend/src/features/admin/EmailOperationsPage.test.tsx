import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { EmailOperationsPage } from './EmailOperationsPage'
import { renderWithProviders } from '@/test/utils'
import type { EmailLedger } from './api'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchEmailLedger: vi.fn(), fetchEmailPreviews: vi.fn(), fetchEmailPreview: vi.fn() }
})

import { fetchEmailLedger, fetchEmailPreview, fetchEmailPreviews } from './api'

function ledger(over: Partial<EmailLedger> = {}): EmailLedger {
  return {
    deliveries: [
      {
        id: '1', source: 'transactional', at: '2026-08-08T09:00:00Z', kind: 'password_reset',
        template: 'credential', recipient: 'sara@example.com', tenant_name: 'Acme', locale: 'ar',
        status: 'sent', transport: 'log', attempts: 1, reason: null,
      },
      {
        id: '2', source: 'digest', at: '2026-08-08T08:00:00Z', kind: 'daily',
        template: 'digest', recipient: 'omar@example.com', tenant_name: 'Acme', locale: null,
        status: 'failed', transport: null, attempts: 2, reason: 'SMTP 550 mailbox unavailable',
      },
    ],
    total: 2,
    page: 1,
    per_page: 50,
    by_state: { sent: 1, failed: 1 },
    transport: { state: 'sandbox', provider_configured: false, driver: 'log' },
    available_states: ['failed', 'awaiting_credentials', 'sandbox', 'sent'],
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
