import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { NotificationCenter } from './NotificationCenter'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

/**
 * PROJECT-NOTIFICATION-SCOPE-001 — the agency's own messages are marked; a client's are not.
 *
 * «ارتفعت تكلفة الطلب في مشروع رزة أفينيو» and «انتهت صلاحية تفويض سناب شات» are different kinds of
 * message. One is about one client's money; the other is about the agency's plumbing and belongs to
 * the whole team. Drawn identically, the second invites somebody to go looking for the client it is
 * about.
 *
 * Only the portfolio case is chipped, deliberately. Inside a project almost everything is that
 * project's, and a badge on every row is a badge nobody reads — so the test asserts the ABSENCE on a
 * project-scoped row as carefully as the presence on an agency-wide one.
 */

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listNotifications: vi.fn(), markNotificationRead: vi.fn(), markAllNotificationsRead: vi.fn() }
})

import { listNotifications } from './api'

const row = (over: Record<string, unknown>) => ({
  id: 'n1',
  type: 'operational',
  severity: 'info' as const,
  title: 'عنوان',
  message: null,
  project_id: null,
  scope: 'portfolio' as const,
  client_workspace_id: null,
  action_url: null,
  status: 'unread' as const,
  read_at: null,
  created_at: '2026-09-25T08:00:00Z',
  ...over,
})

const feed = (items: unknown[]) => ({ items, unread: items.length, total: items.length, withheld: 0 })

describe('the notification centre tells the two scopes apart', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['notifications.view'])
  })
  afterEach(() => signOut())

  /** The agency's own plumbing is marked, so nobody hunts for the client it is about. */
  it('marks an agency-wide notification', async () => {
    vi.mocked(listNotifications).mockResolvedValue(feed([
      row({ id: 'n1', title: 'انتهت صلاحية التفويض', scope: 'portfolio', project_id: null }),
    ]) as never)

    renderWithProviders(<NotificationCenter />, { locale: 'ar' })

    screen.getByRole('button').click()

    await waitFor(() => expect(screen.getByTestId('notification-portfolio-chip')).toBeInTheDocument())
  })

  /** A client's message is not chipped — a badge on every row is a badge nobody reads. */
  it('leaves a project notification unmarked', async () => {
    vi.mocked(listNotifications).mockResolvedValue(feed([
      row({ id: 'n2', title: 'ارتفعت تكلفة الطلب', scope: 'project', project_id: 'p1' }),
    ]) as never)

    renderWithProviders(<NotificationCenter />, { locale: 'ar' })

    screen.getByRole('button').click()

    await waitFor(() => expect(screen.getByText('ارتفعت تكلفة الطلب')).toBeInTheDocument())
    expect(screen.queryByTestId('notification-portfolio-chip')).not.toBeInTheDocument()
  })
})
