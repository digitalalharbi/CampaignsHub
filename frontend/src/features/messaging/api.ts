import { api, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * Messaging API layer — client ⇄ internal-team threads. Mirrors the tenant-scoped backend
 * (routes/api/messaging.php). Self-contained to this feature. Reads need messaging.view; posting a reply,
 * opening a thread and marking read need messaging.manage.
 */

export type ThreadStatus = 'open' | 'closed'
export type AuthorType = 'client' | 'team' | 'system'

/** A conversation. Mirrors backend MessageThread. Unread is NOT stored here — it comes from the show payload. */
export interface MessageThread {
  id: string
  tenant_id: string | null
  client_workspace_id: string | null
  request_id: string | null
  project_id: string | null
  subject: string
  status: ThreadStatus | string
  last_message_at: string | null
  created_by: string | null
  created_at: string | null
}

/** One message in a thread. Mirrors backend Message. */
export interface Message {
  id: string
  tenant_id: string | null
  thread_id: string
  author_type: AuthorType | string
  author_user_id: string | null
  body: string
  attachments: unknown[] | null
  read_by_client_at: string | null
  read_by_team_at: string | null
  created_at: string | null
}

/** The show payload: the thread, its ordered messages, and per-side unread counts. */
export interface ThreadDetail {
  thread: MessageThread
  messages: Message[]
  unread: { client: number; team: number }
  /**
   * How long the conversation actually is, and how much of it this window left out.
   *
   * Optional because a cached payload from before the field existed must not be read as «nothing was
   * withheld» — `undefined` means unknown, and the panel says nothing rather than something false.
   */
  messages_total?: number
  messages_withheld?: number
  /** The cursor for the window before this one — `null` at the start of the conversation. */
  older_before?: string | null
}

export async function listThreads(status?: ThreadStatus): Promise<MessageThread[]> {
  const res = await api.get<ApiEnvelope<MessageThread[]>>('/messaging/threads', {
    params: status ? { status } : {},
  })
  return res.data.data ?? []
}

/**
 * One window of a thread. `before` is the cursor for the window OLDER than the one in hand.
 *
 * A cursor rather than a page number, because a conversation grows while it is being read: the third
 * page of a thread somebody is still replying to is not the same three hundred messages it was a
 * minute ago, and a cursor keeps the boundary attached to a message instead of to an offset.
 */
export const getThread = (id: string, before?: string | null) =>
  getData<ThreadDetail>(
    `/messaging/threads/${encodeURIComponent(id)}${before ? `?before=${encodeURIComponent(before)}` : ''}`,
  )

export interface NewThread {
  subject: string
  body?: string
  client_workspace_id?: string | null
  request_id?: string | null
  project_id?: string | null
}

export const openThread = (body: NewThread) => postData<MessageThread>('/messaging/threads', body)

/** Post a reply from the internal team side. */
export const postTeamReply = (threadId: string, body: string) =>
  postData<Message>(`/messaging/threads/${encodeURIComponent(threadId)}/messages`, {
    author_type: 'team',
    body,
  })

/** Clear the team side's unread counter for a thread. Returns the cleared count + remaining unread. */
export const markThreadRead = (threadId: string, side: 'client' | 'team' = 'team') =>
  postData<{ cleared: number; unread: number }>(`/messaging/threads/${encodeURIComponent(threadId)}/read`, { side })

// ---------------------------------------------------------------------------
// Formatting helpers (Latin digits — a product rule)
// ---------------------------------------------------------------------------

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return `${d.toLocaleDateString('en-CA')} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}
