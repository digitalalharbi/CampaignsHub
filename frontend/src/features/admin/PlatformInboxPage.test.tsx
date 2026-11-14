import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PlatformInboxPage } from './PlatformInboxPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchInboxSummary: vi.fn(), fetchInbox: vi.fn(), fetchInboxItem: vi.fn(), replyToInboxItem: vi.fn(), updateInboxStatus: vi.fn(),
  }
})

import { fetchInbox, fetchInboxItem, fetchInboxSummary, replyToInboxItem, updateInboxStatus, type ContactMessageRow } from './api'

const message: ContactMessageRow = {
  id: 'c1a2b3c4-0000-4000-8000-000000000001', name: 'Sarah', email: 'sarah@example.test', phone: null, status: 'new', read_at: null,
  created_at: '2026-11-12T09:00:00Z', locale: 'ar', company: null, topic: null, source: 'contact_page',
  subject: 'Plans', message: 'Which plan suits three ad accounts?',
}

/** PLATFORM-INBOX-ATTENTION-001 — «الوارد»: unread counted per queue, opened, moved and answered. */
describe('PlatformInboxPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchInboxSummary).mockResolvedValue({
      contact: { unread: 1, open: 1 }, support: { unread: 2, open: 2 }, data: { unread: 0, open: 0 }, unread: 3,
    })
    vi.mocked(fetchInbox).mockResolvedValue({ rows: [message], total: 1, unread: 1 })
    vi.mocked(fetchInboxItem).mockResolvedValue({ row: { ...message, status: 'read', read_at: '2026-11-12T10:00:00Z' }, replies: [] })
  })

  it('counts what nobody has opened in each queue and marks the unread row', async () => {
    renderWithProviders(<PlatformInboxPage />, { locale: 'ar' })

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('الوارد')
    await waitFor(() => expect(screen.getByTestId('inbox-unread-contact')).toHaveTextContent('1'))
    expect(screen.getByTestId('inbox-unread-support')).toHaveTextContent('2')
    expect(screen.getByTestId('inbox-unread-data')).toHaveTextContent('0')
    expect(await screen.findByTestId('inbox-row')).toHaveAttribute('data-unread', 'true')
    expect(fetchInbox).toHaveBeenCalledWith('contact', 1)
  })

  it('switches queue', async () => {
    renderWithProviders(<PlatformInboxPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('inbox-tab-data'))

    await waitFor(() => expect(fetchInbox).toHaveBeenCalledWith('data', 1))
    expect(screen.getByTestId('inbox-tab-data')).toHaveAttribute('aria-selected', 'true')
  })

  it('opens a message from the link in the notice, and replies to it with the ledger state shown', async () => {
    vi.mocked(replyToInboxItem).mockResolvedValue({ delivery_status: 'awaiting_provider_credentials' })
    renderWithProviders(<PlatformInboxPage />, { locale: 'en', route: `/admin/inbox?tab=contact&open=${message.id}` })

    expect(await screen.findByTestId('inbox-body')).toHaveTextContent('Which plan suits three ad accounts?')
    expect(fetchInboxItem).toHaveBeenCalledWith('contact', message.id)

    const send = screen.getByTestId('inbox-reply-send')
    expect(send).toBeDisabled()
    fireEvent.change(screen.getByTestId('inbox-reply'), { target: { value: '  The Team plan.  ' } })
    vi.mocked(fetchInboxItem).mockResolvedValue({
      row: { ...message, status: 'answered', read_at: '2026-11-12T10:00:00Z' },
      replies: [{ id: 'r1', author: 'Platform Owner', body: 'The Team plan.', delivery_status: 'awaiting_provider_credentials', at: '2026-11-12T10:05:00Z' }],
    })
    fireEvent.click(send)

    await waitFor(() => expect(replyToInboxItem).toHaveBeenCalledWith('contact', message.id, 'The Team plan.'))
    const delivery = await screen.findByTestId('inbox-reply-delivery')
    expect(delivery).toHaveAttribute('data-status', 'awaiting_provider_credentials')
    expect(delivery).toHaveTextContent('Mail not set up')
  })

  it('moves a status, and offers no reply on a data request', async () => {
    vi.mocked(updateInboxStatus).mockResolvedValue({})
    vi.mocked(fetchInboxItem).mockResolvedValue({
      row: {
        id: 'd1', name: 'Ali', email: 'ali@example.test', phone: null, status: 'pending', read_at: null, created_at: '2026-11-12T09:00:00Z',
        locale: 'ar', reference: 'DR-1', type: 'export', details: 'Export my data', source: 'web', verified_at: null,
      },
      replies: [],
    })
    renderWithProviders(<PlatformInboxPage />, { locale: 'en', route: '/admin/inbox?tab=data&open=d1' })

    expect(await screen.findByTestId('inbox-body')).toHaveTextContent('Export my data')
    expect(screen.queryByTestId('inbox-reply')).toBeNull()

    fireEvent.change(screen.getByTestId('inbox-status'), { target: { value: 'in_review' } })
    fireEvent.click(screen.getByTestId('inbox-status-save'))
    await waitFor(() => expect(updateInboxStatus).toHaveBeenCalledWith('data', 'd1', 'in_review'))
  })
})
