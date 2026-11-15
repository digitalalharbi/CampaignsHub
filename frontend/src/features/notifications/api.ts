import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

export interface AppNotification {
  id: string
  type: string
  severity: 'info' | 'success' | 'warning' | 'critical'
  title: string
  message: string | null
  project_id: string | null
  client_workspace_id: string | null
  action_url: string | null
  /**
   * NOTIF-BELL-001 — the finding itself («no_results», «budget_90», …) and its name in the reader's language. The bell names a row by
   * `kind_label`, never by `type` (which is the preference category and is shared by five different findings). Absent on an older server.
   */
  kind?: string | null
  kind_label?: string | null
  group?: 'budget' | 'performance' | 'sync' | 'other'
  repeat_count?: number
  /** The page of the related object (budgets, integrations, alerts, reports); the bell's «افتح» button goes here. */
  open_url?: string | null
  status: 'unread' | 'read' | 'snoozed' | 'resolved'
  read_at: string | null
  created_at: string | null
  /**
   * DIGESTS-CADENCE-BUDGETS-001 — the same grammar as the alert email: the digest state, the problem drawn, the next steps.
   * Absent on an older server; `viz` null for a notification that is not an alert.
   */
  visual?: BellVisualData | null
}

export type BellState = 'ok' | 'watch' | 'bad' | 'none'

export type BellViz =
  | { type: 'before_now'; before: number; now: number; before_label: string; now_label: string }
  | { type: 'pace'; used: number; plan: number; used_label: string; plan_label: string }
  | { type: 'steps'; steps: { label: string; state: BellState }[] }
  | { type: 'count'; n: number; blocks: number; more: boolean; label: string; state: BellState }

export interface BellVisualData {
  state: BellState
  viz: BellViz | null
  steps: string[]
}

export interface NotificationDeliveryRow {
  id: string
  channel: 'in_app' | 'email'
  status: string
  attempts: number
  type: string
  title: string
  created_at: string | null
}

/**
 * The centre's list, with what it left out — OPS-LEDGER-001.
 *
 * `unread` was the only figure read from `meta`, and an unread COUNT is not a statement of
 * completeness: three hundred notifications, ninety unread, showed a hundred rows beside «90» and
 * nothing said the other two hundred existed.
 */
export type BellFilter = 'all' | 'critical' | 'budget' | 'performance' | 'sync'

const FILTERS: readonly string[] = ['critical', 'budget', 'performance', 'sync']

/** `filter` is read defensively: a caller that hands this function straight to `useQuery` passes the query context here. */
export async function listNotifications(filter: BellFilter | unknown = 'all'): Promise<{
  items: AppNotification[]
  unread: number
  total: number
  withheld: number
}> {
  const res = await api.get<ApiEnvelope<AppNotification[]>>('/notifications', { params: typeof filter === 'string' && FILTERS.includes(filter) ? { group: filter } : undefined })
  const meta = res.data.meta as { unread?: number; total?: number; withheld?: number } | undefined
  const items = res.data.data ?? []

  return {
    items,
    unread: meta?.unread ?? 0,
    total: meta?.total ?? items.length,
    withheld: meta?.withheld ?? 0,
  }
}

export const markNotificationRead = (id: string) => api.post(`/notifications/${id}/read`)
/** One call on the server: every unread notification this reader can see, not only the page the panel loaded. */
export const markAllNotificationsRead = () => api.post('/notifications/read-all')

/**
 * NOTIF-BELL-001 — the bell's live figure: the REAL unread count, and the unread critical / warning ids a sound may be played for.
 * Small enough to ask every half minute while the tab is visible; the list is only fetched when the panel is open.
 */
export interface BellSummary {
  unread: number
  attention: { id: string; severity: 'critical' | 'warning' }[]
  /** Sound settings and whether it is quiet hours for this reader right now. Absent on an older server. */
  sound?: BellSoundState
}

export interface BellSoundState {
  enabled: boolean
  /** 0–100 */
  volume: number
  quiet: boolean
}

export async function getNotificationSummary(): Promise<BellSummary> {
  const res = await api.get<ApiEnvelope<BellSummary>>('/notifications/summary')
  const d = res.data.data

  return { unread: d?.unread ?? 0, attention: d?.attention ?? [], sound: d?.sound }
}

export async function listDeliveries(): Promise<NotificationDeliveryRow[]> {
  const res = await api.get<ApiEnvelope<NotificationDeliveryRow[]>>('/notifications/deliveries')
  return res.data.data ?? []
}
