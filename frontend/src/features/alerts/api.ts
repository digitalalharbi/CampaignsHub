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
  /** OPERATIONAL-INTELLIGENCE-P1-001 — the anatomy; present on every row the server sends (derived for older ones). */
  priority?: AlertPriority | null
  evidence?: AlertEvidence | null
  anatomy?: AlertAnatomy | null
  decision_status?: DecisionStatus | null
  owner_id?: number | null
}

export type AlertPriority = 'P1' | 'P2' | 'P3'
export type DecisionStatus = 'new' | 'in_progress' | 'done' | 'dismissed'
export interface Bilingual { ar: string; en: string }
export interface AlertEvidence {
  metric: string | null
  window_days: number | null
  before: number | null
  now: number | null
  threshold: number | null
  threshold_unit: string | null
  completeness: 'complete' | 'unverified' | 'partial'
  sufficient: boolean
  missing: string[]
}
export interface AlertAnatomy {
  problem: Bilingual
  numbers: Bilingual
  impact: Bilingual | null
  action: Bilingual | null
  insufficient_note: Bilingual | null
}

export const ALERT_TYPES = [
  'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results',
  'sync_failure', 'token_expiry', 'report_failed', 'sla_warning',
  // LEAD-SLA-NOTIFICATION-001 — three follow-up promises, kept in step with the server's own list.
  'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue',
  // AUTOMATION-FIRST-OPERATIONS-001 — the type with no threshold to set.
  'metric_anomaly',
  // EMAIL-ALERT-SYNC-OUTAGE-001 / EMAIL-ALERT-MEASUREMENT-OUTAGE-001
  'sync_outage', 'measurement_outage',
  // TRACKING-HEALTH-001 — the findings of the Tracking Health page.
  'tracking_health',
  // LAUNCH-ADVANCE-001 — a launched account that now earns a better stage.
  'launch_advance',
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
export async function listAlertEvents(
  status?: 'open' | 'snoozed' | 'resolved',
  /**
   * The project the reader is standing in — ANALYTICS-FILTER-TRUTH-001.
   *
   * The ledger is tenant-wide, so without this an agency reading one client's workspace was shown
   * every client's alerts at once. The server keeps the account-wide rows (a token expiring belongs
   * to a connection, not to a project) and drops the neighbours'.
   */
  projectId?: string | null,
): Promise<AlertEventPage> {
  const params: Record<string, string> = {}
  if (status) params.status = status
  if (projectId) params.project = projectId

  const res = await api.get<ApiEnvelope<AlertEvent[]>>('/alerts/events', { params })
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


// ---- OPERATIONAL-INTELLIGENCE-P1-001 — the decision log and the exception-first board -----------------------------------

export type CellStatus = 'problem' | 'no_data' | 'watch' | 'healthy'

export interface AttentionItem {
  id: string
  priority: AlertPriority
  severity: AlertEvent['severity']
  type: string
  problem: Bilingual | null
  numbers: Bilingual | null
  impact: Bilingual | null
  action: Bilingual | null
  insufficient: boolean
  evidence: { metric: string | null; before: number | null; now: number | null; threshold: number | null; threshold_unit: string | null }
  automation: { state: 'needs_decision' | 'awaiting_approval' | 'done_automatically' | 'in_flight'; proposals: { action: string; status: string; reason: string }[] }
  owner: { id: number; name: string } | null
  decision_status: DecisionStatus
  project_id: string | null
  campaign_id: string | null
  campaign_name: string | null
  platforms: string[]
  level: 'campaign' | 'connection'
  path: string
}

export interface AttentionBoardData {
  platforms: string[]
  rows: { campaign_id: string; project_id: string; name: string; cells: Record<string, { status: CellStatus; last_data: string | null; alert_ids: string[] }> }[]
  counts: Record<CellStatus, number>
  cells_total: number
  coverage: { total: number; checked: number }
  unchecked: { id: string; name: string; reason: string }[]
  projects: { id: string; name: string; status: CellStatus | 'unchecked' }[]
  rows_total: number
  /** Campaigns that name no platform: counted here, never shown as healthy. */
  unplaced: number
  truncated: boolean
  needs_decision: AttentionItem[]
  needs_decision_total: number
  healthy_count: number
}

export const getAttentionBoard = (projectId?: string | null) =>
  getData<AttentionBoardData>('/alerts/attention' + (projectId ? `?project=${encodeURIComponent(projectId)}` : ''))

export interface DecisionLogLine {
  id: string
  kind: 'status' | 'assign' | 'note' | 'resolve' | 'snooze'
  by: string | null
  at: string | null
  from_status: string | null
  to_status: string | null
  action_tag: string | null
  note: string | null
  from_owner: string | null
  to_owner: string | null
}

export interface AlertImpactResult {
  state: 'none' | 'not_measurable' | 'insufficient_data' | 'computed'
  reason?: string
  metric?: string
  window_days?: number
  action_tag?: string | null
  before?: number | null
  after?: number
  change_pct?: number | null
  verdict?: 'improved' | 'worsened' | 'flat' | 'unclear'
  days_remaining?: number
}

export interface AlertDetail {
  event: AlertEvent
  owner: { id: number; name: string } | null
  log: DecisionLogLine[]
  impact: AlertImpactResult
  assignees: { id: number; name: string }[]
  action_tags: string[]
}

export const getAlertDetail = (id: string) => getData<AlertDetail>(`/alerts/events/${id}`)
export const decideAlert = (id: string, body: { status: DecisionStatus; reason?: string; action_tag?: string; note?: string }) =>
  postData<AlertEvent>(`/alerts/events/${id}/decision`, body)
export const assignAlert = (id: string, ownerId: number | null) => postData<AlertEvent>(`/alerts/events/${id}/assign`, { owner_id: ownerId })
export const noteAlert = (id: string, note: string) => postData<null>(`/alerts/events/${id}/note`, { note })
