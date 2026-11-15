import { OpsHubBar } from '@/features/ops/OpsHubBar'
import { Num } from '@/components/ui/Num'
import { useEffect, useMemo, useState } from 'react'
import { useDebouncedValue } from '@/components/forms/useTypeahead'
import { StatusMixBar } from '@/features/analytics/charts'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, BellRing, CheckCircle2, LayoutGrid, ListChecks, Plus, Rows3, Truck, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { FilterBar, FilterSearch, FilterSelect } from '@/components/ui/FilterBar'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { PRIORITY_META, STATUS_META, priorityLabel, statusLabel } from './labels'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip, type KpiTone } from '@/components/fit/KpiMini'
import { Pager } from '@/components/fit/Pager'
import { useFitRows } from '@/components/fit/useFitRows'
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

// Filters use the canonical vocabulary (legacy 'open'/'medium' were normalized in the DB migration).
const FILTER_STATUSES: string[] = TASK_STATUSES
const FILTER_PRIORITIES: string[] = ['urgent', 'high', 'normal', 'low']
/** Options for a task's status select: canonical targets, plus its own current value if any stray value survives. */
const statusOptions = (current: string): string[] =>
  (TASK_STATUSES as string[]).includes(current) ? TASK_STATUSES : [current, ...TASK_STATUSES]

export function TasksPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const qc = useQueryClient()
  const canCreate = useAuth((s) => s.hasPermission('tasks.create'))
  const canUpdate = useAuth((s) => s.hasPermission('tasks.update'))

  const [term, setTerm] = useState('')
  const [status, setStatus] = useState<'all' | string>('all')
  const [priority, setPriority] = useState<'all' | string>('all')
  const [mine, setMine] = useState(false)
  const [view, setView] = useState<'list' | 'board'>('list')
  const [creating, setCreating] = useState(false)
  const [selected, setSelected] = useState<Task | null>(null)

  /*
   * TASKS-LEDGER-001 — the filters and the counts are the server's now.
   *
   * The endpoint handed over every task — its own comment cites 2,105 in one tenant — and this page
   * fetched all of them, sifted them in the browser, and computed «open», «overdue» and «done» from
   * whatever it had. Both halves had to move: paginating alone would leave those three counts
   * describing the page, which is the defect the alerts queue was fixed for, one screen along.
   *
   * The search is debounced into the query key rather than filtering a local array, because the
   * array is now one page and a match behind it would simply not exist as far as the reader is told.
   */
  const needle = useDebouncedValue(term.trim(), 250)

  const [page, setPage] = useState(1)
  /*
   * NOV-FIT-001 — the server is asked for as many tasks as the screen holds. A list row is `TASK_ROW` px and the
   * mix strip and pager below it are reserved; the board shows `laneCap` cards per lane and says how many more.
   */
  const fit = useFitRows({ rowHeight: TASK_ROW + 8, reserve: 44 + 76, min: 3, max: 25, fallback: 10 })
  const perPage = view === 'board' ? 25 : fit.rows
  const laneCap = Math.max(1, Math.floor((fit.rows * (TASK_ROW + 8)) / 78))

  const filters = useMemo(
    () => ({
      status: status === 'all' ? undefined : status,
      priority: priority === 'all' ? undefined : priority,
      q: needle || undefined,
      mine: mine || undefined,
    }),
    [status, priority, needle, mine],
  )

  /*
   * «Nothing here» and «nothing matched» are different sentences, and the page can no longer tell
   * them apart by measuring an array it holds — it holds one page of a filtered query. The filters
   * themselves answer it: with none applied, an empty result IS an empty workspace.
   */
  const unfiltered = Object.values(filters).every((v) => v === undefined)

  /* Any change of filter puts the reader back on page one — page 3 of a narrower list is nowhere. */
  useEffect(() => setPage(1), [status, priority, needle, mine])

  const q = useQuery({
    queryKey: ['tasks', 'page', filters, page, perPage],
    queryFn: () => listTasks({ ...filters, page, perPage }),
    placeholderData: (prev) => prev,
  })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['tasks'] })

  const tasks = q.data?.tasks ?? []

  const summary = {
    total: q.data?.total ?? 0,
    open: q.data?.counts.open ?? 0,
    overdue: q.data?.counts.overdue ?? 0,
    done: q.data?.counts.done ?? 0,
  }

  return (
    <FitPage
      testid="tasks-page"
      introTestid="tasks-intro"
      title={c.title}
      info={c.subtitle}
      actions={canCreate ? (
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700">
          <Plus size={15} /> {c.new_task}
        </button>
      ) : undefined}
      kpis={
        /*
          UX-PAGE-HERO-001 · PRODUCT-VISUAL-001 §20 — the queue's state, in the head.

          The cards keep their «unknown» handling, which is what makes a failed request read as «we do not
          know» instead of as four zeros. `tasks-intro-kpis` is the handle the first-viewport sweep reads.
        */
        <KpiStrip testid="tasks-intro-kpis">
          <SummaryCard label={c.sum_total} value={summary.total} tone="brand" icon={ListChecks} unknown={q.isError} />
          <SummaryCard label={c.sum_open} value={summary.open} tone="info" icon={Rows3} unknown={q.isError} />
          {/*
            ALERTS-COPY-001, on the tasks board — nothing overdue is good news, painted red.

            `tone="danger"` was unconditional, so a team that is fully on schedule met a red card
            announcing it. Colour is a judgement, and a red zero spends the reader's attention on
            the one number that did not need it — which is how people learn to stop looking at red.
          */}
          <SummaryCard label={c.sum_overdue} value={summary.overdue} tone={summary.overdue > 0 ? 'danger' : 'success'} icon={AlertTriangle} unknown={q.isError} />
          <SummaryCard label={c.sum_done} value={summary.done} tone="success" icon={CheckCircle2} unknown={q.isError} />
        </KpiStrip>
      }
      toolbar={
        <>
          <OpsHubBar />
          {/*
            The filters, on the page — UX-SWEEP-001.

            Status, assignee and priority are the three questions somebody opens a task list to ask, so they
            are inline, with the applied ones as chips that each undo their own value. Dense (NOV-FIT-001):
            one row on a desktop.
          */}
          <FilterBar
            id="tasks"
            ar={ar}
            density="dense"
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
        </>
      }
    >
      <div ref={fit.ref} className="flex min-h-0 flex-1 flex-col gap-2">
        <div className="min-h-0 flex-1">
          {q.isLoading ? (
            <StateBox>{c.loading}</StateBox>
          ) : q.isError ? (
            // AGENCY-PERMS: a refusal, an expired session and a dead server used to print the same
            // sentence here, and only the last of the three is something a Retry button can fix.
            <QueryFailure error={q.error} ar={ar} fallbackTitle={c.error} testId="tasks-failure" onRetry={() => q.refetch()} />
          ) : tasks.length === 0 ? (
            <StateBox>{unfiltered ? c.none : c.no_match}</StateBox>
          ) : view === 'board' ? (
            <BoardView tasks={tasks} ar={ar} canUpdate={canUpdate} cap={laneCap}
              onStatus={(id, s) => updateStatus(id, s)} onOpen={setSelected} />
          ) : (
            <ul className="flex flex-col gap-2">
              {tasks.map((t) => (
                <TaskRow key={t.id} task={t} c={c} ar={ar} canUpdate={canUpdate}
                  onStatus={(s) => updateStatus(t.id, s)} onOpen={() => setSelected(t)} />
              ))}
            </ul>
          )}
        </div>

        {/*
          VIZ-OPS-001 — how much of the ledger is still open, AFTER the ledger itself, as one slim strip.

          The owner's rule: «the feature and the service come first on the page, clearly, without
          needing to scroll — and the analytical results after them.» A task board is a place to DO the
          work; the reading of it comes once the work is in view.

          ## `overdue` is NOT a band here, and drawing it as one would be wrong arithmetic

          The server counts it as «a due date in the past that nobody has finished» — a SUBSET of open,
          not a sibling. Putting the three in one bar would count every overdue task twice and push the
          bands past the total, which `StatusMixBar` refuses outright rather than normalising. So the
          bar divides open · done · everything else, and lateness is stated where it lives: inside open.

          «Everything else» is a real band, not padding: `cancelled` is neither open nor done, so the
          two do not cover the ledger, and calling their sum the total would make both shares too large.
        */}
        {summary.total > 0 && (
          <div className="shrink-0 rounded-xl border border-border bg-surface px-3 py-2">
            <StatusMixBar
              testId="task-mix"
              ar={ar}
              label={ar ? 'حالة المهام' : 'How the board stands'}
              total={summary.total}
              residualLabel={ar ? 'أخرى' : 'Neither'}
              bands={[
                { key: 'open', label: ar ? 'مفتوحة' : 'Open', count: summary.open, tone: 'info' },
                { key: 'done', label: ar ? 'منجزة' : 'Done', count: summary.done, tone: 'success' },
              ]}
            />
            {summary.overdue > 0 && (
              <p data-testid="task-overdue-note" className="mt-1 text-xs text-warning">
                {ar
                  ? `${summary.overdue.toLocaleString('en-US')} من المهام المفتوحة تجاوزت موعدها.`
                  : `${summary.overdue.toLocaleString('en-US')} of the open tasks are past their due date.`}
              </p>
            )}
          </div>
        )}

        <Pager
          testid="tasks-pager"
          hideWhenSingle
          ar={ar}
          page={q.data?.page ?? 1}
          pages={q.data?.lastPage ?? 1}
          total={summary.total}
          onPage={setPage}
        />
      </div>

      {selected && (
        <TaskDrawer task={selected} c={c} ar={ar} canUpdate={canUpdate}
          onClose={() => setSelected(null)}
          onStatus={(s) => { updateStatus(selected.id, s); setSelected({ ...selected, status: s }) }} />
      )}

      {creating ? (
        <CreateTaskDrawer c={c} ar={ar} onClose={() => setCreating(false)} onCreated={() => { setCreating(false); invalidate() }} />
      ) : null}
    </FitPage>
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
/** UX-KPI-PRESENTATION-001 — the shared card. The tone and the em dash are this surface's, not a second design. */
function SummaryCard({ label, value, tone, unknown, icon }: { label: string; value: number; tone: KpiTone; unknown?: boolean; icon: typeof ListChecks }) {
  return <KpiMini label={label} value={unknown ? '—' : value} tone={tone} icon={icon} />
}

function StatusBadge({ status, ar }: { status: string; ar: boolean }) {
  const m = STATUS_META[status]
  return <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${m?.tone ?? 'bg-surface-hover text-text-secondary'}`}>{statusLabel(status, ar)}</span>
}

/**
 * OPS-LINK-001 — where a task came from and who holds it, in one compact line.
 *
 * An alert-opened task links back to its alert, so the person doing the work can see the measurement that raised it;
 * every other task says what created it. Nothing here is inferred from a title.
 */
const SOURCE_LABEL: Record<string, { ar: string; en: string }> = {
  manual: { ar: 'يدوية', en: 'Manual' },
  alert: { ar: 'من تنبيه', en: 'From an alert' },
  delivery: { ar: 'من التسليم', en: 'From delivery' },
  rule: { ar: 'من قاعدة', en: 'From a rule' },
  request: { ar: 'من طلب', en: 'From a request' },
}

function TaskOrigin({ task, ar }: { task: Task; ar: boolean }) {
  const portal = usePortalPath()
  const src = task.source
  if (!src && !task.assignee_name && !task.created_by_name) return null
  const label = src ? (SOURCE_LABEL[src.kind]?.[ar ? 'ar' : 'en'] ?? src.kind) : null

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-text-muted" data-testid={`task-origin-${task.id}`}>
      {src && src.kind === 'alert' && 'id' in src
        ? (
          <Link to={portal(`/alerts?event=${src.id}`)} className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline" data-testid={`task-source-alert-${task.id}`}>
            <BellRing size={12} aria-hidden /> {label}: <span className="text-text-secondary">{src.title}</span>
          </Link>
        )
        : src && src.kind === 'delivery' && 'client_workspace_id' in src
          ? (
            <Link to={portal(`/clients/${src.client_workspace_id}?tab=delivery`)} className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline" data-testid={`task-source-delivery-${task.id}`}>
              <Truck size={12} aria-hidden /> {label}: <span className="text-text-secondary" dir="ltr">{src.period}</span>
            </Link>
          )
          : label && <span data-testid={`task-source-${task.id}`}>{label}</span>}
      {task.assignee_name && <span>{ar ? 'المسؤول: ' : 'Owner: '}<b className="text-text-secondary">{task.assignee_name}</b></span>}
      {task.created_by_name && <span>{ar ? 'أنشأها: ' : 'Raised by: '}<b className="text-text-secondary">{task.created_by_name}</b></span>}
    </div>
  )
}

/** A list row is this tall (px): one line of title and chips, one of origin and due date. */
const TASK_ROW = 64

function TaskRow({ task, c, ar, canUpdate, onStatus, onOpen }: { task: Task; c: Copy; ar: boolean; canUpdate: boolean; onStatus: (s: string) => void; onOpen: () => void }) {
  const pr = PRIORITY_META[task.priority]
  const done = task.status === 'completed'
  return (
    <li data-testid="task-row" className="flex flex-col gap-2 rounded-xl border border-border bg-surface px-3 py-2 sm:flex-row sm:items-center sm:justify-between md:h-16 md:overflow-hidden">
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 items-center gap-2">
          <button onClick={onOpen} title={task.description ?? task.title} className={`min-w-0 truncate text-start font-semibold hover:text-brand-600 ${done ? 'text-text-muted line-through' : 'text-text-primary'}`}>{task.title}</button>
          <span className={`shrink-0 text-xs font-bold ${pr?.tone ?? 'text-text-secondary'}`}>● {priorityLabel(task.priority, ar)}</span>
          {task.is_overdue ? (
            <span className="inline-flex shrink-0 items-center gap-1 rounded-md bg-danger/10 px-1.5 py-0.5 text-xs font-semibold text-danger">
              <AlertTriangle size={12} /> {c.overdue}
            </span>
          ) : null}
        </div>
        <div className="flex min-w-0 items-center gap-x-3 md:h-5 md:overflow-hidden">
          <TaskOrigin task={task} ar={ar} />
          {task.due_date ? <span className="shrink-0 text-xs text-text-muted">{c.due}: <span className="tnum" dir="ltr">{fmtDate(task.due_date)}</span></span> : null}
        </div>
      </div>
      <div className="flex items-center gap-2">
        {/*
          The status is said ONCE.

          A badge reading «قيد التنفيذ» sat directly beside a select whose chosen option read «قيد
          التنفيذ» — the same word twice, a centimetre apart, on every row an operator can edit. The
          select is the better half to keep: it states the status AND is the control that changes
          it, whereas the badge only stated it.

          What the badge carried that a bare select did not is COLOUR, which is how a long list is
          scanned — «متوقفة» red, «مكتملة» green. So the tone moves onto the select rather than
          being dropped, and the badge stays for the reader who cannot change the status, where it
          is the only thing saying what the status is.
        */}
        {canUpdate ? (
          <select value={task.status} onChange={(e) => onStatus(e.target.value)}
            /* Named with the task, because «Status» alone repeated down a list identifies nothing. */
            aria-label={`${c.status}: ${task.title}`}
            className={`rounded-lg border border-border px-2 py-1 text-xs font-semibold ${STATUS_META[task.status]?.tone ?? 'bg-surface text-text-secondary'}`}>
            {statusOptions(task.status).map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
          </select>
        ) : (
          <StatusBadge status={task.status} ar={ar} />
        )}
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

function BoardView({ tasks, ar, canUpdate, onStatus, onOpen, cap }: { tasks: Task[]; ar: boolean; canUpdate: boolean; onStatus: (id: string, s: string) => void; onOpen: (t: Task) => void; cap: number }) {
  // Columns: the open workflow stages plus completed — each is a lane of its tasks.
  const columns: string[] = [...OPEN_STATUSES, 'completed']
  return (
    <div className="grid h-full gap-2 md:grid-cols-3 xl:grid-cols-6">
      {columns.map((col) => {
        const items = tasks.filter((t) => t.status === col)
        const shown = items.slice(0, cap)
        return (
          <div key={col} className="flex min-h-0 flex-col gap-1.5 overflow-hidden rounded-xl border border-border bg-surface-secondary/40 p-2">
            <div className="flex items-center justify-between">
              <StatusBadge status={col} ar={ar} />
              <span className="tnum text-xs font-bold text-text-muted" dir="ltr">{items.length}</span>
            </div>
            {items.length === 0 ? (
              <p className="rounded-lg border border-dashed border-border p-2 text-center text-xs text-text-muted">—</p>
            ) : shown.map((t) => (
              <div key={t.id} className="flex flex-col gap-1 rounded-lg border border-border bg-surface p-2">
                <button type="button" onClick={() => onOpen(t)} title={t.title} className={`truncate text-start text-sm font-semibold ${t.status === 'completed' ? 'text-text-muted line-through' : 'text-text-primary'}`}>{t.title}</button>
                <div className="flex items-center justify-between gap-1">
                  <span className={`text-xs font-bold ${PRIORITY_META[t.priority]?.tone ?? 'text-text-secondary'}`}>● {priorityLabel(t.priority, ar)}</span>
                  {t.due_date ? <span className={`text-xs tnum ${t.is_overdue ? 'text-danger' : 'text-text-muted'}`} dir="ltr">{fmtDate(t.due_date)}</span> : null}
                </div>
                {canUpdate ? (
                  <select value={t.status} onChange={(e) => onStatus(t.id, e.target.value)}
                    aria-label={`${ar ? 'الحالة' : 'Status'}: ${t.title}`}
                    className="rounded-md border border-border bg-surface px-1.5 py-0.5 text-xs font-semibold text-text-secondary">
                    {statusOptions(t.status).map((s) => <option key={s} value={s}>{statusLabel(s, ar)}</option>)}
                  </select>
                ) : null}
              </div>
            ))}
            {/* Honest about what the lane is not showing — «+3» rather than a lane that silently stops. */}
            {items.length > shown.length && (
              <p data-testid={`board-more-${col}`} className="text-center text-xs font-semibold text-text-muted"><span className="tnum" dir="ltr">+{items.length - shown.length}</span></p>
            )}
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
            <dd className={`tnum font-semibold ${task.is_overdue ? 'text-danger' : 'text-text-primary'}`}><Num>{task.due_date ? fmtDate(task.due_date) : '—'}</Num></dd>
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
