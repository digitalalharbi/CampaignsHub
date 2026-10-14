import { useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Activity, Bell, Briefcase, ChevronDown, Cpu, History, ListChecks, PanelRightClose, Plug, ShieldCheck,
  type LucideIcon,
} from 'lucide-react'
import { getData } from '@/lib/api/client'
import type { HealthData } from '@/lib/api/types'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { usePortalPath } from '@/app/portalPath'
import { listProjects, listSyncRuns } from '@/features/projects/api'
import { SYNC_RUN_STATUS, statusLabel } from '@/features/projects/ProjectSyncHistory'
import { fetchConnectionHub, type HubConnection } from '@/features/integrations/api'
import { listTasks, OPEN_STATUSES } from '@/features/tasks/api'
import { useProjectCampaignActivity } from '@/features/campaigns/metrics'
import { listNotifications } from '@/features/notifications/api'
import { useProjectCapabilities } from '@/features/projects/capabilities'

/**
 * OPS-PANEL-001 — the operational side panel of the advertiser shell.
 *
 * One column that answers «is everything I depend on working, and what changed?» without leaving
 * the page: the workspace in view, its integrations, the system's own health, open work, the
 * project's change history, its background syncs and the notification inbox.
 *
 * ## Nothing here is computed anew
 *
 * Every section reads an endpoint a page of this portal already reads, under the SAME query key
 * where one exists — so opening the panel shares the cache the bell, the Connection Hub and the
 * sync history already keep, and adds no backend. Realtime is not wired in this frontend (no Echo),
 * so freshness is TanStack polling, and only while the panel is open: closed, it is not mounted and
 * asks nothing.
 *
 * ## Missing is not zero
 *
 * A section the reader may not see (permission or entitlement) is not rendered at all. A section
 * whose request failed says it is unavailable — never «0 tasks», which would be a claim about the
 * workspace this panel cannot make.
 */

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const DOT: Record<Tone, string> = {
  success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info', neutral: 'bg-text-muted',
}
const TEXT: Record<Tone, string> = {
  success: 'text-success', warning: 'text-warning', danger: 'text-danger', info: 'text-info', neutral: 'text-text-muted',
}

const POLL = 60_000

function Pill({ tone, children, testId }: { tone: Tone; children: ReactNode; testId?: string }) {
  return (
    <span data-testid={testId} className={`inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold ${TEXT[tone]}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${DOT[tone]}`} aria-hidden />
      {children}
    </span>
  )
}

/** A section: icon, title and a one-line status; the detail only on expand. */
function Section({
  id, icon: Icon, title, status, children, defaultOpen = false,
}: {
  id: string
  icon: LucideIcon
  title: string
  status: ReactNode
  children: ReactNode
  defaultOpen?: boolean
}) {
  const [open, setOpen] = useState(defaultOpen)

  return (
    <section data-testid={`ops-${id}`} className="rounded-xl border border-border bg-surface-secondary/60">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={`ops-${id}-body`}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-start outline-none hover:bg-surface-hover focus-visible:ring-2 focus-visible:ring-brand-600"
      >
        <Icon size={15} className="shrink-0 text-text-muted" aria-hidden />
        <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-text-primary">{title}</span>
        {status}
        <ChevronDown size={14} className={`shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open && (
        <div id={`ops-${id}-body`} className="grid gap-1.5 border-t border-border px-3 py-2.5 text-xs text-text-secondary">
          {children}
        </div>
      )}
    </section>
  )
}

function Row({ label, value, tone }: { label: ReactNode; value: ReactNode; tone?: Tone }) {
  return (
    <div className="flex items-start justify-between gap-2">
      <span className="min-w-0 flex-1 truncate">{label}</span>
      <span className={`tnum shrink-0 ${tone ? TEXT[tone] : 'text-text-primary'}`}>{value}</span>
    </div>
  )
}

function Muted({ children }: { children: ReactNode }) {
  return <p className="text-text-muted">{children}</p>
}

function Loading() {
  return <span className="h-2 w-10 animate-pulse rounded bg-surface-hover" aria-hidden />
}

/* ── Integrations ─────────────────────────────────────────────────────────────────────────────── */

export function connectionTone(c: HubConnection): Tone {
  if (c.connection_state === 'REAUTH_REQUIRED' || c.connection_state === 'REVOKED') return 'danger'
  if (c.sync_state === 'FAILED') return 'danger'
  if (c.needs_attention || c.connection_state === 'AWAITING_CREDENTIALS') return 'warning'
  if (c.sync_state === 'SYNCING' || c.sync_state === 'QUEUED') return 'info'
  if (c.connection_state === 'CONNECTED') return 'success'
  return 'neutral'
}

const CONNECTION_STATE: Record<string, { ar: string; en: string }> = {
  CONNECTED: { ar: 'متصل', en: 'Connected' },
  NOT_CONNECTED: { ar: 'غير متصل', en: 'Not connected' },
  AWAITING_CREDENTIALS: { ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials' },
  REAUTH_REQUIRED: { ar: 'يتطلب إعادة ربط', en: 'Re-authorisation needed' },
  REVOKED: { ar: 'أُلغي الإذن', en: 'Revoked' },
}
const SYNC_STATE: Record<string, { ar: string; en: string }> = {
  NEVER_SYNCED: { ar: 'لم تُزامن بعد', en: 'Never synced' },
  QUEUED: { ar: 'في الطابور', en: 'Queued' },
  SYNCING: { ar: 'تتم المزامنة', en: 'Syncing' },
  SUCCEEDED: { ar: 'نجحت المزامنة', en: 'Synced' },
  FAILED: { ar: 'فشلت المزامنة', en: 'Sync failed' },
}

function useHub(enabled: boolean) {
  return useQuery({ queryKey: ['connection-hub'], queryFn: fetchConnectionHub, enabled, retry: false, refetchInterval: POLL })
}

function IntegrationsSection({ ar }: { ar: boolean }) {
  const hub = useHub(true)
  const portalPath = usePortalPath()
  const rows = hub.data?.connections ?? []
  const bad = rows.filter((c) => connectionTone(c) === 'danger').length
  const warn = rows.filter((c) => connectionTone(c) === 'warning').length

  const status = hub.isLoading ? <Loading /> : hub.isError ? (
    <Pill tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Pill>
  ) : rows.length === 0 ? (
    <Pill tone="neutral">{ar ? 'لا توجد ربطات' : 'None connected'}</Pill>
  ) : bad > 0 ? (
    <Pill tone="danger" testId="ops-integrations-status">{bad} {ar ? 'تحتاج إصلاحاً' : 'need fixing'}</Pill>
  ) : warn > 0 ? (
    <Pill tone="warning" testId="ops-integrations-status">{warn} {ar ? 'تحتاج انتباهاً' : 'need attention'}</Pill>
  ) : (
    <Pill tone="success" testId="ops-integrations-status">{rows.length} {ar ? 'تعمل' : 'healthy'}</Pill>
  )

  return (
    <Section id="integrations" icon={Plug} title={ar ? 'حالة التكاملات' : 'Integrations'} status={status}>
      {hub.isError && <Muted>{ar ? 'تعذّر جلب حالة التكاملات.' : 'Integration status could not be loaded.'}</Muted>}
      {rows.map((c) => (
        <div key={c.id} className="grid gap-0.5 rounded-lg bg-surface px-2 py-1.5">
          <div className="flex items-center justify-between gap-2">
            <span className="min-w-0 truncate font-semibold text-text-primary">{ar ? c.label_ar : c.label}</span>
            <Pill tone={connectionTone(c)}>
              {c.connection_state === 'CONNECTED'
                ? (ar ? SYNC_STATE[c.sync_state]?.ar : SYNC_STATE[c.sync_state]?.en) ?? c.sync_state
                : (ar ? CONNECTION_STATE[c.connection_state]?.ar : CONNECTION_STATE[c.connection_state]?.en) ?? c.connection_state}
            </Pill>
          </div>
          <span className="truncate text-text-muted">
            {c.connection_name} · {ar ? 'آخر نجاح' : 'Last success'} <span className="tnum">{fmtDateTime(c.last_success_at)}</span>
          </span>
        </div>
      ))}
      <Link to={portalPath('integrations')} className="font-semibold text-brand-600 hover:underline">
        {ar ? 'مركز التكاملات' : 'Open integrations'}
      </Link>
    </Section>
  )
}

/* ── System health ────────────────────────────────────────────────────────────────────────────── */

const DAY = 86_400_000

function HealthSection({ ar, canIntegrations }: { ar: boolean; canIntegrations: boolean }) {
  const health = useQuery({ queryKey: ['health', '/health'], queryFn: () => getData<HealthData>('/health'), refetchInterval: POLL, retry: false })
  const hub = useHub(canIntegrations)
  const connections = hub.data?.connections ?? []
  const failing = connections.filter((c) => c.sync_state === 'FAILED').length
  const now = Date.now()
  const expiring = connections.filter((c) => {
    if (!c.token_expires_at) return false
    const t = new Date(c.token_expires_at).getTime()
    return Number.isFinite(t) && t - now < 7 * DAY
  }).length

  const apiUp = health.isSuccess && health.data?.status !== 'down'
  const down = Object.entries(health.data?.checks ?? {}).filter(([, v]) => v === 'down').map(([k]) => k)
  const tone: Tone = health.isLoading ? 'neutral' : !apiUp || down.length > 0 || failing > 0 ? 'danger' : expiring > 0 ? 'warning' : 'success'

  const status = health.isLoading ? <Loading /> : (
    <Pill tone={tone} testId="ops-health-status">
      {tone === 'success' ? (ar ? 'سليم' : 'Healthy') : tone === 'warning' ? (ar ? 'تنبيه' : 'Attention') : (ar ? 'متأثر' : 'Degraded')}
    </Pill>
  )

  return (
    <Section id="health" icon={ShieldCheck} title={ar ? 'صحة النظام' : 'System health'} status={status}>
      {/* Loading is not «down»: the verdict waits for an answer. */}
      {health.isLoading ? <Loading /> : (
        <Row label={ar ? 'خدمة المنصة' : 'Platform API'} value={apiUp ? (ar ? 'تعمل' : 'Up') : (ar ? 'لا تستجيب' : 'Not responding')} tone={apiUp ? 'success' : 'danger'} />
      )}
      {Object.entries(health.data?.checks ?? {}).map(([k, v]) => (
        <Row key={k} label={k} value={v === 'up' ? (ar ? 'تعمل' : 'Up') : (ar ? 'متوقفة' : 'Down')} tone={v === 'up' ? 'success' : 'danger'} />
      ))}
      {canIntegrations && !hub.isError && hub.isSuccess && (
        <>
          <Row label={ar ? 'مزامنات فاشلة' : 'Failed syncs'} value={failing} tone={failing > 0 ? 'danger' : 'success'} />
          <Row label={ar ? 'صلاحيات تنتهي خلال 7 أيام' : 'Tokens expiring within 7 days'} value={expiring} tone={expiring > 0 ? 'warning' : 'success'} />
        </>
      )}
      {health.dataUpdatedAt > 0 && (
        <Muted>{ar ? 'آخر فحص' : 'Checked'} <span className="tnum">{fmtDateTime(new Date(health.dataUpdatedAt).toISOString())}</span></Muted>
      )}
    </Section>
  )
}

/* ── Tasks ────────────────────────────────────────────────────────────────────────────────────── */

function TasksSection({ ar }: { ar: boolean }) {
  const portalPath = usePortalPath()
  const q = useQuery({
    queryKey: ['tasks', 'ops-panel', 'mine'],
    queryFn: () => listTasks({ mine: true, perPage: 5 }),
    refetchInterval: POLL,
    retry: false,
  })
  const counts = q.data?.counts
  const open = (q.data?.tasks ?? []).filter((t) => OPEN_STATUSES.includes(t.status))

  const status = q.isLoading ? <Loading /> : q.isError || !counts ? (
    <Pill tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Pill>
  ) : (
    <Pill tone={counts.overdue > 0 ? 'danger' : counts.open > 0 ? 'info' : 'success'} testId="ops-tasks-status">
      {counts.open} {ar ? 'مفتوحة' : 'open'}{counts.overdue > 0 ? ` · ${counts.overdue} ${ar ? 'متأخرة' : 'overdue'}` : ''}
    </Pill>
  )

  return (
    <Section id="tasks" icon={ListChecks} title={ar ? 'المهام والتقدّم' : 'Tasks & progress'} status={status}>
      {q.isLoading && <Loading />}
      {q.isError && <Muted>{ar ? 'تعذّر جلب المهام.' : 'Tasks could not be loaded.'}</Muted>}
      {counts && (
        <>
          <Row label={ar ? 'مفتوحة' : 'Open'} value={counts.open} />
          <Row label={ar ? 'متأخرة' : 'Overdue'} value={counts.overdue} tone={counts.overdue > 0 ? 'danger' : undefined} />
          <Row label={ar ? 'منجزة' : 'Done'} value={counts.done} tone="success" />
        </>
      )}
      {open.map((t) => (
        <Row key={t.id} label={t.title} value={t.due_date ?? '—'} tone={t.is_overdue ? 'danger' : undefined} />
      ))}
      <Link to={portalPath('tasks')} className="font-semibold text-brand-600 hover:underline">{ar ? 'كل مهامي' : 'All my tasks'}</Link>
    </Section>
  )
}

/* ── Activity and changes (one source, two readings) ─────────────────────────────────────────── */

function changedFields(before: Record<string, unknown> | null, after: Record<string, unknown> | null): string[] {
  if (!before || !after) return []
  return Object.keys(after).filter((k) => JSON.stringify(before[k]) !== JSON.stringify(after[k]))
}

function ActivitySections({ ar, projectId }: { ar: boolean; projectId: string }) {
  const q = useProjectCampaignActivity(projectId)
  const events = q.data ?? []
  const changes = events.filter((e) => changedFields(e.before, e.after).length > 0)
  const unavailable = <Pill tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Pill>
  const count = (n: number) => <Pill tone={n > 0 ? 'info' : 'neutral'}>{n}</Pill>

  return (
    <>
      <Section id="activity" icon={Activity} title={ar ? 'سجل النشاط' : 'Activity log'} status={q.isLoading ? <Loading /> : q.isError ? unavailable : count(events.length)}>
        {q.isLoading && <Loading />}
        {q.isSuccess && events.length === 0 && <Muted>{ar ? 'لا نشاط مسجّل لهذا المشروع.' : 'No activity recorded for this project.'}</Muted>}
        {events.slice(0, 8).map((e) => (
          <div key={e.id} className="grid gap-0.5">
            <span className="truncate text-text-primary">{e.label}</span>
            <span className="truncate text-text-muted">{e.actor} · <span className="tnum">{fmtDateTime(e.at)}</span></span>
          </div>
        ))}
      </Section>
      <Section id="changes" icon={History} title={ar ? 'آخر التغييرات' : 'Recent changes'} status={q.isLoading ? <Loading /> : q.isError ? unavailable : count(changes.length)}>
        {q.isLoading && <Loading />}
        {q.isSuccess && changes.length === 0 && <Muted>{ar ? 'لا تغييرات مسجّلة.' : 'No recorded changes.'}</Muted>}
        {changes.slice(0, 6).map((e) => (
          <div key={e.id} className="grid gap-0.5">
            <span className="truncate text-text-primary">{e.campaign_name ?? e.label}</span>
            <span className="truncate text-text-muted">
              {changedFields(e.before, e.after).join('، ')} · <span className="tnum">{fmtDateTime(e.at)}</span>
            </span>
          </div>
        ))}
      </Section>
    </>
  )
}

/* ── Background jobs ──────────────────────────────────────────────────────────────────────────── */

function JobsSection({ ar, projectId }: { ar: boolean; projectId: string }) {
  const log = useQuery({
    // The key `ProjectSyncHistory` and the integrations panel already keep and invalidate.
    queryKey: ['project', projectId, 'sync-runs'],
    queryFn: () => listSyncRuns(projectId),
    refetchInterval: 30_000,
    retry: false,
  })
  const runs = log.data?.runs ?? []
  const running = runs.filter((r) => r.status === 'running' || r.status === 'queued').length
  const failed = runs.filter((r) => r.status === 'failed').length

  const status = log.isLoading ? <Loading /> : log.isError ? (
    <Pill tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Pill>
  ) : running > 0 ? (
    <Pill tone="info" testId="ops-jobs-status">{running} {ar ? 'قيد التنفيذ' : 'running'}</Pill>
  ) : failed > 0 ? (
    <Pill tone="danger" testId="ops-jobs-status">{failed} {ar ? 'فشلت' : 'failed'}</Pill>
  ) : (
    <Pill tone={runs.length ? 'success' : 'neutral'} testId="ops-jobs-status">{runs.length ? (ar ? 'هادئة' : 'Idle') : (ar ? 'لا تشغيلات' : 'No runs')}</Pill>
  )

  return (
    <Section id="jobs" icon={Cpu} title={ar ? 'المهام الخلفية' : 'Background jobs'} status={status}>
      {log.isLoading && <Loading />}
      {log.isError && <Muted>{ar ? 'تعذّر جلب سجل المزامنة.' : 'Sync history could not be loaded.'}</Muted>}
      {log.isSuccess && runs.length === 0 && <Muted>{ar ? 'لا تشغيلات مسجّلة لهذا المشروع.' : 'No runs recorded for this project.'}</Muted>}
      {runs.slice(0, 6).map((r) => (
        <div key={r.id} className="grid gap-0.5">
          <div className="flex items-center justify-between gap-2">
            <span className="min-w-0 truncate text-text-primary">{r.provider} · {r.account ?? '—'}</span>
            <Pill tone={SYNC_RUN_STATUS[r.status]?.tone ?? 'neutral'}>{statusLabel(r.status, ar)}</Pill>
          </div>
          <span className="tnum text-text-muted">{fmtDateTime(r.finished_at ?? r.started_at)}</span>
        </div>
      ))}
      {log.data && log.data.runs_withheld > 0 && (
        <Muted>{ar ? `و${log.data.runs_withheld} تشغيلاً أقدم` : `and ${log.data.runs_withheld} older runs`}</Muted>
      )}
    </Section>
  )
}

/* ── Notifications ────────────────────────────────────────────────────────────────────────────── */

const SEVERITY_TONE: Record<string, Tone> = { info: 'info', success: 'success', warning: 'warning', critical: 'danger' }

function NotificationsSection({ ar }: { ar: boolean }) {
  const navigate = useNavigate()
  const portalPath = usePortalPath()
  // The bell's own key and cadence: one cache, one poll.
  const q = useQuery({ queryKey: ['notifications'], queryFn: listNotifications, refetchInterval: POLL })
  const unread = q.data?.unread ?? 0
  const items = (q.data?.items ?? []).slice(0, 5)

  const status = q.isLoading ? <Loading /> : q.isError ? (
    <Pill tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Pill>
  ) : (
    <Pill tone={unread > 0 ? 'info' : 'neutral'} testId="ops-notifications-status">{unread} {ar ? 'غير مقروءة' : 'unread'}</Pill>
  )

  return (
    <Section id="notifications" icon={Bell} title={ar ? 'الإشعارات' : 'Notifications'} status={status}>
      {q.isLoading && <Loading />}
      {q.isSuccess && items.length === 0 && <Muted>{ar ? 'لا إشعارات.' : 'No notifications.'}</Muted>}
      {items.map((n) => (
        <button
          key={n.id}
          type="button"
          disabled={!n.action_url}
          onClick={() => n.action_url && navigate(portalPath(n.action_url))}
          className="grid gap-0.5 rounded-lg px-1 py-1 text-start hover:bg-surface-hover disabled:cursor-default disabled:hover:bg-transparent"
        >
          <span className="flex items-center gap-1.5">
            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${DOT[SEVERITY_TONE[n.severity] ?? 'neutral']}`} aria-hidden />
            <span className={`truncate ${n.status === 'unread' ? 'font-semibold text-text-primary' : ''}`}>{n.title}</span>
          </span>
          <span className="tnum text-text-muted">{fmtDateTime(n.created_at)}</span>
        </button>
      ))}
    </Section>
  )
}

/* ── Workspace ────────────────────────────────────────────────────────────────────────────────── */

function WorkspaceSection({ ar, projectId }: { ar: boolean; projectId: string | null }) {
  const portalPath = usePortalPath()
  const workspace = useAuth((s) => s.user?.workspace_name ?? null)
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false) })
  const project = projects.data?.find((p) => p.id === projectId) ?? null

  return (
    <Section
      id="workspace"
      icon={Briefcase}
      title={ar ? 'مساحة العمل' : 'Workspace'}
      defaultOpen
      status={<span className="max-w-[110px] truncate text-[11px] text-text-muted" data-testid="ops-workspace-project">{project?.name ?? '—'}</span>}
    >
      <Row label={ar ? 'المساحة' : 'Workspace'} value={workspace ?? '—'} />
      <Row label={ar ? 'المشروع الحالي' : 'Current project'} value={project?.name ?? (ar ? 'لم يُختر' : 'None selected')} />
      {project && (
        <>
          <Row label={ar ? 'اكتمال الإعداد' : 'Setup'} value={`${project.setup_completion}%`} tone={project.setup_completion >= 100 ? 'success' : 'warning'} />
          <Link to={portalPath(`projects/${project.id}`)} className="font-semibold text-brand-600 hover:underline">{ar ? 'فتح المشروع' : 'Open project'}</Link>
        </>
      )}
    </Section>
  )
}

/* ── The panel ────────────────────────────────────────────────────────────────────────────────── */

export function OpsPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const close = useUi((s) => s.toggleOpsPanel)
  const projectId = useProject((s) => s.currentProjectId)
  const hasPermission = useAuth((s) => s.hasPermission)
  const nav = useAuth((s) => s.user?.account?.nav)
  /* The rail's own entitlement rule: no list yet (older payload) → everything, as the rail does. */
  const entitled = (key: string) => !nav || nav.includes(key)

  const canIntegrations = hasPermission('integrations.view') && entitled('connections')
  const canTasks = hasPermission('tasks.view') && entitled('tasks')
  /* Project routes are guarded by project capabilities; this only avoids offering a 403. */
  const { can } = useProjectCapabilities()
  const canActivity = entitled('campaigns') && can('campaigns.view')
  const canJobs = canIntegrations && can('analytics.view')

  return (
    <div className="flex h-full flex-col gap-2" data-testid="ops-panel">
      <div className="flex items-center justify-between px-1 pb-1">
        <span className="text-sm font-bold text-text-primary">{ar ? 'لوحة التشغيل' : 'Operations'}</span>
        <button
          type="button"
          onClick={close}
          aria-label={ar ? 'إغلاق لوحة التشغيل' : 'Close operations panel'}
          className="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover hover:text-text-primary"
        >
          <PanelRightClose size={16} className="rtl:-scale-x-100" />
        </button>
      </div>
      <WorkspaceSection ar={ar} projectId={projectId} />
      {canIntegrations && <IntegrationsSection ar={ar} />}
      <HealthSection ar={ar} canIntegrations={canIntegrations} />
      {canTasks && <TasksSection ar={ar} />}
      {canActivity && projectId && <ActivitySections ar={ar} projectId={projectId} />}
      {canJobs && projectId && <JobsSection ar={ar} projectId={projectId} />}
      <NotificationsSection ar={ar} />
    </div>
  )
}
