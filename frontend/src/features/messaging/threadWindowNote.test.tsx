import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ThreadsPage } from './ThreadsPage'
import type { ThreadDetail, MessageThread } from './api'

/**
 * MESSAGE-THREAD-TRUTH-001 — a windowed conversation says so, on the surface that renders it.
 *
 * The server now sends the newest messages and the two counts, and `MessageThreadWindowTest` holds
 * that. None of it reaches a reader unless the panel prints it: the failure mode being guarded is a
 * page that ends after five hundred messages and looks exactly like a whole conversation.
 *
 * `messages_withheld: 0` is asserted as its own case rather than folded in. A note that appears
 * whenever the field is present would put «older messages are not shown» on every short thread in
 * the product — which is its own kind of untrue.
 */
vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listThreads: vi.fn(), getThread: vi.fn(), markThreadRead: vi.fn(), postTeamReply: vi.fn() }
})

import { getThread, listThreads } from './api'

const thread: MessageThread = {
  id: 't1',
  subject: 'A long conversation',
  status: 'open',
  client_workspace_id: null,
  request_id: null,
  project_id: null,
  last_message_at: '2026-09-11T10:00:00Z',
  created_at: '2026-01-01T10:00:00Z',
} as MessageThread

const message = (n: number) => ({
  id: `m${n}`,
  thread_id: 't1',
  author_type: n % 2 === 0 ? 'client' : 'team',
  body: `message ${n}`,
  created_at: '2026-09-11T10:00:00Z',
})

/** A detail payload whose window holds `shown` of `total`. */
const detail = (shown: number, total: number): ThreadDetail =>
  ({
    thread,
    messages: Array.from({ length: shown }, (_, i) => message(total - shown + i + 1)),
    unread: { client: 0, team: 0 },
    messages_total: total,
    messages_withheld: total - shown,
  }) as ThreadDetail

describe('a windowed conversation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['messaging.view', 'messaging.manage'])
    vi.mocked(listThreads).mockResolvedValue([thread])
  })
  afterEach(() => signOut())

  const openThread = async () => {
    const row = await screen.findByText('A long conversation')
    row.click()
  }

  it('tells the reader the older messages are not on the page', async () => {
    vi.mocked(getThread).mockResolvedValue(detail(500, 520))

    renderWithProviders(<ThreadsPage />, { locale: 'en' })
    await openThread()

    const note = await screen.findByTestId('thread-window-note')

    expect(note).toHaveTextContent('500')
    expect(note).toHaveTextContent('520')
    expect(note.textContent).toMatch(/Older ones are not shown/i)
  })

  it('says it in Arabic too, where the client reads it', async () => {
    vi.mocked(getThread).mockResolvedValue(detail(500, 520))

    renderWithProviders(<ThreadsPage />, { locale: 'ar' })
    await openThread()

    const note = await screen.findByTestId('thread-window-note')

    expect(note.textContent).toMatch(/الرسائل الأقدم غير معروضة/)
    /* Latin digits — the product's numeral rule does not follow the language. */
    expect(note).toHaveTextContent('520')
  })

  it('says nothing when the whole conversation is on the page', async () => {
    vi.mocked(getThread).mockResolvedValue(detail(3, 3))

    renderWithProviders(<ThreadsPage />, { locale: 'en' })
    await openThread()

    await screen.findByText('message 3')
    expect(screen.queryByTestId('thread-window-note')).toBeNull()
  })

  /**
   * And a payload from before the server sent the counts is UNKNOWN, not «nothing withheld».
   *
   * The distinction matters on exactly the page this defect lived on: a cached response with no
   * counts must not be reported as a complete conversation.
   */
  it('claims nothing when the server did not say', async () => {
    vi.mocked(getThread).mockResolvedValue({
      thread,
      messages: [message(1)],
      unread: { client: 0, team: 0 },
    } as ThreadDetail)

    renderWithProviders(<ThreadsPage />, { locale: 'en' })
    await openThread()

    await screen.findByText('message 1')
    expect(screen.queryByTestId('thread-window-note')).toBeNull()
  })
})
