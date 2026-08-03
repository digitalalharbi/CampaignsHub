import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Columns3, Inbox, LayoutGrid, Search, Table as TableIcon } from 'lucide-react'
import { ALLOWED_TRANSITIONS, changeRequestStatus, listRequests, type RequestFilters, type RequestRow } from './internalApi'
import { STATUS_LABELS, priorityTone, statusTone } from './labels'
import { SearchableSelect } from '@/components/forms'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { useT, type TranslationKey } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

// Board-column layout order (a request-workflow layout constant, not a select's option source — those now come
// from the taxonomy engine). Terminal states (rejected/cancelled/archived) are intentionally not columns.
/*
 * REQ-JOURNEY-001 — the board is the journey, in order: جديد → مراجعة → عرض → موافقة → تنفيذ →
 * تسليم → مكتمل.
 *
 * «معلّق» and «ينتظر العميل» are deliberately NOT columns. They are pauses, not places: a held request
 * still belongs to whatever step it was on, and giving a pause its own column would hide a week's work
 * behind a lane nobody scrolls to. They show as a badge on the card instead.
 */
const KANBAN_COLUMNS = ['new', 'under_review', 'qualified', 'quoted', 'approved', 'in_progress', 'delivered', 'completed']
const VIEW_KEY = 'ch-requests-view'
type View = 'table' | 'kanban' | 'cards'

export function RequestsDashboardPage() {
  const t = useT()
  const qc = useQueryClient()
  const [filters, setFilters] = useState<RequestFilters>({ page: 1, per_page: 100 })
  const [search, setSearch] = useState('')
  const [view, setView] = useState<View>(() => (localStorage.getItem(VIEW_KEY) as View) || 'table')
  const query = useQuery({ queryKey: ['app', 'requests', filters], queryFn: () => listRequests(filters) })

  // Filter option sets from the central engine — keys match the request state machine / priority validator.
  const statusTax = useTaxonomyOptions('request.status')
  const priorityTax = useTaxonomyOptions('request.priority')

  const set = (patch: Partial<RequestFilters>) => setFilters((f) => ({ ...f, ...patch, page: 1 }))
  const setViewPref = (v: View) => { setView(v); localStorage.setItem(VIEW_KEY, v) }
  const rows = query.data?.data ?? []
  const ar = useUi((s) => s.locale) === 'ar'
  const summary = {
    total: query.data?.meta?.total ?? rows.length,
    new: rows.filter((r) => r.status === 'new').length,
    review: rows.filter((r) => r.status === 'under_review').length,
    waiting: rows.filter((r) => r.status === 'waiting_client').length,
  }

  // Kanban move: optimistic status change, rollback (refetch) on failure. Backend is the state-machine authority.
  const move = useMutation({
    mutationFn: ({ id, to }: { id: string; to: string }) => changeRequestStatus(id, to),
    onError: () => qc.invalidateQueries({ queryKey: ['app', 'requests', filters] }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['app', 'requests', filters] }),
  })

  return (
    <div className="w-full">
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{t('requests_inbox')}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t('requests_inbox_subtitle')}</p>
        </div>
        <div className="flex rounded-lg border border-border bg-surface p-0.5">
          {([['table', TableIcon], ['kanban', Columns3], ['cards', LayoutGrid]] as const).map(([v, Icon]) => (
            <button key={v} onClick={() => setViewPref(v)} aria-label={v} className={`flex h-8 w-9 items-center justify-center rounded-md ${view === v ? 'bg-brand-primary-soft text-brand-700' : 'text-text-muted hover:text-text-primary'}`}><Icon size={16} /></button>
          ))}
        </div>
      </header>

      {/* Summary — inbox at a glance. */}
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <ReqSummaryCard label={ar ? 'إجمالي الطلبات' : 'Total requests'} value={summary.total} tone="brand" />
        <ReqSummaryCard label={ar ? 'جديدة' : 'New'} value={summary.new} tone="info" />
        <ReqSummaryCard label={ar ? 'قيد المراجعة' : 'Under review'} value={summary.review} tone="warning" />
        <ReqSummaryCard label={ar ? 'بانتظار العميل' : 'Waiting on client'} value={summary.waiting} tone="muted" />
      </div>

      {/* Filters */}
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); set({ q: search || undefined }) }}>
          <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('search')} className="h-10 w-56 rounded-lg border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500" />
        </form>
        <div className="w-48">
          <SearchableSelect
            value={filters.status ?? null}
            onChange={(v) => set({ status: v ?? undefined })}
            options={statusTax.options}
            loading={statusTax.isPending}
            optionsError={statusTax.isError ? t('error_generic') : null}
            onRetry={() => statusTax.refetch()}
            placeholder={t('all_statuses')}
          />
        </div>
        <div className="w-48">
          <SearchableSelect
            value={filters.priority ?? null}
            onChange={(v) => set({ priority: v ?? undefined })}
            options={priorityTax.options}
            loading={priorityTax.isPending}
            optionsError={priorityTax.isError ? t('error_generic') : null}
            onRetry={() => priorityTax.refetch()}
            placeholder={t('all_priorities')}
          />
        </div>
      </div>

      {query.isLoading ? (
        <div className="space-y-2 rounded-2xl border border-border bg-surface p-4">{[0, 1, 2, 3, 4].map((i) => <div key={i} className="h-11 animate-pulse rounded-lg bg-surface-secondary" />)}</div>
      ) : query.isError ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-sm text-danger"><AlertTriangle size={22} /> {t('error_generic')}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Inbox size={26} /> <span className="text-sm">{t('requests_empty')}</span></div>
      ) : view === 'table' ? (
        <TableView rows={rows} t={t} ar={ar} />
      ) : view === 'cards' ? (
        <CardsView rows={rows} t={t} ar={ar} />
      ) : (
        <KanbanView rows={rows} onMove={(id, to) => move.mutate({ id, to })} ar={ar} />
      )}
    </div>
  )
}

function TableView({ rows, t, ar }: { rows: RequestRow[]; t: (k: TranslationKey) => string; ar: boolean }) {
  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-surface">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-xs font-semibold text-text-muted">
              {(['col_reference', 'col_service', 'col_contact', 'col_status', 'col_priority', 'col_assignee', 'col_sla'] as const).map((c) => <th key={c} className="px-4 py-3 text-start">{t(c)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-border last:border-0 hover:bg-surface-hover">
                <td className="px-4 py-3"><Link to={`/app/requests/${r.id}`} className="font-mono font-semibold text-brand-600 hover:underline" dir="ltr">{r.reference}</Link></td>
                <td className="px-4 py-3 text-text-secondary">{r.service_ar}</td>
                <td className="px-4 py-3 text-text-primary">{r.contact}</td>
                {/*
                  REQ-LABELS-001 — the name, not the key.
                  This rendered `r.status_label` (English-only, from the API) and `r.priority` (the raw
                  key), so an Arabic inbox read «Under Review» and «medium». Both labels now arrive in
                  both languages and the locale chooses, which also means the language toggle does not
                  need a refetch to take effect.
                */}
                <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${statusTone(r.status)}`}>{ar ? r.status_label : r.status_label_en}</span></td>
                <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${priorityTone(r.priority)}`}>{ar ? r.priority_label : r.priority_label_en}</span></td>
                <td className="px-4 py-3 text-text-secondary">{r.assignee ?? '—'}</td>
                <td className="px-4 py-3">{r.sla_breached ? <span className="text-xs font-semibold text-danger">{t('sla_overdue')}</span> : <span className="tnum text-xs text-text-muted" dir="ltr">{r.sla_due_at?.slice(0, 10) ?? '—'}</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function CardsView({ rows, t, ar }: { rows: RequestRow[]; t: (k: TranslationKey) => string; ar: boolean }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {rows.map((r) => (
        <Link key={r.id} to={`/app/requests/${r.id}`} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4 hover:border-brand-400">
          <div className="flex items-center justify-between">
            <span className="font-mono text-sm font-bold text-brand-600" dir="ltr">{r.reference}</span>
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusTone(r.status)}`}>{ar ? r.status_label : r.status_label_en}</span>
          </div>
          <div className="text-sm font-medium text-text-primary">{r.contact}</div>
          <div className="text-xs text-text-secondary">{r.service_ar}</div>
          <div className="mt-1 flex items-center justify-between">
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${priorityTone(r.priority)}`}>{ar ? r.priority_label : r.priority_label_en}</span>
            <span className="text-[11px] text-text-muted">{r.assignee ?? t('col_assignee')}</span>
          </div>
        </Link>
      ))}
    </div>
  )
}

function KanbanView({ rows, onMove, ar }: { rows: RequestRow[]; onMove: (id: string, to: string) => void; ar: boolean }) {
  const [dragId, setDragId] = useState<string | null>(null)
  const byStatus = (s: string) => rows.filter((r) => r.status === s)
  const dragging = rows.find((r) => r.id === dragId)

  return (
    <div className="flex gap-3 overflow-x-auto pb-2">
      {KANBAN_COLUMNS.map((col) => {
        const canDrop = dragging ? ALLOWED_TRANSITIONS[dragging.status]?.includes(col) : false
        return (
          <div
            key={col}
            onDragOver={(e) => { if (canDrop) e.preventDefault() }}
            onDrop={() => { if (dragId && canDrop) onMove(dragId, col); setDragId(null) }}
            className={`w-64 shrink-0 rounded-xl border p-2.5 ${canDrop ? 'border-brand-400 bg-brand-primary-soft/40' : 'border-border bg-surface-secondary'}`}
          >
            <div className="mb-2 flex items-center justify-between px-1 text-xs font-bold text-text-secondary">
              {/* The column heading follows the reader too — it was pinned to Arabic while the cards were pinned to English. */}
              <span>{ar ? STATUS_LABELS[col] : (rows.find((r) => r.status === col)?.status_label_en ?? STATUS_LABELS[col])}</span><span className="tnum text-text-muted">{byStatus(col).length}</span>
            </div>
            <div className="space-y-2">
              {byStatus(col).map((r) => (
                <div
                  key={r.id}
                  draggable
                  onDragStart={() => setDragId(r.id)}
                  onDragEnd={() => setDragId(null)}
                  className="cursor-grab rounded-lg border border-border bg-surface p-3 active:cursor-grabbing"
                >
                  <Link to={`/app/requests/${r.id}`} className="font-mono text-xs font-bold text-brand-600" dir="ltr">{r.reference}</Link>
                  <div className="mt-1 text-sm font-medium text-text-primary">{r.contact}</div>
                  <div className="mt-1.5 flex items-center justify-between">
                    <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${priorityTone(r.priority)}`}>{ar ? r.priority_label : r.priority_label_en}</span>
                    {r.sla_breached && <span className="text-[10px] font-semibold text-danger">SLA</span>}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function ReqSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'info' | 'warning' | 'muted' }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', info: 'bg-info', warning: 'bg-warning', muted: 'bg-text-muted' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className="tnum text-2xl font-extrabold text-text-primary" dir="ltr">{value}</span>
    </div>
  )
}
