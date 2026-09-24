import { StatCard } from '@/components/ui/StatCard'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, Copy, FolderKanban, Pause, Pencil, Play, Plus, RotateCcw, Search, Trash2, Users } from 'lucide-react'
import {
  archiveProject,
  createProject,
  deleteProject,
  fetchProjectDeletionImpact,
  listClientWorkspaces,
  listProjects,
  projectAction,
  updateProject,
  type ClientWorkspace,
  type Project,
} from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ErrorSummary, type FieldError } from '@/components/forms'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const STATUSES = ['draft', 'onboarding', 'active', 'paused', 'completed', 'archived']

/**
 * PROJECTS-STATUS-LABEL-001 — the status chips and badges were the database's own words.
 *
 * The filter row read «الكل draft onboarding active paused completed archived» — one translated
 * chip followed by six column values — and every project's badge said `active` in an otherwise
 * Arabic page. Nothing was wrong with the data; the labels had simply never been written, and
 * `{f}` / `{p.status}` render whatever arrives.
 *
 * Keys are the values `projects.status` holds, and `projectStatus.test.ts` asserts this map covers
 * `STATUSES` exactly — so a status added to one and not the other fails a test instead of appearing
 * to a customer as an identifier.
 */
export const PROJECT_STATUS_LABELS: Record<string, { ar: string; en: string }> = {
  draft: { ar: 'مسودة', en: 'Draft' },
  onboarding: { ar: 'قيد الإعداد', en: 'Onboarding' },
  active: { ar: 'نشط', en: 'Active' },
  paused: { ar: 'متوقف', en: 'Paused' },
  completed: { ar: 'مكتمل', en: 'Completed' },
  archived: { ar: 'مؤرشف', en: 'Archived' },
}

export function projectStatusLabel(status: string, ar: boolean): string {
  const label = PROJECT_STATUS_LABELS[status]

  // An unknown status is shown as itself rather than hidden: a value the product does not recognise
  // is a fact about the data, and swallowing it would make a broken row look like a normal one.
  return label ? (ar ? label.ar : label.en) : status
}

/** Error-summary title — local bilingual copy (shared i18n dictionary is untouched). */
const PROJ_ERR_TITLE = { ar: 'يرجى تصحيح الأخطاء التالية', en: 'Please fix the following errors' } as const
/** Summary/toolbar copy — local bilingual (shared i18n dictionary is untouched). */
const PROJ_COPY = {
  ar: { search_ph: 'ابحث باسم المشروع…', all: 'الكل', total: 'إجمالي المشاريع', active: 'نشطة', paused: 'متوقفة', onboarding: 'قيد الإعداد', no_match: 'لا مشاريع تطابق البحث أو الفلتر.' },
  en: { search_ph: 'Search by project name…', all: 'All', total: 'Total projects', active: 'Active', paused: 'Paused', onboarding: 'Onboarding', no_match: 'No projects match your search or filter.' },
} as const
/**
 * PROJECT-DELETE-001 §34–§35 — the destructive dialog's own words, and the notice after every act.
 *
 * Kept beside the page rather than in the shared dictionary for the same reason the rest of this
 * file's copy is: these sentences are about THIS screen's decisions, and a deletion warning that
 * drifts out of sight of the code it describes is how a dialog ends up promising something the
 * server stopped doing.
 */
const PROJ_DANGER = {
  ar: {
    title: 'حذف المشروع',
    lead: 'سيؤدي حذف المشروع إلى إزالة المشروع وبياناته المرتبطة من كامبينز هب. لن يؤدي ذلك إلى حذف الحساب الإعلاني من المنصة.',
    keeps: 'لن يُلغى تفويض المنصة، ولن تُحذف الحسابات الإعلانية — قد تستخدمها مشاريع أخرى.',
    type_name: 'اكتب اسم المشروع للتأكيد',
    confirm: 'حذف المشروع نهائيًا',
    reach: 'ما سيشمله الحذف',
  },
  en: {
    title: 'Delete project',
    lead: 'Deleting removes the project and its data from CampaignsHub. It does not delete the advertising account on the platform.',
    keeps: 'The platform authorisation is not revoked and no advertising account is deleted — other projects may use them.',
    type_name: 'Type the project name to confirm',
    confirm: 'Delete project permanently',
    reach: 'What deletion reaches',
  },
} as const

/** What each impact count is called, in the order a person reads them. */
const PROJ_IMPACT_LABELS: Array<{ key: string; ar: string; en: string }> = [
  { key: 'integration_bindings', ar: 'الحسابات الإعلانية المرتبطة', en: 'Linked advertising accounts' },
  { key: 'campaigns', ar: 'الحملات', en: 'Campaigns' },
  { key: 'metric_rows', ar: 'صفوف البيانات', en: 'Measured rows' },
  { key: 'reports', ar: 'التقارير', en: 'Reports' },
  { key: 'active_shares', ar: 'روابط مشاركة نشطة', en: 'Active share links' },
  { key: 'report_schedules', ar: 'جداول إرسال', en: 'Scheduled sends' },
  { key: 'tasks', ar: 'المهام', en: 'Tasks' },
  { key: 'team_members', ar: 'أعضاء الفريق', en: 'Team members' },
]

/** PROJECT-DELETE-001 §34 — every lifecycle act says so, in one place, in the right words. */
const PROJ_NOTICES = {
  ar: {
    updated: 'تم تحديث المشروع',
    archived: 'تمت أرشفة المشروع',
    restored: 'تمت استعادة المشروع',
    paused: 'تم إيقاف المشروع مؤقتًا',
    resumed: 'تم استئناف المشروع',
    cloned: 'تم نسخ المشروع',
    deleted: 'تم حذف المشروع',
  },
  en: {
    updated: 'Project updated',
    archived: 'Project archived',
    restored: 'Project restored',
    paused: 'Project paused',
    resumed: 'Project resumed',
    cloned: 'Project cloned',
    deleted: 'Project deleted',
  },
} as const

const PROJ_CREATE_IDS: Record<string, string> = { name: 'proj-name', client_workspace_id: 'proj-workspace' }
const PROJ_EDIT_IDS: Record<string, string> = { name: 'proj-edit-name', status: 'proj-edit-status' }

export function ProjectsPage() {
  const t = useT()
  const portalPath = usePortalPath()
  const locale = useUi((s) => s.locale)
  const errTitle = PROJ_ERR_TITLE[locale]
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Project | null>(null)
  const [name, setName] = useState('')
  const [status, setStatus] = useState('active')
  const [workspaceId, setWorkspaceId] = useState('')
  const [showArchived, setShowArchived] = useState(false)
  const [term, setTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | string>('all')
  const [deleting, setDeleting] = useState<Project | null>(null)
  const [confirmName, setConfirmName] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const pc = PROJ_COPY[locale]
  const danger = PROJ_DANGER[locale]
  const notices = PROJ_NOTICES[locale]
  const mayDelete = useAuth((s) => s.hasPermission('projects.delete'))

  const projects = useQuery({
    queryKey: ['projects', { showArchived }],
    queryFn: () => listProjects(showArchived),
  })
  const workspaces = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces })
  const wsById = new Map<string, ClientWorkspace>((workspaces.data ?? []).map((w) => [w.id, w]))

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['projects'] })

  const createMutation = useMutation({
    mutationFn: createProject,
    onSuccess: () => {
      setCreating(false)
      setName('')
      invalidate()
    },
  })
  const updateMutation = useMutation({
    mutationFn: ({ id, ...input }: { id: string; name: string; status: string }) => updateProject(id, input),
    onSuccess: () => {
      setEditing(null)
      setNotice(notices.updated)
      invalidate()
    },
  })
  const actionMutation = useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'clone' | 'restore' | 'pause' | 'resume' }) =>
      projectAction(id, action),
    onSuccess: (_data, { action }) => {
      setNotice(notices[action === 'clone' ? 'cloned' : action === 'restore' ? 'restored' : action === 'pause' ? 'paused' : 'resumed'])
      invalidate()
    },
  })
  const archiveMutation = useMutation({
    mutationFn: archiveProject,
    onSuccess: () => {
      setNotice(notices.archived)
      invalidate()
    },
  })

  /*
   * PROJECT-DELETE-001 — the dialog asks the server what it is about to do, before it does it.
   *
   * Fetched when the dialog opens rather than with the list: it is one project's counts across a
   * dozen tables, and computing it for every card on every render would make opening the page pay
   * for a question almost nobody asks.
   */
  const impact = useQuery({
    queryKey: ['project-deletion-impact', deleting?.id],
    queryFn: () => fetchProjectDeletionImpact(deleting!.id),
    enabled: deleting !== null,
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteProject(deleting!.id, confirmName),
    onSuccess: () => {
      setDeleting(null)
      setConfirmName('')
      setNotice(notices.deleted)
      /*
       * Everything the deleted project fed, not just the list.
       *
       * A project that has gone still has its name in the switcher, its bindings in the integrations
       * page and its figures in whatever was open behind this dialog. Invalidating only `projects`
       * is how a deletion looks like it worked and then reappears on the next screen.
       */
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['client-workspaces'] })
      queryClient.invalidateQueries({ queryKey: ['plan-usage'] })
    },
  })

  const openEdit = (p: Project) => {
    setEditing(p)
    setName(p.name)
    setStatus(p.status)
  }

  const toSummary = (err: unknown, ids: Record<string, string>): FieldError[] => {
    const api = toApiError(err)
    return api.errors ? Object.entries(api.errors).flatMap(([f, m]) => (m?.length ? [{ field: ids[f] ?? f, message: m[0] }] : [])) : []
  }
  const createErrors = createMutation.isError ? toSummary(createMutation.error, PROJ_CREATE_IDS) : []
  const editErrors = updateMutation.isError ? toSummary(updateMutation.error, PROJ_EDIT_IDS) : []

  const items = projects.data ?? []
  const summary = {
    total: items.length,
    active: items.filter((p) => p.status === 'active').length,
    paused: items.filter((p) => p.status === 'paused').length,
    onboarding: items.filter((p) => p.status === 'onboarding').length,
  }
  const needle = term.trim().toLowerCase()
  const filtered = items.filter((p) => {
    if (statusFilter !== 'all' && p.status !== statusFilter) return false
    if (needle && !p.name.toLowerCase().includes(needle)) return false
    return true
  })
  const statusChips: string[] = ['all', ...STATUSES]

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('projects')}</h1>
          {/*
            What this category is FOR — UX-IDENTITY-001.

            It said «مصدر البيانات: CampaignsHub API», which is plumbing: it tells the reader where
            the rows came from and nothing about what a project is or what they can do here.
          */}
          <p className="mt-1 max-w-2xl text-sm text-text-secondary">
            {locale === 'ar'
              ? 'كل مشروع مساحة معزولة لعميل واحد — حساباته الإعلانية وحملاته وفريقه وتقاريره.'
              : 'Each project is one client’s isolated workspace — its ad accounts, campaigns, team and reports.'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant={showArchived ? 'secondary' : 'ghost'} onClick={() => setShowArchived((v) => !v)}>
            {showArchived ? t('hide_archived') : t('show_archived')}
          </Button>
          <Button
            onClick={() => {
              setCreating(true)
              setName('')
              setWorkspaceId('')
            }}
          >
            <Plus size={15} /> {t('new_project')}
          </Button>
        </div>
      </div>

      {/*
        PROJECT-DELETE-001 §34 — no lifecycle act leaves the reader guessing whether it happened.

        Archive, pause, resume, clone, rename and delete all landed silently before this: the card
        changed if you happened to be looking at the right part of it, and otherwise the only way to
        find out was to reload. `role="status"` so it is announced rather than merely drawn.
      */}
      {notice !== null && (
        <div
          role="status"
          data-testid="project-action-notice"
          className="flex items-center justify-between gap-3 rounded-xl border border-success bg-success-soft px-3 py-2 text-sm text-success"
        >
          <span>{notice}</span>
          <button type="button" onClick={() => setNotice(null)} className="text-xs font-semibold opacity-80 hover:opacity-100">
            {locale === 'ar' ? 'إغلاق' : 'Dismiss'}
          </button>
        </div>
      )}

      {/* Summary — the portfolio at a glance. */}
      {!projects.isLoading && items.length > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <ProjSummaryCard label={pc.total} value={summary.total} tone="brand" />
          <ProjSummaryCard label={pc.active} value={summary.active} tone="success" />
          <ProjSummaryCard label={pc.paused} value={summary.paused} tone="warning" />
          <ProjSummaryCard label={pc.onboarding} value={summary.onboarding} tone="muted" />
        </div>
      )}

      {/* Search + status filters. */}
      {!projects.isLoading && items.length > 0 && (
        <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
          <label className="relative flex w-full items-center sm:max-w-xs">
            <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
            <input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder={pc.search_ph}
              className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
            />
          </label>
          <div className="flex flex-wrap gap-2">
            {statusChips.map((f) => (
              <button
                key={f}
                onClick={() => setStatusFilter(f)}
                className={`rounded-full px-3 py-1 text-xs font-semibold ${
                  statusFilter === f ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
                }`}
              >
                {f === 'all' ? pc.all : projectStatusLabel(f, locale === 'ar')}
              </button>
            ))}
          </div>
        </div>
      )}

      {projects.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-36 w-full" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <EmptyState title={t('no_projects')} />
      ) : filtered.length === 0 ? (
        <EmptyState title={pc.no_match} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {filtered.map((p) => {
            const ws = wsById.get(p.client_workspace_id)
            const archived = p.status === 'archived'
            const paused = p.status === 'paused'
            return (
              <Card key={p.id}>
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2">
                    <FolderKanban size={18} className="text-brand-600" />
                    <span className="text-sm font-bold">{p.name}</span>
                  </div>
                  <Badge tone={p.status === 'active' ? 'success' : archived ? 'neutral' : 'warning'}>{projectStatusLabel(p.status, locale === 'ar')}</Badge>
                </div>
                <p className="mt-2 text-xs text-text-secondary">{ws?.name ?? '—'}</p>
                <span className="mt-1 block text-xs text-text-muted">
                  {t('setup')}: <span className="tnum">{p.setup_completion}%</span>
                </span>

                <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-border pt-3 text-xs">
                  <button type="button" onClick={() => openEdit(p)} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Pencil size={13} /> {t('edit')}
                  </button>
                  <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'clone' })} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Copy size={13} /> {t('clone')}
                  </button>
                  {archived ? (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'restore' })} className="inline-flex items-center gap-1 text-success hover:opacity-80">
                      <RotateCcw size={13} /> {t('restore')}
                    </button>
                  ) : paused ? (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'resume' })} className="inline-flex items-center gap-1 text-success hover:opacity-80">
                      <Play size={13} /> {t('resume')}
                    </button>
                  ) : (
                    <button type="button" onClick={() => actionMutation.mutate({ id: p.id, action: 'pause' })} className="inline-flex items-center gap-1 text-warning hover:opacity-80">
                      <Pause size={13} /> {t('pause')}
                    </button>
                  )}
                  {!archived && (
                    <button type="button" onClick={() => archiveMutation.mutate(p.id)} className="inline-flex items-center gap-1 text-text-muted hover:text-danger">
                      {t('archive')}
                    </button>
                  )}
                  {/*
                    Destroying a client is not filed beside archiving them by accident.

                    `projects.delete` is a permission the catalogue always had and nothing read; the
                    server enforces it either way, and offering a button somebody will always be
                    refused is its own small defect.
                  */}
                  {mayDelete && (
                    <button
                      type="button"
                      data-testid={`project-delete-${p.id}`}
                      onClick={() => {
                        setDeleting(p)
                        setConfirmName('')
                      }}
                      className="inline-flex items-center gap-1 text-text-muted hover:text-danger"
                    >
                      <Trash2 size={13} /> {danger.title}
                    </button>
                  )}
                  {/*
                    Portal-relative, per ADR 0002's decision 2. These two were written as `/projects/…`
                    — which is not a route in ANY portal — so both were dead in `/app` and `/agency`
                    alike. Found by pressing them during a live review, not by a status check: a
                    client-side router answers a route it does not have with a blank page and a 200.
                  */}
                  <Link to={portalPath(`/projects/${p.id}/team`)} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary">
                    <Users size={13} /> {t('team')}
                  </Link>
                  <Link to={portalPath(`/projects/${p.id}/integrations`)} className="ms-auto font-bold text-brand-600 hover:underline">
                    {t('integrations')} →
                  </Link>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      {/* Create */}
      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title={t('new_project')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreating(false)}>{t('cancel')}</Button>
            <Button loading={createMutation.isPending} disabled={!name || !workspaceId} onClick={() => createMutation.mutate({ client_workspace_id: workspaceId, name })}>
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          {createErrors.length > 0 && <ErrorSummary errors={createErrors} title={errTitle} />}
          <Field label={t('client_workspace')} htmlFor="proj-workspace" required>
            <Select id="proj-workspace" value={workspaceId} onChange={(e) => setWorkspaceId(e.target.value)} placeholder="—" options={(workspaces.data ?? []).map((w) => ({ value: w.id, label: w.name }))} />
          </Field>
          <Field label={t('name')} htmlFor="proj-name" required>
            <Input id="proj-name" value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
          </Field>
        </div>
      </Modal>

      {/*
        PROJECT-DELETE-001 §35 — the danger dialog.

        Three things it has that «Are you sure?» does not: what will go, what will NOT go, and a
        field that makes the reader name the project before the button is live. The counts come from
        the server, measured against the same rows the deletion will touch, so the dialog cannot
        drift away from what happens next.
      */}
      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={danger.title}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)}>{t('cancel')}</Button>
            <Button
              variant="danger"
              data-testid="project-delete-confirm"
              loading={deleteMutation.isPending}
              disabled={
                deleting === null
                || deleteMutation.isPending
                || confirmName.trim() !== deleting.name.trim()
              }
              onClick={() => deleteMutation.mutate()}
            >
              {danger.confirm}
            </Button>
          </>
        }
      >
        <div className="space-y-3" data-testid="project-delete-dialog">
          <p className="flex items-start gap-2 rounded-lg border border-danger bg-danger-soft p-3 text-sm text-danger">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{danger.lead}</span>
          </p>

          {/* What survives, said plainly — the fact another client's advertising depends on. */}
          <p data-testid="project-delete-keeps" className="rounded-lg border border-border bg-surface-secondary p-3 text-xs text-text-secondary">
            {danger.keeps}
          </p>

          {impact.isLoading ? (
            <Skeleton className="h-28 w-full" />
          ) : impact.data ? (
            <section className="rounded-lg border border-border">
              <h3 className="border-b border-border px-3 py-2 text-xs font-bold text-text-secondary">{danger.reach}</h3>
              <dl className="divide-y divide-border">
                {PROJ_IMPACT_LABELS.map((row) => (
                  <div key={row.key} className="flex items-center justify-between px-3 py-1.5 text-sm">
                    <dt className="text-text-secondary">{locale === 'ar' ? row.ar : row.en}</dt>
                    {/* Latin digits, grouped — the product's numerals rule, in every locale. */}
                    <dd className="tnum font-semibold">
                      {((impact.data.counts as Record<string, number>)[row.key] ?? 0).toLocaleString('en-US')}
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          ) : null}

          <Field label={danger.type_name} htmlFor="proj-delete-name" required>
            <Input
              id="proj-delete-name"
              data-testid="project-delete-name"
              value={confirmName}
              onChange={(e) => setConfirmName(e.target.value)}
              data-autofocus
            />
          </Field>

          {deleteMutation.isError && (
            <p data-testid="project-delete-error" className="rounded-lg border border-danger bg-danger-soft p-3 text-sm text-danger">
              {toApiError(deleteMutation.error).message}
            </p>
          )}
        </div>
      </Modal>

      {/* Edit */}
      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={t('edit_project')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>{t('cancel')}</Button>
            <Button loading={updateMutation.isPending} onClick={() => editing && updateMutation.mutate({ id: editing.id, name, status })}>
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          {editErrors.length > 0 && <ErrorSummary errors={editErrors} title={errTitle} />}
          <Field label={t('name')} htmlFor="proj-edit-name" required>
            <Input id="proj-edit-name" value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
          </Field>
          <Field label={t('status_label')} htmlFor="proj-edit-status">
            <Select id="proj-edit-status" value={status} onChange={(e) => setStatus(e.target.value)} options={STATUSES.map((s) => ({ value: s, label: s }))} />
          </Field>
        </div>
      </Modal>
    </section>
  )
}

function ProjSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'success' | 'warning' | 'muted' }) {
  return (
    <StatCard label={label} value={value} tone={tone === 'muted' ? 'neutral' : tone} dot />
  )
}
