/**
 * PROJECT-LIFECYCLE-001 — the TS/API shape of the lifecycle contract (docs/rules/project-lifecycle.md).
 *
 * Mirrors `App\Domains\Projects\Lifecycle\ProjectStatus`, `LiveProjects` and `ClientStatus`. Labels go through
 * `lifecycleLabel()` (rule 10 — never render a raw value such as «active»).
 */
import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

export type ProjectLifecycleStatus = 'draft' | 'active' | 'paused' | 'archived' | 'deleted'

export type ClientLifecycleStatus =
  | 'prospect'
  | 'onboarding'
  | 'active'
  | 'needs_attention'
  | 'paused'
  | 'completed'
  | 'archived'
  | 'deleted'

/** «Live» = aggregated, alerted, notified, picked. Same set as `LiveProjects::STATUSES`. */
export const LIVE_PROJECT_STATUSES: readonly ProjectLifecycleStatus[] = ['active', 'paused']
/** Management lists and «link an account to…» pickers. Same set as `LiveProjects::LISTED`. */
export const LISTED_PROJECT_STATUSES: readonly ProjectLifecycleStatus[] = ['draft', 'active', 'paused']

/** Same table as `ProjectStatus::allowedTargets()` — the UI shows only buttons the server would accept. */
export const PROJECT_TRANSITIONS: Readonly<Record<ProjectLifecycleStatus, readonly ProjectLifecycleStatus[]>> = {
  draft: ['active', 'archived', 'deleted'],
  active: ['paused', 'archived', 'deleted'],
  paused: ['active', 'archived', 'deleted'],
  archived: ['draft', 'active', 'paused', 'deleted'],
  deleted: ['draft', 'active', 'paused', 'archived'],
}

export const isLiveProject = (status: string): boolean => (LIVE_PROJECT_STATUSES as readonly string[]).includes(status)

const LABELS: Record<string, { ar: string; en: string }> = {
  'projects.lifecycle.status.draft': { ar: 'مسودة', en: 'Draft' },
  'projects.lifecycle.status.active': { ar: 'نشط', en: 'Active' },
  'projects.lifecycle.status.paused': { ar: 'متوقف مؤقتًا', en: 'Paused' },
  'projects.lifecycle.status.archived': { ar: 'مؤرشف', en: 'Archived' },
  'projects.lifecycle.status.deleted': { ar: 'محذوف', en: 'Deleted' },
  'clients.lifecycle.status.prospect': { ar: 'محتمل', en: 'Prospect' },
  'clients.lifecycle.status.onboarding': { ar: 'قيد التهيئة', en: 'Onboarding' },
  'clients.lifecycle.status.active': { ar: 'نشط', en: 'Active' },
  'clients.lifecycle.status.needs_attention': { ar: 'يحتاج متابعة', en: 'Needs attention' },
  'clients.lifecycle.status.paused': { ar: 'متوقف مؤقتًا', en: 'Paused' },
  'clients.lifecycle.status.completed': { ar: 'مكتمل', en: 'Completed' },
  'clients.lifecycle.status.archived': { ar: 'مؤرشف', en: 'Archived' },
  'clients.lifecycle.status.deleted': { ar: 'محذوف', en: 'Deleted' },
  'projects.lifecycle.closed_reason.archived': { ar: 'المشروع مؤرشف', en: 'The project is archived' },
}

/** `lifecycleLabel('archived', true)` → «مؤرشف». Unknown values fall back to a neutral label, never the raw value. */
export function lifecycleLabel(status: string, ar: boolean, subject: 'projects' | 'clients' = 'projects'): string {
  const entry = LABELS[`${subject}.lifecycle.status.${status}`]
  if (entry) return ar ? entry.ar : entry.en
  return ar ? 'غير معروف' : 'Unknown'
}

export function lifecycleKeyLabel(key: string, ar: boolean): string | null {
  const entry = LABELS[key]
  return entry ? (ar ? entry.ar : entry.en) : null
}

/** One account of this project that is ALSO linked to another project («مشترك»). */
export interface SharedAccount {
  external_account_id: string
  provider: string
  name: string | null
  external_id: string | null
  other_projects: { id: string; name: string; status: ProjectLifecycleStatus }[]
}

export type ArchiveEffectKind =
  | 'binding_sync_stopped'
  | 'binding_unlinked'
  | 'report_schedule_paused'
  | 'alert_rule_disabled'
  | 'automation_rule_disabled'
  | 'agent_routine_disabled'
  | 'task_closed'
  | 'alert_closed'
  | 'proposal_expired'

/** GET /projects/{id}/archive-impact */
export interface ArchiveImpact {
  project: { id: string; name: string; status: ProjectLifecycleStatus }
  stops: Partial<Record<ArchiveEffectKind, number>>
  stops_if_unlinked: Partial<Record<ArchiveEffectKind, number>>
  shared_accounts: SharedAccount[]
  ad_platforms_untouched: true
}

export interface TransitionRecord {
  id: string
  subject_id: string
  from: string
  to: string
  actor_id: number | null
  at: string
}

/** GET /projects/{id}/lifecycle */
export interface ProjectLifecycleState {
  status: ProjectLifecycleStatus
  archived_at: string | null
  previous_status: ProjectLifecycleStatus | null
  purge_after: string | null
  transitions: {
    id: string
    from: string
    to: string
    actor_id: number | null
    actor_name: string | null
    name_snapshot: string
    reason: string | null
    context: Record<string, unknown> | null
    at: string
  }[]
}

export const getArchiveImpact = (projectId: string): Promise<ArchiveImpact> =>
  getData<ArchiveImpact>(`/projects/${projectId}/archive-impact`)

export const getProjectLifecycle = (projectId: string): Promise<ProjectLifecycleState> =>
  getData<ProjectLifecycleState>(`/projects/${projectId}/lifecycle`)

/** POST /projects/{id}/archive — `projects.archive`. */
export async function archiveProjectWithEffects(
  projectId: string,
  input: { unlink_accounts?: boolean; reason?: string } = {},
): Promise<{ id: string; status: ProjectLifecycleStatus; lifecycle: { transition: TransitionRecord; effects: Partial<Record<ArchiveEffectKind, number>>; shared_accounts: SharedAccount[] } }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/archive`, input)
}

/** POST /projects/{id}/restore — `projects.restore`; the server requires the explicit confirmation. */
export async function unarchiveProject(
  projectId: string,
): Promise<{ id: string; status: ProjectLifecycleStatus; lifecycle: { transition: TransitionRecord; restored: Record<string, number>; skipped: Record<string, number> } }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/restore`, { confirm: true })
}
