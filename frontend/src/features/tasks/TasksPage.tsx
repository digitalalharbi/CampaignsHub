import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, LayoutGrid, ListChecks, Plus, Rows3, X } from 'lucide-react'
import { FilterBar, FilterSearch, FilterSelect } from '@/components/ui/FilterBar'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { DateField } from '@/components/ui/DateField'
import { fmtDate } from '@/lib/datetime'
import {
  OPEN_STATUSES, TASK_PRIORITIES, TASK_STATUSES, createTask, listTasks, updateTask,
  type NewTask, type Task,
} from './api'

const COPY = {
  ar: {
    title: 'المهام', subtitle: 'نظّم عمل الفريق وتابع التنفيذ — حسب الحالة والأولوية وتاريخ الاستحقاق.',
    new_task: 'مهمة جديدة', search_ph: 'ابحث في المهام…', mine: 'مهامي', all: 'الكل',
    view_list: 'قائمة', view_board: 'لوحة',
    sum_total: 'إجمالي المهام', sum_open: 'مفتوحة', sum_overdue: 'متأخرة', sum_done: 'مكتملة',
    none: 'لا توجد مهام بعد.', no_match: 'لا مهام تطابق البحث أو الفلاتر.',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل المهام.',
    priority: 'الأولوية', status: 'الحالة', due: 'الاستحقاق', overdue: 'متأخرة', complete: 'إكمال',
    title_l: 'العنوان', desc_l: 'الوصف', create: 'إنشاء المهمة', creating: 'جارٍ الإنشاء…', close: 'إغلاق', optional: 'اختياري',
  },
  en: {
    title: 'Tasks', subtitle: 'Organize the team’s work and track delivery — by status, priority and due date.',
    new_task: 'New task', search_ph: 'Search tasks…', mine: 'My tasks', all: 'All',
    view_list: 'List', view_board: 'Board',
    sum_total: 'Total tasks', sum_open: 'Open', sum_overdue: 'Overdue', sum_done: 'Completed',
    none: 'No tasks yet.', no_match: 'No tasks match your search or filters.',
    loading: 'Loading…', error: 'Could not load tasks.',
    priority: 'Priority', status: 'Status', due: 'Due', overdue: 'Overdue', complete: 'Complete',
    title_l: 'Title', desc_l: 'Description', create: 'Create task', creating: 'Creating…', close: 'Close', optional: 'optional',
  },
}
type Copy = (typeof COPY)['ar']

const STATUS_META: Record<string, { ar: string; en: string; tone: string }> = {
  open: { ar: 'مفتوحة', en: 'Open', tone: 'bg-info/15 text-info' },
  backlog: { ar: 'قائمة الانتظار', en: 'Backlog', tone: 'bg-surface-hover text-text-secondary' },
  todo: { ar: 'للتنفيذ', en: 'To do', tone: 'bg-info/15 text-info' },
  in_progress: { ar: 'قيد التنفيذ', en: 'In progress', tone: 'bg-brand-600/15 text-brand-600' },
  waiting_client: { ar: 'بانتظار العميل', en: 'Waiting on client', tone: 'bg-warning/15 text-warning' },
  blocked: { ar: 'متوقفة', en: 'Blocked', tone: 'bg-danger/15 text-danger' },
  review: { ar: 'مراجعة', en: 'Review', tone: 'bg-info/15 text-info' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'bg-success/15 text-success' },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', tone: 'bg-surface-hover text-text-tertiary' },
}
const PRIORITY_META: Record<string, { ar: string; en: string; tone: string }> = {
  low: { ar: 'منخفضة', en: 'Low', tone: 'text-text-tertiary' },
  normal: { ar: 'عادية', en: 'Normal', tone: 'text-text-secondary' },
  medium: { ar: 'متوسطة', en: 'Medium', tone: 'text-info' }, // legacy value written by some services
  high: { ar: 'عالية', en: 'High', tone: 'text-warning' },
  urgent: { ar: 'عاجلة', en: 'Urgent', tone: 'text-danger' },
}

// Filters use the canonical vocabulary (legacy 'open'/'medium' were normalized in the DB migration).
const FILTER_STATUSES: string[] = TASK_STATUSES
const FILTER_PRIORITIES: string[] = ['urgent', 'high', 'normal', 'low']
/** Options for a task's status select: canonical targets, plus its own current value if any stray value survives. */
const statusOptions = (current: string): string[] =>
  (TASK_STATUSES as string[]).includes(current) ? TASK_STATUSES : [current, ...TASK_STATUSES]
const statusLabel = (s: string, ar: boolean) => (STATUS_META[s] ? (ar ? STATUS_META[s].ar : STATUS_META[s].en) : s)
const priorityLabel = (p: string, ar: boolean) => (PRIORITY_META[p] ? (ar ? PRIORITY_META[p].ar : PRIORITY_META[p].en) : p)

export function TasksPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const qc = useQueryClient()
  const userId = useAuth((s) => s.user?.id)
  const canCreate = useAuth((s) => s.hasPermission('tasks.create'))
  const canUpdate = useAuth((s) => s.hasPermission('tasks.update'))

  const [term, setTerm] = useState('')
  const [status, setStatus] = useState<'all' | string>('all')
  const [priority, setPriority] = useState<'all' | string>('all')
  const [mine, setMine] = useState(false)
  const [view, setView] = useState<'list' | 'board'>('list')
  const [creating, setCreating] = useState(false)
  const [selected, setSelected] = useState<Task | null>(null)

  const q = useQuery({ queryKey: ['tasks', 'all'], queryFn: () => listTasks() })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['tasks'] })
  const all = q.data ?? []

  const summary = {
    total: all.length,
    open: all.filter((t) => (OPEN_STATUSES as string[]).includes(t.status)).length,
    overdue: all.filter((t) => t.is_overdue).length,
    done: all.filter((t) => t.status === 'completed').length,
  }

  const needle = term.trim().toLowerCase()
  const tasks = all.filter((t) => {
    if (status !== 'all' && t.status !== status) return false
    if (priority !== 'all' && t.priority !== priority) return false
    if (mine && String(t.assignee_id ?? '') !== String(userId ?? '')) return false
    if (needle && !`${t.title} ${t.description ?? ''}`.toLowerCase().includes(needle)) return false
    return true
  })

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
        {canCreate ? (
          <button onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700">
            <Plus size={15} /> {c.new_task}
          </button>
        ) : null}
      </header>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard label={c.sum_total} value={summary.total} tone="brand" unknown={q.isError} />
        <SummaryCard label={c.sum_open} value={summary.open} tone="info" unknown={q.isError} />
        <SummaryCard label={c.sum_overdue} value={summary.overdue} tone="danger" unknown={q.isError} />
        <SummaryCard label={c.sum_done} value={summary.done} tone="success" unknown={q.isError} />
      </div>

      {/*
        The filters, on the page — UX-SWEEP-001.

        SIMPLIFY-002 folded status, assignee and priority behind one button. Those are the three
        questions somebody opens a task list to ask; folding them left a page whose only visible
        controls were a search box and a view switcher, which reads as a list that cannot be
        narrowed at all. They are inline now, with the applied ones as chips that each undo their
        own value. Nothing about this page has a rare axis, so there is no «More filters» at all.
      */}
      <FilterBar
        id="tasks"
        ar={ar}
        applied={[
          ...(status === 'all' ? [] : [{
            key: `status:${status}`,
            axis: ar ? 'الحالة' : 'Status',
            label: statusLabel(status, ar),
            onRemove: () => setStatus('all'),
          }]),
          ...(priority === 'all' ? [] : [{
            key: `priority:${priority}`,
            axis: c.priority,
            label: priorityLabel(priority, ar),
            onRemove: () => setPriority('all'),
          }]),
          ...(mine ? [{
            key: 'mine',
            axis: ar ? 'المُسنَدة' : 'Assigned to',
            label: c.mine,
            onRemove: () => setMine(false),
          }] : []),
        ]}
        onReset={() => { setStatus('all'); setPriority('all'); setMine(false) }}
        trailing={
          <div className="flex overflow-hidden rounded-lg border border-border">
            <button onClick={() => setView('list')} aria-label={c.view_list} title={c.view_list}
              className={`flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold ${view === 'list' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}>
              <Rows3 size={14} />
            </button>
            <button onClick={() => setView('board')} aria-label={c.view_board} title={c.view_board}
              className={`flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold ${view === 'board' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}>
              <LayoutGrid size={14} />
            </button>
          </div>
        }
      >
        <FilterSearch value={term} placeholder={c.search_ph} testid="tasks-search" onChange={setTerm} />

        <FilterSelect
          label={ar ? 'الحالة' : 'Status'}
          value={status}
          testid="tasks-status"
          options={[{ value: 'all', label: c.all }, ...FILTER_STATUSES.map((v) => ({ value: v, label: statusLabel(v, ar) }))]}
          onChange={setStatus}
        />

        <FilterSelect
          label={c.priority}
          value={priority}
          testid="tasks-priority"
          options={[{ value: 'all', label: c.all }, ...FILTER_PRIORITIES.map((v) => ({ value: v, label: priorityLabel(v, ar) }))]}
          onChange={setPriority}
        />

        <FilterSelect
          label={ar ? 'المُسنَدة' : 'Assigned to'}
          value={mine ? 'mine' : 'all'}
          testid="tasks-assignee"
          options={[{ value: 'all', label: c.all }, { value: 'mine', label: c.mine }]}
          onChange={(v) => setMine(v === 'mine')}
        />
      </FilterBar>

      {/* Body */}
      {q.isLoading ? (
        <StateBox>{c.loading}</StateBox>
      ) : q.isError ? (
        // AGENCY-PERMS: a refusal, an expired session and a dead server used to print the same
        // sentence here, and only the last of the three is something a Retry button can fix.
        <QueryFailure error={q.error} ar={ar} fallbackTitle={c.error} testId="tasks-failure" onRetry={() => q.refetch()} />
      ) : tasks.length === 0 ? (
        <StateBox>{all.length === 0 ? c.none : c.no_match}</StateBox>
      ) : view === 'board' ? (
        <BoardView tasks={tasks} ar={ar} canUpdate={canUpdate}
          onStatus={(id, s) => updateStatus(id, s)} />
      ) : (
        <ul className="flex flex-col gap-2">
          {tasks.map((t) => (
            <TaskRow key={t.id} task={t} c={c} ar={ar} canUpdate={canUpdate}
              onStatus={(s) => updateStatus(t.id, s)} onOpen={() => setSelected(t)} />
          ))}
        </ul>
      )}

      {selected && (
        <TaskDrawer task={selected} c={c} ar={ar} canUpdate={canUpdate}
          onClose={() => setSelected(null)}
          onStatus={(s) => { updateStatus(selected.id, s); setSelected({ ...selected, status: s }) }} />
      )}

      {creating ? (
        <CreateTaskDrawer c={c} ar={ar} onClose={() => setCreating(false)} onCreated={() => { setCreating(false); invalidate() }} />
      ) : null}
    </div>
  )

  function updateStatus(id: string, s: string) {
    updateTask(id, { status: s }).then(invalidate)
  }
}

function StateBox({ children, tone }: { children: React.ReactNode; tone?: 'danger' }) {
  return (
    <p className={`rounded-xl border border-dashed p-10 text-center text-sm ${
      tone === 'danger' ? 'border-danger/30 bg-danger/5 text-danger' : 'border-border text-text-secondary'
    }`}>{children}</p>
  )
}


/**
 * A count, or «—» when the list could not be read (AGENCY-PERMS).
 *
 * With the refusal arm in place these four cards still read 0 · 0 · 0 · 0, which turns a permission
 * boundary into an empty state — the exact substitution the product forbids. Nobody refused the
 * list knows how many tasks there are, so the honest figure is "not available", not zero.
 */
function SummaryCard({ label, value, tone, unknown }: { label: string; value: number; tone: 'brand' | 'info' | 'danger' | 'success'; unknown?: boolean }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', info: 'bg-info', danger: 'bg-danger', success: 'bg-success' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className={`text-2xl font-extrabold tnum ${unknown ? 'text-text-muted' : 'text-text-primary'}`} dir="ltr">
        {unknown ? '—' : value}
      </span>
    </div>
  )
}

function StatusBadge({ status, ar }: { status: string; ar: boolean }) {
  const m = STATUS_META[status]
  return <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${m?.tone ?? 'bg-surface-hover text-text-secondary'}`}>{statusLabel(status, ar)}</span>
}

function TaskRow({ task, c, ar, canUpdate, onStatus, onOpen }: { task: Task; c: Copy; ar: boolean; canUpdate: boolean; onStatus: (s: string) => void; onOpen: () => void }) {
  const pr = PRIORITY_META[task.priority]
  const done = task.status === 'completed'
  return (
    <li className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex flex-1 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <button onClick={onOpen} className={`text-start font-semibold hover:text-brand-600 ${done ? 'text-text-tertiary line-through' : 'text-text-primary'}`}>{task.title}</button>
          <span className={`text-[11px] font-bold ${pr?.tone ?? 'text-text-secondary'}`}>● {priorityLabel(task.priority, ar)}</span>
          {task.is_overdue ? (
            <span className="inline-flex items-center gap-1 rounded-md bg-danger/10 px-1.5 py-0.5 text-[11px] font-semibold text-danger">
              <AlertTriangle size={12} /> {c.overdue}
            </span>
          ) : null}
        </div>
        {task.description ? <p className="line-clamp-1 text-sm text-text-secondary">{task.description}</p> : null}
        {task.due_date ? <span className="text-[11px] text-text-tertiary">{c.due}: <span className="tnum" dir="ltr">{fmtDate(task.due_date)}</span></span> : null}
      </div>
      <div className="flex items-center gap-2">
        <StatusBadge status={task.status} ar={ar} />
        {canUpdate ? (
          <select value={task.status} onChange={(e) => onStatus(e.target.value)}
            className="rounded-lg border border-border bg-surface px-2 py-1 text-xs font-semibold text-text-secondary">
            {statusOptions(task.status).map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
          </select>
        ) : null}
        {canUpdate && !done ? (
          <button onClick={() => onStatus('completed')} title={c.complete} aria-label={c.complete}
            className="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:border-success hover:text-success">
            <CheckCircle2 size={13} />
          </button>
        ) : null}
      </div>
    </li>
  )
}

function BoardView({ tasks, ar, canUpdate, onStatus }: { tasks: Task[]; ar: boolean; canUpdate: boolean; onStatus: (id: string, s: string) => void }) {
  // Columns: the open workflow stages plus completed — each is a lane of its tasks.
  const columns: string[] = [...OPEN_STATUSES, 'completed']
  return (
    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
      {columns.map((col) => {
        const items = tasks.filter((t) => t.status === col)
        return (
          <div key={col} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface-secondary/40 p-3">
            <div className="flex items-center justify-between">
              <StatusBadge status={col} ar={ar} />
              <span className="tnum text-xs font-bold text-text-tertiary" dir="ltr">{items.length}</span>
            </div>
            {items.length === 0 ? (
              <p className="rounded-lg border border-dashed border-border p-4 text-center text-[11px] text-text-tertiary">—</p>
            ) : items.map((t) => (
              <div key={t.id} className="flex flex-col gap-1 rounded-xl border border-border bg-surface p-3">
                <span className={`text-sm font-semibold ${t.status === 'completed' ? 'text-text-tertiary line-through' : 'text-text-primary'}`}>{t.title}</span>
                <div className="flex items-center justify-between">
                  <span className={`text-[11px] font-bold ${PRIORITY_META[t.priority]?.tone ?? 'text-text-secondary'}`}>● {priorityLabel(t.priority, ar)}</span>
                  {t.due_date ? <span className={`text-[11px] tnum ${t.is_overdue ? 'text-danger' : 'text-text-tertiary'}`} dir="ltr">{fmtDate(t.due_date)}</span> : null}
                </div>
                {canUpdate ? (
                  <select value={t.status} onChange={(e) => onStatus(t.id, e.target.value)}
                    className="mt-1 rounded-lg border border-border bg-surface px-2 py-1 text-[11px] font-semibold text-text-secondary">
                    {statusOptions(t.status).map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
                  </select>
                ) : null}
              </div>
            ))}
          </div>
        )
      })}
    </div>
  )
}

/** Task detail — the full record plus its real status action, in a slide-over. */
function TaskDrawer({ task, c, ar, canUpdate, onClose, onStatus }: { task: Task; c: Copy; ar: boolean; canUpdate: boolean; onClose: () => void; onStatus: (s: string) => void }) {
  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <div className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-extrabold text-text-primary">{task.title}</h2>
          <button onClick={onClose} aria-label={c.close} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover"><X size={18} /></button>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={task.status} ar={ar} />
          <span className={`text-[11px] font-bold ${PRIORITY_META[task.priority]?.tone ?? 'text-text-secondary'}`}>● {priorityLabel(task.priority, ar)}</span>
          {task.is_overdue && (
            <span className="inline-flex items-center gap-1 rounded-md bg-danger/10 px-1.5 py-0.5 text-[11px] font-semibold text-danger">
              <AlertTriangle size={12} /> {c.overdue}
            </span>
          )}
        </div>

        {task.description && <p className="whitespace-pre-wrap rounded-xl bg-surface-hover px-3 py-2 text-sm text-text-secondary">{task.description}</p>}

        <dl className="flex flex-col gap-2 rounded-2xl border border-border p-4 text-sm">
          <div className="flex items-center justify-between gap-3">
            <dt className="text-text-secondary">{c.due}</dt>
            <dd className={`tnum font-semibold ${task.is_overdue ? 'text-danger' : 'text-text-primary'}`} dir="ltr">{task.due_date ? fmtDate(task.due_date) : '—'}</dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-text-secondary">{c.status}</dt>
            <dd className="font-semibold text-text-primary">{statusLabel(task.status, ar)}</dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-text-secondary">{c.priority}</dt>
            <dd className="font-semibold text-text-primary">{priorityLabel(task.priority, ar)}</dd>
          </div>
        </dl>

        {canUpdate && (
          <div className="flex flex-col gap-2">
            <label className="text-xs font-semibold text-text-secondary">{c.status}</label>
            <select value={task.status} onChange={(e) => onStatus(e.target.value)}
              className="rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-text-primary">
              {statusOptions(task.status).map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
            </select>
            {task.status !== 'completed' && (
              <button onClick={() => onStatus('completed')}
                className="flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700">
                <CheckCircle2 size={15} /> {c.complete}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function CreateTaskDrawer({ c, ar, onClose, onCreated }: { c: Copy; ar: boolean; onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState<NewTask>({ title: '', priority: 'normal', status: 'todo' })
  const createM = useMutation({
    mutationFn: () => createTask({ ...form, title: form.title.trim(), description: (form.description ?? '').trim() || null }),
    onSuccess: onCreated,
  })
  const set = (patch: Partial<NewTask>) => setForm((f) => ({ ...f, ...patch }))

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={(e) => { e.preventDefault(); if (form.title.trim()) createM.mutate() }}
        className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl">
        <div className="flex items-start justify-between gap-3">
          <h2 className="flex items-center gap-2 text-lg font-extrabold text-text-primary"><ListChecks size={18} /> {c.new_task}</h2>
          <button type="button" onClick={onClose} aria-label={c.close} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover"><X size={18} /></button>
        </div>

        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.title_l}
          <input required maxLength={200} value={form.title} onChange={(e) => set({ title: e.target.value })} data-autofocus
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>

        <div className="grid grid-cols-2 gap-2">
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {c.priority}
            <select value={form.priority} onChange={(e) => set({ priority: e.target.value })}
              className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary">
              {TASK_PRIORITIES.map((p) => <option key={p} value={p}>{priorityLabel(p, ar)}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {c.status}
            <select value={form.status} onChange={(e) => set({ status: e.target.value })}
              className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary">
              {TASK_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
            </select>
          </label>
        </div>

        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {`${c.due} (${c.optional})`}
          <DateField value={form.due_date ?? ''} onChange={(v) => set({ due_date: v || null })} />
        </label>

        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {`${c.desc_l} (${c.optional})`}
          <textarea rows={3} maxLength={4000} value={form.description ?? ''} onChange={(e) => set({ description: e.target.value })}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
        </label>

        <button type="submit" disabled={createM.isPending || !form.title.trim()}
          className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
          {createM.isPending ? c.creating : c.create}
        </button>
      </form>
    </div>
  )
}
