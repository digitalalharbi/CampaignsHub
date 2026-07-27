import { api, getData, postData } from '@/lib/api/client'
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

export const listAlertRules = () => getData<AlertRule[]>('/alerts/rules')

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
