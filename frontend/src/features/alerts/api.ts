import { api, getData, getEnvelope, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/** An alert rule (config). Mirrors backend AlertRule. */
export interface AlertRule {
  id: string
  project_id: string | null
  type: string
  name: string
  threshold: Record<string, number> | null
  cooldown_minutes: number
  channels: string[] | null
  create_task: boolean
  severity: 'info' | 'warning' | 'critical'
  active: boolean
  created_at: string | null
}

/** A firing-ledger entry. Mirrors backend AlertEvent. */
export interface AlertEvent {
  id: string
  project_id: string | null
  rule_id: string
  type: string
  entity_type: string | null
  entity_id: string | null
  status: 'open' | 'snoozed' | 'resolved'
  severity: 'info' | 'warning' | 'critical'
  context: Record<string, unknown> | null
  notification_id: string | null
  task_id: string | null
  last_triggered_at: string | null
  snoozed_until: string | null
  resolved_at: string | null
  created_at: string | null
}

export const ALERT_TYPES = [
  'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results',
  'sync_failure', 'token_expiry', 'report_failed', 'sla_warning',
] as const

export type AlertType = (typeof ALERT_TYPES)[number]

/**
 * The rules, newest first and bounded by the server.
 *
 * The envelope rather than just the rows, because `meta.total` is what lets the page say the list is
 * capped. A truncated list presented as the whole set is the kind of quiet lie this codebase does not
 * ship: somebody would go looking for a rule that exists and conclude it had been deleted.
 */
export const listAlertRules = async (): Promise<{ rules: AlertRule[]; total: number }> => {
  const envelope = await getEnvelope<AlertRule[]>('/alerts/rules')
  const rules = envelope.data ?? []

  return { rules, total: Number(envelope.meta?.total ?? rules.length) }
}

export interface NewAlertRule {
  type: AlertType
  name: string
  threshold?: Record<string, number>
  cooldown_minutes?: number
  channels?: string[]
  create_task?: boolean
  severity?: 'info' | 'warning' | 'critical'
  active?: boolean
}

export const createAlertRule = (body: NewAlertRule) => postData<AlertRule>('/alerts/rules', body)

export async function listAlertEvents(status?: 'open' | 'snoozed' | 'resolved'): Promise<AlertEvent[]> {
  const res = await api.get<ApiEnvelope<AlertEvent[]>>('/alerts/events', { params: status ? { status } : {} })
  return res.data.data ?? []
}

export const resolveAlert = (id: string) => postData<AlertEvent>(`/alerts/events/${id}/resolve`)
export const snoozeAlert = (id: string, minutes: number) => postData<AlertEvent>(`/alerts/events/${id}/snooze`, { minutes })

/** Create a follow-up task from an alert (uses the standard tasks endpoint). */
export const createTaskFromAlert = (title: string, description: string | null, projectId: string | null) =>
  postData('/tasks', { title, description, project_id: projectId, priority: 'high', status: 'todo' })
