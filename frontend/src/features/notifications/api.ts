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
export async function listNotifications(): Promise<{
  items: AppNotification[]
  unread: number
  total: number
  withheld: number
}> {
  const res = await api.get<ApiEnvelope<AppNotification[]>>('/notifications')
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
export const markAllNotificationsRead = () => api.post('/notifications/read-all')

export async function listDeliveries(): Promise<NotificationDeliveryRow[]> {
  const res = await api.get<ApiEnvelope<NotificationDeliveryRow[]>>('/notifications/deliveries')
  return res.data.data ?? []
}
