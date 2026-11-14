import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ServiceRequestsPage } from './ServiceRequestsPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual, fetchServiceRequests: vi.fn(), fetchServiceRequest: vi.fn(), updateServiceRequest: vi.fn(), replyToServiceRequest: vi.fn(),
    // PLATFORM-PAYMENT-ALERTS-001 — the alerts panel above the list has its own test file.
    fetchPlatformAlerts: vi.fn().mockResolvedValue({ alerts: [], counts: { open: 0, critical: 0, by_kind: {} }, settlement_check: 'BLOCKED_EXTERNAL_CREDENTIALS' }),
  }
})

import { fetchServiceRequest, fetchServiceRequests, replyToServiceRequest, updateServiceRequest, type ServiceRequestDetail, type ServiceRequestList } from './api'

const row = {
  id: '01JREQ', reference: 'REQ-2026-ABC123',
  type: { key: 'paid_campaign_launch', name_ar: 'إطلاق حملة إعلانية مدفوعة', name_en: 'Launch a paid campaign' },
  status: { key: 'new', name_ar: 'جديد', name_en: 'New', is_terminal: false },
  priority: 'medium', company: 'Visitor Co', contact_name: 'Visitor', assignee: null,
  submitted_at: '2026-11-11T09:00:00Z', last_activity_at: '2026-11-11T09:00:00Z', sla_due_at: '2026-11-13T09:00:00Z',
  overdue: false, source: 'campaignshub_site',
}

const list: ServiceRequestList = {
  requests: [row], total: 1, page: 1, per_page: 25, last_page: 1,
  counts: { open: 1, unassigned: 1, overdue: 0 },
  statuses: [
    { key: 'new', name_ar: 'جديد', name_en: 'New', is_terminal: false },
    { key: 'under_review', name_ar: 'تحت المراجعة', name_en: 'Under Review', is_terminal: false },
  ],
  administrators: [{ id: 7, name: 'Platform Owner' }],
}

const detail: ServiceRequestDetail = {
  ...row,
  contact: { name: 'Visitor', email: 'visitor@example.test', phone: '+966500000000', company: 'Visitor Co' },
  objective: 'More sales', budget: 25000, currency: 'SAR', services: ['a', 'b'],
  next_statuses: ['under_review'],
  messages: [{ id: 1, from_visitor: true, author: 'Client', body: 'Here is the account id.', at: '2026-11-11T10:00:00Z' }],
  files: [{ id: 3, name: 'brief.pdf', size: 20480, mime: 'application/pdf', at: '2026-11-11T09:05:00Z' }],
  activity: [{ type: 'created', from: null, to: 'new', message: 'Request submitted', actor_id: null, at: '2026-11-11T09:00:00Z' }],
}

/** PLATFORM-SERVICE-INBOX-001 — the platform owner's «الطلبات»: counted, listed, opened, owned and moved. */
describe('ServiceRequestsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchServiceRequests).mockResolvedValue(list)
    vi.mocked(fetchServiceRequest).mockResolvedValue({ request: detail })
  })

  it('answers with the three counters and the row before anything is opened', async () => {
    renderWithProviders(<ServiceRequestsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('service-request-REQ-2026-ABC123')).toHaveTextContent('إطلاق حملة إعلانية مدفوعة')
    expect(screen.getByTestId('service-requests-count-open')).toHaveTextContent('1')
    expect(screen.getByTestId('service-requests-count-unassigned')).toHaveTextContent('1')
    expect(screen.getByTestId('service-requests-count-overdue')).toHaveTextContent('0')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('الطلبات')
    expect(screen.getByTestId('service-request-REQ-2026-ABC123')).toHaveTextContent('بدون')
    expect(fetchServiceRequests).toHaveBeenCalledWith('open', 1)
  })

  it('narrows to the requests nobody has picked up', async () => {
    renderWithProviders(<ServiceRequestsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('service-requests-filter-unassigned'))

    await waitFor(() => expect(fetchServiceRequests).toHaveBeenCalledWith('unassigned', 1))
  })

  it('opens a request, picks its owner and moves it, sending only what changed', async () => {
    vi.mocked(updateServiceRequest).mockResolvedValue({
      request: { ...detail, assignee: { id: 7, name: 'Platform Owner' }, status: list.statuses[1] },
    })
    renderWithProviders(<ServiceRequestsPage />, { locale: 'en' })

    fireEvent.click((await screen.findByTestId('service-request-REQ-2026-ABC123')).querySelector('button')!)

    const save = await screen.findByTestId('service-request-save')
    expect(await screen.findByText('visitor@example.test')).toBeInTheDocument()
    expect(save).toBeDisabled()

    fireEvent.change(screen.getByTestId('service-request-owner'), { target: { value: '7' } })
    fireEvent.change(screen.getByTestId('service-request-status'), { target: { value: 'under_review' } })
    fireEvent.click(save)

    await waitFor(() => expect(updateServiceRequest).toHaveBeenCalledWith('01JREQ', { assigned_to: 7, status: 'under_review' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Saved')
  })

  it('shows what the requester sent after ordering: their reply and their file', async () => {
    renderWithProviders(<ServiceRequestsPage />, { locale: 'ar' })

    fireEvent.click((await screen.findByTestId('service-request-REQ-2026-ABC123')).querySelector('button')!)

    const messages = await screen.findByTestId('service-request-messages')
    expect(messages).toHaveTextContent('صاحب الطلب')
    expect(messages).toHaveTextContent('Here is the account id.')
    const link = screen.getByTestId('service-request-files').querySelector('a')!
    expect(link).toHaveAttribute('href', '/api/v1/admin/service-requests/01JREQ/files/3')
    expect(link).toHaveTextContent('20 KB')
  })

  it('pages past the first page and goes back to the first when the filter changes', async () => {
    vi.mocked(fetchServiceRequests).mockResolvedValue({ ...list, total: 30, last_page: 2 })
    renderWithProviders(<ServiceRequestsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('service-requests-pager-next'))
    await waitFor(() => expect(fetchServiceRequests).toHaveBeenCalledWith('open', 2))

    fireEvent.click(screen.getByTestId('service-requests-filter-unassigned'))
    await waitFor(() => expect(fetchServiceRequests).toHaveBeenLastCalledWith('unassigned', 1))
  })

  it('says there is nothing rather than drawing an empty table', async () => {
    vi.mocked(fetchServiceRequests).mockResolvedValue({ ...list, requests: [], total: 0, counts: { open: 0, unassigned: 0, overdue: 0 } })
    renderWithProviders(<ServiceRequestsPage />, { locale: 'ar' })

    expect(await screen.findByText('ما فيه طلبات')).toBeInTheDocument()
    expect(screen.queryByTestId('service-requests-table')).toBeNull()
  })

  /* PLATFORM-INBOX-ATTENTION-001 */
  it('answers the requester and shows the email reply with its real delivery state', async () => {
    vi.mocked(replyToServiceRequest).mockResolvedValue({
      request: { ...detail, replies: [{ id: 'r1', author: 'Platform Owner', body: 'We can start next week.', delivery_status: 'sent', at: '2026-11-12T10:00:00Z' }] },
    })
    renderWithProviders(<ServiceRequestsPage />, { locale: 'en' })

    fireEvent.click((await screen.findByTestId('service-request-REQ-2026-ABC123')).querySelector('button')!)
    fireEvent.change(await screen.findByTestId('service-request-reply'), { target: { value: 'We can start next week.' } })
    fireEvent.click(screen.getByTestId('service-request-reply-send'))

    await waitFor(() => expect(replyToServiceRequest).toHaveBeenCalledWith('01JREQ', 'We can start next week.'))
    expect(await screen.findByTestId('inbox-reply-delivery')).toHaveAttribute('data-status', 'sent')
  })
})
