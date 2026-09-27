import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NotificationCenter } from './NotificationCenter'
import * as api from './api'
import { useUi } from '@/stores/ui'

/**
 * UX-NOTIFICATION-CARD-001 — the centre, rendered, keeping the two facts it used to lose.
 *
 * The presentation rules have their own unit tests; this asserts the centre actually shows them,
 * because the defect was never in the rule — it was that the row threw the answer away. A read
 * critical alert and a read info note were the same object on screen.
 */
const rows = [
  {
    id: 'n-critical-read',
    type: 'sync.failed',
    severity: 'critical' as const,
    title: 'Sync failed',
    message: 'Snapchat refused the last sweep',
    project_id: 'p-1',
    client_workspace_id: null,
    action_url: null,
    status: 'read' as const,
    read_at: '2026-09-26T09:00:00.000Z',
    created_at: new Date().toISOString(),
  },
  {
    id: 'n-info-portfolio',
    type: 'report.ready',
    severity: 'info' as const,
    title: 'Report ready',
    message: null,
    project_id: null,
    client_workspace_id: null,
    action_url: null,
    status: 'unread' as const,
    read_at: null,
    created_at: new Date().toISOString(),
  },
]

function mount() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <NotificationCenter />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the notification centre', () => {
  beforeEach(() => {
    vi.spyOn(api, 'listNotifications').mockResolvedValue({
      items: rows, unread: 1, total: rows.length, withheld: 0,
    } as never)
  })

  /** The bell has to be opened before the list exists; the count is what invites the click. */
  async function open() {
    const { container } = mount()
    await waitFor(() => expect(screen.getByRole('button', { name: /.*/ })).toBeInTheDocument())
    screen.getAllByRole('button')[0]!.click()

    return container
  }

  it('keeps a critical row marked as critical after it has been read', async () => {
    await open()

    const row = await waitFor(() => screen.getByTestId('notification-n-critical-read'))
    expect(row.getAttribute('data-severity')).toBe('danger')
  })

  it('says which scope each row is about', async () => {
    await open()

    await waitFor(() => expect(screen.getByTestId('notification-n-info-portfolio')).toBeInTheDocument())
    expect(screen.getByTestId('notification-n-info-portfolio').getAttribute('data-scope')).toBe('portfolio')
    expect(screen.getByTestId('notification-n-critical-read').getAttribute('data-scope')).toBe('project')
  })

  /** A day heading, so twelve near-identical timestamps stop being the reader's index. */
  it('groups the rows under a day heading', async () => {
    await open()

    await waitFor(() => expect(screen.getByText(/Today|اليوم/)).toBeInTheDocument())
  })

  /**
   * The centre speaks the reader's language.
   *
   * Every title is composed on the server as one English string — «New client message», «Client
   * needs attention: Acme» — so an Arabic panel, whose own heading, severity chips and scope chips
   * are Arabic, listed English sentences. Seen in a browser on the agency dashboard.
   *
   * `messageLabels` already holds the Arabic name of every catalogue type for the settings screen,
   * and `EveryMessageTypeIsNamedTest` keeps that map in step with `MessageCatalogue::keys()`. The
   * name existed; the panel never asked for it.
   */
  it('names a known type in Arabic and keeps the server’s specifics', async () => {
    /* Set explicitly: the panel reads the store, and a test that inherits it asserts the default. */
    useUi.setState({ locale: 'ar' })

    vi.spyOn(api, 'listNotifications').mockResolvedValue({
      items: [{
        id: 'n-msg', type: 'message', severity: 'info' as const,
        title: 'Client needs attention: Acme', message: null,
        project_id: null, client_workspace_id: null, action_url: null,
        status: 'unread' as const, read_at: null, created_at: new Date().toISOString(),
      }],
      unread: 1, total: 1, withheld: 0,
    } as never)

    mount()
    screen.getAllByRole('button')[0]!.click()

    /* The type's own name leads… */
    expect(await screen.findByText('رسالة جديدة')).toBeInTheDocument()
    /* …and the server's sentence is not thrown away: it carries WHICH client. */
    expect(screen.getByText('Client needs attention: Acme')).toBeInTheDocument()
  })

  /**
   * A type the product has no name for keeps the server's title.
   *
   * `request.journey.proposal_sent` is composed at dispatch and is not a catalogue key, so no label
   * exists for it. A blank or a database key would both be worse than the real sentence the server
   * already wrote.
   */
  it('keeps the server title for a type it has no name for', async () => {
    vi.spyOn(api, 'listNotifications').mockResolvedValue({
      items: [{
        id: 'n-j', type: 'request.journey.proposal_sent', severity: 'info' as const,
        title: 'Request REQ-9 → Proposal sent', message: null,
        project_id: null, client_workspace_id: null, action_url: null,
        status: 'unread' as const, read_at: null, created_at: new Date().toISOString(),
      }],
      unread: 1, total: 1, withheld: 0,
    } as never)

    mount()
    screen.getAllByRole('button')[0]!.click()

    expect(await screen.findByText('Request REQ-9 → Proposal sent')).toBeInTheDocument()
  })
})
