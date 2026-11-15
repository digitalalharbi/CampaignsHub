/**
 * PROJECT-LIFECYCLE-001 — the TS/API shape of the lifecycle contract (docs/rules/project-lifecycle.md).
 *
 * Mirrors `App\Domains\Projects\Lifecycle\ProjectStatus`, `LiveProjects` and `ClientStatus`. Labels go through
 * `lifecycleLabel()` (rule 10 — never render a raw value such as «active»).
 */
import { deleteData, ensureCsrfCookie, getData, postData } from '@/lib/api/client'

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
  // N2 — actions, filters, windows (§4, §5, §8). One place, AR + EN, keyed (rule 10).
  'projects.lifecycle.action.open': { ar: 'فتح', en: 'Open' },
  'projects.lifecycle.action.archive': { ar: 'أرشفة', en: 'Archive' },
  'projects.lifecycle.action.restore': { ar: 'استعادة', en: 'Restore' },
  'projects.lifecycle.action.delete': { ar: 'حذف', en: 'Delete' },
  'projects.lifecycle.action.purge': { ar: 'حذف نهائي', en: 'Delete permanently' },
  'projects.lifecycle.action.move': { ar: 'نقل لعميل آخر', en: 'Move to another client' },
  'projects.lifecycle.action.cancel': { ar: 'إلغاء', en: 'Cancel' },
  'projects.lifecycle.filter.active': { ar: 'النشطة', en: 'Active' },
  'projects.lifecycle.filter.archived': { ar: 'المؤرشفة', en: 'Archived' },
  'projects.lifecycle.filter.trash': { ar: 'المحذوفات', en: 'Trash' },
  'projects.lifecycle.col.project': { ar: 'المشروع', en: 'Project' },
  'projects.lifecycle.col.client': { ar: 'العميل', en: 'Client' },
  'projects.lifecycle.col.status': { ar: 'الحالة', en: 'Status' },
  'projects.lifecycle.col.setup': { ar: 'الإعداد', en: 'Setup' },
  'projects.lifecycle.col.purge_after': { ar: 'يُحذف نهائيًا في', en: 'Deleted permanently on' },
  'projects.lifecycle.col.actions': { ar: 'إجراءات', en: 'Actions' },
  'projects.lifecycle.empty.active': { ar: 'لا مشاريع نشطة.', en: 'No active projects.' },
  'projects.lifecycle.empty.archived': { ar: 'لا مشاريع مؤرشفة.', en: 'No archived projects.' },
  'projects.lifecycle.empty.trash': { ar: 'المحذوفات فارغة.', en: 'The trash is empty.' },
  'projects.lifecycle.platforms_untouched': { ar: 'المنصات الإعلانية لا تتأثر: لا يُحذف أي حساب إعلاني ولا تُلغى أي صلاحية ربط.', en: 'The ad platforms are not affected: no ad account is deleted and no connection is revoked.' },
  'projects.lifecycle.shared.title': { ar: 'حسابات مشتركة مع مشاريع أخرى', en: 'Accounts shared with other projects' },
  'projects.lifecycle.shared.body': { ar: 'يتوقف ربط هذا المشروع فقط؛ المشاريع الأخرى المرتبطة بالحساب نفسه لا تتأثر.', en: 'Only this project’s link stops; the other projects linked to the same account are not affected.' },
  'projects.lifecycle.shared.badge': { ar: 'مشترك', en: 'Shared' },
  'projects.lifecycle.type_project': { ar: 'اكتب اسم المشروع للتأكيد', en: 'Type the project name to confirm' },
  'projects.lifecycle.type_client': { ar: 'اكتب اسم العميل للتأكيد', en: 'Type the client name to confirm' },
  'projects.lifecycle.archive.title': { ar: 'أرشفة المشروع', en: 'Archive project' },
  'projects.lifecycle.archive.body': { ar: 'تتوقف المزامنة والتنبيهات والإشعارات والتقارير المجدولة والأتمتة والمهام لهذا المشروع، ويظهر تحت «المؤرشفة» فقط. يمكنك استعادته لاحقًا.', en: 'Syncs, alerts, notifications, scheduled reports, automation and tasks stop for this project, and it shows only under «Archived». You can restore it later.' },
  'projects.lifecycle.archive.unlink': { ar: 'فك ربط الحسابات الإعلانية أيضًا', en: 'Also unlink the ad accounts' },
  'projects.lifecycle.delete.title': { ar: 'حذف المشروع', en: 'Delete project' },
  'projects.lifecycle.delete.body': { ar: 'يُنقل المشروع إلى «المحذوفات» ويمكن استعادته حتى انتهاء مدة الاحتفاظ، ثم يُحذف نهائيًا. تُطبَّق كل آثار الأرشفة ويُفك ربط كل الحسابات.', en: 'The project moves to «Trash» and can be restored until the retention period ends; then it is deleted permanently. Every archive effect applies and every account is unlinked.' },
  'projects.lifecycle.delete.reach': { ar: 'ما سيتأثر', en: 'What is affected' },
  'projects.lifecycle.delete.retention': { ar: 'مدة الاحتفاظ في المحذوفات', en: 'Kept in the trash for' },
  'projects.lifecycle.delete.client_question': { ar: 'هذا المشروع هو الوحيد لدى العميل. ماذا تريد أن تحذف؟', en: 'This is the client’s only project. What do you want to delete?' },
  'projects.lifecycle.delete.project_only': { ar: 'المشروع فقط', en: 'The project only' },
  'projects.lifecycle.delete.project_and_client': { ar: 'المشروع والعميل', en: 'The project and the client' },
  'projects.lifecycle.delete.client_kept': { ar: 'للعميل مشاريع أخرى، لذلك يُحذف هذا المشروع فقط ولا يتأثر العميل.', en: 'The client has other projects, so only this project is deleted and the client is not affected.' },
  'projects.lifecycle.purge.title': { ar: 'حذف المشروع نهائيًا', en: 'Delete project permanently' },
  'projects.lifecycle.purge.body': { ar: 'تُحذف بيانات المشروع نهائيًا ولا يمكن التراجع. يبقى سجل التدقيق وسجل الإشعارات باسم المشروع.', en: 'The project’s data is deleted permanently and cannot be undone. The audit and notification logs are kept with the project’s name.' },
  'projects.lifecycle.restore.title': { ar: 'استعادة المشروع', en: 'Restore project' },
  'projects.lifecycle.restore.body': { ar: 'يعود المشروع إلى حالته السابقة وتعود إعداداته كما كانت.', en: 'The project returns to its previous state and its settings come back as they were.' },
  'projects.lifecycle.restore.to': { ar: 'يعود إلى', en: 'Returns to' },
  'projects.lifecycle.restore.relink': { ar: 'إعادة ربط الحسابات المتاحة', en: 'Re-link the available accounts' },
  'projects.lifecycle.restore.unavailable': { ar: 'غير متاح', en: 'Unavailable' },
  'projects.lifecycle.restore.client_back': { ar: 'يعود العميل أيضًا لأنه حُذف مع المشروع.', en: 'The client comes back too, because it was deleted with the project.' },
  'projects.lifecycle.move.title': { ar: 'نقل المشروع لعميل آخر', en: 'Move project to another client' },
  'projects.lifecycle.move.body': { ar: 'ينتقل المشروع بكل بياناته إلى عميل آخر داخل مساحة العمل نفسها. تبقى الفواتير مع العميل الحالي.', en: 'The project moves with all its data to another client in the same workspace. Invoices stay with the current client.' },
  'projects.lifecycle.move.target': { ar: 'العميل الجديد', en: 'New client' },
  'projects.lifecycle.move.portal_users': { ar: 'مستخدمو بوابة العميل الحالي يفقدون الوصول إلى هذا المشروع', en: 'Portal users of the current client lose access to this project' },
  'projects.lifecycle.banner.archived': { ar: 'هذا المشروع مؤرشف — للعرض فقط. لا تُجرى عليه مزامنة ولا تنبيهات ولا تقارير.', en: 'This project is archived — view only. No syncs, alerts or reports run for it.' },
  'projects.lifecycle.banner.archived_at': { ar: 'أُرشف في', en: 'Archived on' },
  'projects.lifecycle.history': { ar: 'سجل الحالة', en: 'Status history' },
  'projects.lifecycle.client.archive.title': { ar: 'أرشفة العميل', en: 'Archive client' },
  'projects.lifecycle.client.archive.body': { ar: 'تُؤرشف معه كل مشاريعه التالية، وتتوقف مزامنتها وتنبيهاتها وتقاريرها. يمكنك استعادة العميل ومشاريعه لاحقًا.', en: 'All of its projects below are archived with it, and their syncs, alerts and reports stop. You can restore the client and its projects later.' },
  'projects.lifecycle.client.archive.none': { ar: 'لا مشاريع نشطة لدى هذا العميل.', en: 'This client has no active projects.' },
  'projects.lifecycle.client.projects': { ar: 'المشاريع', en: 'Projects' },
  'projects.lifecycle.done.archived': { ar: 'تمت أرشفة المشروع', en: 'Project archived' },
  'projects.lifecycle.done.restored': { ar: 'تمت استعادة المشروع', en: 'Project restored' },
  'projects.lifecycle.done.deleted': { ar: 'نُقل المشروع إلى المحذوفات', en: 'Project moved to the trash' },
  'projects.lifecycle.done.purged': { ar: 'حُذف المشروع نهائيًا', en: 'Project deleted permanently' },
  'projects.lifecycle.done.moved': { ar: 'نُقل المشروع إلى العميل الجديد', en: 'Project moved to the new client' },
}

/** `lt('projects.lifecycle.action.open', ar)` — a keyed label; a missing key shows the key's last word, never blank. */
export function lt(key: string, ar: boolean): string {
  const entry = LABELS[key]
  return entry ? (ar ? entry.ar : entry.en) : key.split('.').at(-1) ?? key
}

/** Design-system badge tone for a lifecycle state (never the raw value as text). */
export function lifecycleTone(status: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  return status === 'active' ? 'success' : status === 'paused' ? 'warning' : status === 'deleted' ? 'danger' : status === 'draft' ? 'info' : 'neutral'
}

export type ProjectRowAction = 'open' | 'archive' | 'restore' | 'delete' | 'purge' | 'move'

export interface LifecyclePermissions {
  archive: boolean
  restore: boolean
  delete: boolean
  purge: boolean
  move: boolean
}

/**
 * §8 — the buttons a row shows, by state AND permission: active «فتح / أرشفة / حذف» (+ «نقل لعميل آخر»), archived
 * «فتح / استعادة / حذف», deleted «استعادة / حذف نهائي». The server enforces the same permissions.
 */
export function projectRowActions(status: string, can: LifecyclePermissions): ProjectRowAction[] {
  if (status === 'deleted') return [can.restore && 'restore', can.purge && 'purge'].filter(Boolean) as ProjectRowAction[]
  if (status === 'archived') return ['open', can.restore && 'restore', can.delete && 'delete'].filter(Boolean) as ProjectRowAction[]
  return ['open', can.archive && 'archive', can.delete && 'delete', can.move && 'move'].filter(Boolean) as ProjectRowAction[]
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


/** GET /projects/{id}/deletion-impact (extended by N2). */
export interface DeletionImpact {
  project: { id: string; name: string; status: string; client: string | null }
  counts: Record<string, number>
  shared_accounts: SharedAccount[]
  ad_platforms_untouched: true
  client: { id: string; name: string; other_projects: number; can_delete_client: boolean } | null
  retention_days: number
}

export const getDeletionImpact = (projectId: string): Promise<DeletionImpact> =>
  getData<DeletionImpact>(`/projects/${projectId}/deletion-impact`)

/** DELETE /projects/{id} — soft delete to «المحذوفات»; the names are checked by the server. */
export async function softDeleteProject(
  projectId: string,
  input: { confirm_name: string; client_action?: 'project_only' | 'project_and_client'; confirm_client_name?: string },
): Promise<{ client_deleted: boolean; purge_after: string | null }> {
  await ensureCsrfCookie()
  return deleteData(`/projects/${projectId}`, input)
}

/** GET /projects?status=deleted — «المحذوفات». */
export const listTrashedProjects = (): Promise<TrashedProject[]> => getData<TrashedProject[]>('/projects?status=deleted')

export interface TrashedProject {
  id: string
  name: string
  client_workspace_id: string
  client_name?: string | null
  lifecycle_status?: ProjectLifecycleStatus
  status: string
  purge_after?: string | null
  setup_completion: number
}

export interface RestoreImpact {
  project: { id: string; name: string; status: ProjectLifecycleStatus }
  restores_to: ProjectLifecycleStatus
  relink_accounts: { binding_id: string; external_account_id: string; provider: string; name: string | null; external_id: string | null; available: boolean }[]
  client_restored: boolean
  client: { id: string; name: string } | null
  purge_after: string | null
}

export const getRestoreImpact = (projectId: string): Promise<RestoreImpact> =>
  getData<RestoreImpact>(`/projects/${projectId}/restore-impact`)

/** POST /projects/{id}/restore — from «المؤرشفة» or «المحذوفات»; `relink_accounts` false keeps accounts unlinked. */
export async function restoreProject(projectId: string, relinkAccounts = true): Promise<{ id: string; lifecycle_status: ProjectLifecycleStatus }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/restore`, { confirm: true, relink_accounts: relinkAccounts })
}

/** POST /projects/{id}/purge — «حذف نهائي» (projects.purge + typed name). */
export async function purgeProject(projectId: string, confirmName: string): Promise<{ purged: true }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/purge`, { confirm_name: confirmName })
}

export interface MoveImpact {
  project: { id: string; name: string; status: string }
  from_client: { id: string; name: string } | null
  to_client: { id: string; name: string } | null
  refusal: 'cross_workspace' | 'same_client' | 'target_not_active' | 'project_deleted' | null
  moves: Record<string, number>
  kept: Record<string, number>
  from_client_portal_users: number
  ad_platforms_untouched: true
}

export const getMoveImpact = (projectId: string, clientId?: string): Promise<MoveImpact> =>
  getData<MoveImpact>(`/projects/${projectId}/move-impact${clientId ? `?client_workspace_id=${clientId}` : ''}`)

/** POST /projects/{id}/move — inside the same workspace only (server-enforced). */
export async function moveProject(projectId: string, clientId: string, confirmName: string): Promise<{ id: string; client_workspace_id: string }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/move`, { client_workspace_id: clientId, confirm_name: confirmName })
}

export interface ClientArchiveImpact {
  client: { id: string; name: string }
  projects: { id: string; name: string; status: ProjectLifecycleStatus }[]
  shared_accounts: SharedAccount[]
  ad_platforms_untouched: true
}

export const getClientArchiveImpact = (clientId: string): Promise<ClientArchiveImpact> =>
  getData<ClientArchiveImpact>(`/app/clients/${clientId}/archive-impact`)

/** POST /app/clients/{id}/archive — archives the client AND its projects; the client name is checked by the server. */
export async function archiveClientWithProjects(clientId: string, confirmName: string): Promise<{ is_archived: true }> {
  await ensureCsrfCookie()
  return postData(`/app/clients/${clientId}/archive`, { confirm_name: confirmName })
}
