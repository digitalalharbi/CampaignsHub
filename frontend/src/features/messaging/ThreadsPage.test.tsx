import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ThreadsPage } from './ThreadsPage'
import type { MessageThread, ThreadDetail } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listThreads: vi.fn(),
    getThread: vi.fn(),
    postTeamReply: vi.fn(),
    markThreadRead: vi.fn(),
  }
})

import { getThread, listThreads, markThreadRead, postTeamReply } from './api'

const thread: MessageThread = {
  id: 't1', tenant_id: 'x', client_workspace_id: null, request_id: null, project_id: null,
  subject: 'Campaign kickoff', status: 'open', last_message_at: '2026-04-01T10:00:00Z',
  created_by: null, created_at: null,
}

const detail: ThreadDetail = {
  thread,
  messages: [
    { id: 'm1', tenant_id: 'x', thread_id: 't1', author_type: 'client', author_user_id: null, body: 'When do we start?', attachments: null, read_by_client_at: null, read_by_team_at: null, created_at: '2026-04-01T10:00:00Z' },
  ],
  unread: { client: 0, team: 1 },
}

describe('ThreadsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listThreads).mockResolvedValue([thread])
    vi.mocked(getThread).mockResolvedValue(detail)
    vi.mocked(markThreadRead).mockResolvedValue({ cleared: 1, unread: 0 })
    vi.mocked(postTeamReply).mockResolvedValue({ ...detail.messages[0], id: 'm2', author_type: 'team', body: 'Next week.' })
  })
  afterEach(() => signOut())

  it('opens a thread, shows its messages, unread badge and a team reply box', async () => {
    signInWith(['messaging.view', 'messaging.manage'])
    renderWithProviders(<ThreadsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByText('Campaign kickoff'))

    expect(await screen.findByText('When do we start?')).toBeInTheDocument()
    expect(screen.getByText(/1 unread/i)).toBeInTheDocument()
    expect(screen.getByPlaceholderText(/Write a reply as the team/i)).toBeInTheDocument()

    await waitFor(() => expect(getThread).toHaveBeenCalledWith('t1'))
  })

  it('posts a team reply through the real endpoint', async () => {
    signInWith(['messaging.view', 'messaging.manage'])
    renderWithProviders(<ThreadsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByText('Campaign kickoff'))

    const box = await screen.findByPlaceholderText(/Write a reply as the team/i)
    fireEvent.change(box, { target: { value: 'Next week.' } })
    fireEvent.click(screen.getByRole('button', { name: /^Send$/i }))

    await waitFor(() => expect(postTeamReply).toHaveBeenCalledWith('t1', 'Next week.'))
  })

  it('hides the reply box without messaging.manage', async () => {
    signInWith(['messaging.view'])
    renderWithProviders(<ThreadsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByText('Campaign kickoff'))
    await screen.findByText('When do we start?')
    expect(screen.queryByPlaceholderText(/Write a reply as the team/i)).not.toBeInTheDocument()
  })
})
