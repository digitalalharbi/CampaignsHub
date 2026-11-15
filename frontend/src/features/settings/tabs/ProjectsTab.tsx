import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, ArrowLeftRight, FolderKanban, FolderOpen, Plus, RotateCcw, Trash2, XCircle } from 'lucide-react'
import { createProject, listClientWorkspaces, listProjects, type Project } from '@/features/projects/api'
import { getData, toApiError } from '@/lib/api/client'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Alert } from '@/components/ui/Alert'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { TabStrip } from '@/components/fit/TabStrip'
import { ResponsiveList, type ListColumn } from '@/components/fit/ResponsiveList'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'
import { formatWholePercent } from '@/lib/percent'
import { fmtDate } from '@/lib/datetime'
import { lifecycleLabel, lifecycleTone, listTrashedProjects, lt, projectRowActions, type ProjectRowAction } from '@/lib/projectLifecycle'
import { ProjectLifecycleDialog, useLifecyclePermissions, type LifecycleDialogState } from '@/features/projects/lifecycle/ProjectLifecycleDialogs'

type Filter = 'active' | 'archived' | 'trash'

type Row = Pick<Project, 'id' | 'name' | 'client_workspace_id' | 'client_name' | 'setup_completion' | 'lifecycle_status' | 'purge_after'> & { status: string }

const ACTION_ICONS: Record<ProjectRowAction, typeof Archive> = {
  open: FolderOpen, archive: Archive, restore: RotateCcw, delete: Trash2, purge: XCircle, move: ArrowLeftRight,
}

/**
 * Workspace settings → projects (PROJECT-LIFECYCLE-001 §8, builder N2).
 *
 * - «الإعداد» through the one formatter (`formatWholePercent`): the value is already 0–100, it was ×100 here (10000%).
 * - Status in Arabic through the lifecycle keys, as design-system badges — never the raw «active».
 * - Filters «النشطة / المؤرشفة / المحذوفات»; a clear «العميل» column; the buttons by state AND permission
 *   (`projectRowActions`): active «فتح / أرشفة / حذف» + «نقل لعميل آخر», archived «فتح / استعادة / حذف», deleted
 *   «استعادة / حذف نهائي». Every destructive act opens its impact window first.
 * - One list in two shapes (D's `ResponsiveList`): a table from md, cards on a phone — no horizontal scroll.
 */
export function ProjectsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const portalPath = usePortalPath()
  const can = useLifecyclePermissions()
  const [filter, setFilter] = useState<Filter>('active')
  const [dialog, setDialog] = useState<LifecycleDialogState>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const clients = useQuery({ queryKey: ['settings', 'clients-list'], queryFn: () => listClientWorkspaces() })
  const active = useQuery({ queryKey: ['settings', 'projects', 'active'], queryFn: () => listProjects(false) })
  const archived = useQuery({ queryKey: ['settings', 'projects', 'archived'], queryFn: () => getData<Project[]>('/projects?status=archived') })
  const trash = useQuery({ queryKey: ['settings', 'projects', 'trash'], queryFn: listTrashedProjects, enabled: can.restore || can.purge || can.delete })
  const create = useMutation({ mutationFn: createProject, onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'projects'] }) })

  const [form, setForm] = useState({ client_workspace_id: '', name: '' })
  const [err, setErr] = useState('')

  const lists: Record<Filter, typeof active> = { active, archived, trash: trash as unknown as typeof active }
  const current = lists[filter]

  if (active.isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-48" /></div>
  if (active.isError) {
    return <QueryFailure error={active.error} ar={ar} testId="settings-projects-failure"
      fallbackTitle={ar ? 'تعذّر تحميل المشاريع.' : 'The projects could not be loaded.'} />
  }

  const hasClients = (clients.data?.length ?? 0) > 0
  const clientName = (p: Row) => p.client_name ?? clients.data?.find((c) => c.id === p.client_workspace_id)?.name ?? '—'
  // «نقل لعميل آخر» only where there is another client to move to (data-driven, the same code for every workspace type).
  const perms = { ...can, move: can.move && (clients.data?.length ?? 0) > 1 }
  const doCreate = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    if (hasClients && !form.client_workspace_id) { setErr(ar ? 'اختر العميل أولًا.' : 'Choose the client first.'); return }
    // A workspace without a client concept (its hidden default client) sends none: the server resolves it (rule 2).
    try { await create.mutateAsync(hasClients ? form : { name: form.name }); setForm({ client_workspace_id: '', name: '' }) } catch (e2) { setErr(toApiError(e2).message) }
  }

  const stateOf = (p: Row): string => p.lifecycle_status ?? (filter === 'trash' ? 'deleted' : filter === 'archived' ? 'archived' : 'active')
  const rows: Row[] = (current.data ?? []) as Row[]

  const actions = (p: Row, wrap = false) => (
    <div className={`flex items-center gap-x-3 gap-y-1 ${wrap ? 'flex-wrap justify-start' : 'flex-nowrap justify-end'}`} data-testid={`settings-project-actions-${p.id}`}>
      {projectRowActions(stateOf(p), perms).map((a) => {
        const Icon = ACTION_ICONS[a]
        const label = lt(`projects.lifecycle.action.${a}`, ar)
        const cls = `inline-flex items-center gap-1 text-xs font-semibold ${a === 'delete' || a === 'purge' ? 'text-danger hover:underline' : a === 'open' ? 'text-brand-600 hover:underline' : 'text-text-secondary hover:text-text-primary'}`
        if (a === 'open') {
          return <Link key={a} to={portalPath(`/projects/${p.id}`)} className={cls} data-testid={`settings-project-open-${p.id}`}><Icon size={13} aria-hidden /> {label}</Link>
        }

        return (
          <button key={a} type="button" className={cls} data-testid={`settings-project-${a}-${p.id}`}
            onClick={() => setDialog({ kind: a, project: { id: p.id, name: p.name, client_workspace_id: p.client_workspace_id, status: stateOf(p) } })}>
            <Icon size={13} aria-hidden /> {label}
          </button>
        )
      })}
    </div>
  )

  const statusBadge = (p: Row) => <Badge tone={lifecycleTone(stateOf(p))} data-testid={`settings-project-status-${p.id}`}>{lifecycleLabel(stateOf(p), ar)}</Badge>
  const fourth: ListColumn<Row> = filter === 'trash'
    ? { key: 'purge', header: lt('projects.lifecycle.col.purge_after', ar), width: '16%', cell: (p) => <span className="tnum text-text-secondary">{p.purge_after ? fmtDate(p.purge_after) : '—'}</span> }
    : { key: 'setup', header: lt('projects.lifecycle.col.setup', ar), width: '10%', cell: (p) => <span className="tnum text-text-secondary" data-testid={`settings-project-setup-${p.id}`}>{formatWholePercent(p.setup_completion)}</span> }
  const columns: ListColumn<Row>[] = [
    { key: 'name', header: lt('projects.lifecycle.col.project', ar), cell: (p) => <span className="inline-flex min-w-0 items-center gap-2 font-semibold text-text-primary" title={p.name}><FolderKanban size={15} className="shrink-0 text-brand-600" aria-hidden /> <span className="truncate">{p.name}</span></span> },
    { key: 'client', header: lt('projects.lifecycle.col.client', ar), width: '20%', cell: (p) => <span className="text-text-secondary" title={clientName(p)}>{clientName(p)}</span> },
    { key: 'status', header: lt('projects.lifecycle.col.status', ar), width: '13%', cell: statusBadge },
    fourth,
    { key: 'actions', header: lt('projects.lifecycle.col.actions', ar), width: '30%', align: 'end', cell: (p) => actions(p) },
  ]

  const filters = [
    { key: 'active', label: lt('projects.lifecycle.filter.active', ar), count: active.data?.length },
    { key: 'archived', label: lt('projects.lifecycle.filter.archived', ar), count: archived.data?.length },
    ...(can.restore || can.purge || can.delete ? [{ key: 'trash', label: lt('projects.lifecycle.filter.trash', ar), count: trash.data?.length }] : []),
  ]

  return (
    <div className="min-w-0 space-y-4">
      <form onSubmit={doCreate} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><Plus size={18} /> {ar ? 'مشروع جديد' : 'New project'}</h2>
        {err && <div className="mb-3"><Alert severity="danger" title={ar ? 'تعذّر الإنشاء' : 'Could not create it'}>{err}</Alert></div>}
        <div className={`grid gap-3 ${hasClients ? 'sm:grid-cols-[1fr_1fr_auto]' : 'sm:grid-cols-[1fr_auto]'}`}>
          {hasClients && <Field label={ar ? 'العميل' : 'Client'} htmlFor="pj-client"><Select id="pj-client" value={form.client_workspace_id} onChange={(e) => setForm({ ...form, client_workspace_id: e.target.value })} options={(clients.data ?? []).map((c) => ({ value: c.id, label: c.name }))} placeholder={ar ? 'اختر العميل' : 'Choose a client'} /></Field>}
          <Field label={ar ? 'اسم المشروع' : 'Project name'} htmlFor="pj-name"><Input id="pj-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <div className="flex items-end"><Button type="submit" disabled={create.isPending}>{ar ? 'إنشاء' : 'Create'}</Button></div>
        </div>
      </form>

      {notice !== null && (
        <div role="status" data-testid="settings-project-notice" className="flex items-center justify-between gap-3 rounded-xl border border-success bg-success-soft px-3 py-2 text-sm text-success">
          <span>{notice}</span>
          <button type="button" onClick={() => setNotice(null)} className="text-xs font-semibold opacity-80 hover:opacity-100">{ar ? 'إغلاق' : 'Dismiss'}</button>
        </div>
      )}

      <TabStrip testid="settings-projects-filter" label={ar ? 'تصفية المشاريع' : 'Filter projects'} items={filters} active={filter} onChange={(k) => setFilter(k as Filter)} />

      {current.isLoading ? <Skeleton className="h-48" /> : current.isError ? (
        <QueryFailure error={current.error} ar={ar} testId="settings-projects-list-failure" fallbackTitle={ar ? 'تعذّر تحميل المشاريع.' : 'The projects could not be loaded.'} />
      ) : (
        <ResponsiveList<Row>
          testid="settings-projects"
          rows={rows}
          columns={columns}
          rowKey={(p) => p.id}
          empty={<div className="rounded-2xl border border-border bg-surface p-6"><EmptyState title={lt(`projects.lifecycle.empty.${filter}`, ar)} /></div>}
          renderCard={(p) => (
            <div className="min-w-0 rounded-2xl border border-border bg-surface p-3" data-testid={`settings-project-card-${p.id}`}>
              <div className="flex min-w-0 items-start justify-between gap-2">
                <span className="inline-flex min-w-0 items-center gap-2 font-semibold text-text-primary"><FolderKanban size={15} className="shrink-0 text-brand-600" aria-hidden /> <span className="truncate">{p.name}</span></span>
                {statusBadge(p)}
              </div>
              <p className="mt-1 truncate text-xs text-text-secondary">{lt('projects.lifecycle.col.client', ar)}: {clientName(p)}</p>
              <p className="mt-0.5 text-xs text-text-muted">{fourth.header}: {fourth.cell(p)}</p>
              <div className="mt-2 border-t border-border pt-2">{actions(p, true)}</div>
            </div>
          )}
        />
      )}
      <p className="text-xs text-text-muted">{ar ? 'مصادر البيانات ومصدر الحقيقة ونافذة الإسناد وأعضاء المشروع تُدار من صفحة المشروع التفصيلية.' : 'Data sources, the source of truth, the attribution window and project members are managed on the project’s own page.'}</p>

      <ProjectLifecycleDialog state={dialog} ar={ar} onClose={() => setDialog(null)} onDone={(message) => { setDialog(null); setNotice(message) }} />
    </div>
  )
}
