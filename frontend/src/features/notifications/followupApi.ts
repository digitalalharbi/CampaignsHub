import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * NOTIF-V2-FOLLOWUP-001 — the follow-up assistant's API (Owner spec §6). The server decides everything: who sees a task,
 * whether the plan includes the feature (403 + `meta.upgrade`), what closed a task. This file only asks and shows.
 */

export type TaskKind = 'renew_budget' | 'set_budget' | 'relink_platform' | 'review_held_message' | 'decide_recommendation'
export type TaskStatus = 'open' | 'snoozed' | 'done' | 'dismissed'

export interface FollowupTask {
  id: string
  kind: TaskKind
  title: string
  reason: string
  params: Record<string, unknown>
  status: TaskStatus
  project_id: string | null
  project_name: string | null
  workspace_name: string
  due_on: string | null
  snoozed_until: string | null
  assigned_user_id: number | null
  assigned_name: string | null
  action_url: string | null
  opened_at: string | null
  closed_at: string | null
  closed_how: 'auto' | 'manual' | null
}

export interface TaskHistoryRow {
  action: 'opened' | 'assigned' | 'snoozed' | 'closed' | 'reopened'
  label: string
  by: string
  at: string | null
  detail: Record<string, unknown>
}

export interface FollowupList {
  items: FollowupTask[]
  counts: { open: number; snoozed: number; done: number }
  /** The workspace type's own words: «مشروع» / «حملة», «عميل» / «علامة». */
  terms: { project: string; client: string }
  workspace: string
}

export async function listTasks(status: 'open' | 'snoozed' | 'done' | 'all' = 'open', mine = false): Promise<FollowupList> {
  const res = await api.get<ApiEnvelope<FollowupTask[]>>('/followup/tasks', { params: { status, ...(mine ? { mine: 1 } : {}) } })
  const meta = res.data.meta as Partial<FollowupList> | undefined

  return {
    items: res.data.data ?? [],
    counts: meta?.counts ?? { open: 0, snoozed: 0, done: 0 },
    terms: meta?.terms ?? { project: '', client: '' },
    workspace: meta?.workspace ?? '',
  }
}

export async function getTask(id: string): Promise<FollowupTask & { history: TaskHistoryRow[] }> {
  const res = await api.get<ApiEnvelope<FollowupTask & { history: TaskHistoryRow[] }>>(`/followup/tasks/${id}`)
  return res.data.data!
}

export const snoozeTask = (id: string, preset: '1h' | 'tomorrow' | 'next_week') => api.post(`/followup/tasks/${id}/snooze`, { preset })
export const assignTask = (id: string, userId: number) => api.post(`/followup/tasks/${id}/assign`, { user_id: userId })
export const closeTask = (id: string) => api.post(`/followup/tasks/${id}/close`)

export async function listAssignees(id: string): Promise<{ id: number; name: string }[]> {
  const res = await api.get<ApiEnvelope<{ id: number; name: string }[]>>(`/followup/tasks/${id}/assignees`)
  return res.data.data ?? []
}

export interface BriefProjectRow { project_id: string; project_name: string }
export interface BriefSections {
  tasks: { count: number; items: { id: string; kind: TaskKind; params: Record<string, unknown>; project_name: string | null; due_on: string | null }[] }
  budgets_ending: { project_id: string; project_name: string; days: number; until: string | null }[]
  no_budget: BriefProjectRow[]
  recommendations: number
  problems: BriefProjectRow[]
  external_yesterday: { sent: number; held: number; unsubscribed: number }
}
export interface Brief { date: string; summary: string; sections: BriefSections }

export async function getBrief(date?: string): Promise<{ brief: Brief | null; terms: { project: string; client: string } }> {
  const res = await api.get<ApiEnvelope<Brief | null>>('/followup/brief', { params: date ? { date } : undefined })
  const meta = res.data.meta as { terms?: { project: string; client: string } } | undefined

  return { brief: res.data.data ?? null, terms: meta?.terms ?? { project: '', client: '' } }
}

export interface BriefSettings {
  brief_enabled: boolean
  brief_time: string
  brief_channels: ('in_app' | 'email')[]
  timezone: string
}

export async function getBriefSettings(): Promise<BriefSettings> {
  const res = await api.get<ApiEnvelope<BriefSettings>>('/followup/settings')
  return res.data.data!
}

export async function saveBriefSettings(patch: Partial<Omit<BriefSettings, 'timezone'>>): Promise<BriefSettings> {
  const res = await api.put<ApiEnvelope<BriefSettings>>('/followup/settings', patch)
  return res.data.data!
}

export interface RerouteRecipient { id: string; name: string | null; email: string; role: string }

export async function rerouteRecipients(notificationId: string): Promise<{ allowed: boolean; recipients: RerouteRecipient[] }> {
  const res = await api.get<ApiEnvelope<{ allowed: boolean; recipients: RerouteRecipient[] }>>('/followup/reroute/recipients', { params: { notification_id: notificationId } })
  return res.data.data ?? { allowed: false, recipients: [] }
}

export const reroute = (notificationId: string, recipientId: string) => api.post('/followup/reroute', { notification_id: notificationId, recipient_id: recipientId })

/** The plan does not include this feature (the server answered 403 with `meta.upgrade`): show the lock and «ترقية», not an error. */
export function isUpgradeRequired(error: unknown): boolean {
  const r = (error as { response?: { status?: number; data?: { meta?: { upgrade?: boolean } } } } | null)?.response

  return r?.status === 403 && r.data?.meta?.upgrade === true
}
