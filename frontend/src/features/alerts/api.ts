import { api, getEnvelope, postData } from '@/lib/api/client'
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
  // LEAD-SLA-NOTIFICATION-001 — three follow-up promises, kept in step with the server's own list.
  'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue',
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

/** Counts over the WHOLE ledger, which is not the same set as the page. */
export interface AlertEventCounts {
  open: number
  snoozed: number
  resolved: number
  open_critical: number
}

export interface AlertEventPage {
  events: AlertEvent[]
  /** How many events exist in the queried scope, capped page or not. */
  total: number
  counts: AlertEventCounts
}

/**
 * The firing ledger, and the truth about the part of it that did not fit.
 *
 * This used to return the bare array and throw the envelope away, which made the page's own summary
 * badges lies on any tenant past the server's cap: they were counted by filtering the 200 rows that
 * came back, and presented as counts of everything. The counts now come from the server, computed
 * over the whole ledger, so they stay true wherever the cap falls — and `total` is what lets the list
 * say it is showing 200 of 431 rather than presenting a truncated ledger as the whole one.
 */
export async function listAlertEvents(status?: 'open' | 'snoozed' | 'resolved'): Promise<AlertEventPage> {
  const res = await api.get<ApiEnvelope<AlertEvent[]>>('/alerts/events', { params: status ? { status } : {} })
  const events = res.data.data ?? []
  const meta = res.data.meta as { total?: number; counts?: Partial<AlertEventCounts> } | undefined
  const c = meta?.counts

  return {
    events,
    total: Number(meta?.total ?? events.length),
    /*
     * Falling back to counting the rows we have is the honest failure mode for an older server that
     * sends no counts: it is what the page did before, and it is only ever wrong in the direction of
     * under-counting a capped ledger — never inventing alerts that do not exist.
     */
    counts: {
      open: Number(c?.open ?? events.filter((e) => e.status === 'open').length),
      snoozed: Number(c?.snoozed ?? events.filter((e) => e.status === 'snoozed').length),
      resolved: Number(c?.resolved ?? events.filter((e) => e.status === 'resolved').length),
      open_critical: Number(c?.open_critical ?? events.filter((e) => e.status === 'open' && e.severity === 'critical').length),
    },
  }
}

export const resolveAlert = (id: string) => postData<AlertEvent>(`/alerts/events/${id}/resolve`)
export const snoozeAlert = (id: string, minutes: number) => postData<AlertEvent>(`/alerts/events/${id}/snooze`, { minutes })

/** Create a follow-up task from an alert (uses the standard tasks endpoint). */
export const createTaskFromAlert = (title: string, description: string | null, projectId: string | null) =>
  postData('/tasks', { title, description, project_id: projectId, priority: 'high', status: 'todo' })
