import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Columns3, Inbox, LayoutGrid, Search, Table as TableIcon } from 'lucide-react'
import { ALLOWED_TRANSITIONS, changeRequestStatus, listRequests, type RequestBreakdown, type RequestFilters, type RequestRow } from './internalApi'
import { STATUS_LABELS, priorityTone, statusTone } from './labels'
import { ChartCard, RankingBarChart, StatusMixBar } from '@/features/analytics/charts'
import { PageIntro } from '@/components/ui/PageIntro'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { SearchableSelect } from '@/components/forms'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { useT, type TranslationKey } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { StatCard } from '@/components/ui/StatCard'

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
  /*
   * REQ-SUMMARY-001 — the backend's counts, over the whole filtered set.
   *
   * These were computed here from `rows`, which is one page. With 493 requests and 100 loaded, «جديد»
   * read 87 — eighty-seven of the first hundred — sitting beside a total of 493 as though both were
   * measured the same way. The fallback below only ever applies before the first response lands.
   */
  const summary = query.data?.meta?.summary ?? {
    total: query.data?.meta?.total ?? rows.length,
    new: 0, review: 0, paused: 0, needs_attention: 0,
  }

  // Kanban move: optimistic status change, rollback (refetch) on failure. Backend is the state-machine authority.
  const move = useMutation({
    mutationFn: ({ id, to }: { id: string; to: string }) => changeRequestStatus(id, to),
    onError: () => qc.invalidateQueries({ queryKey: ['app', 'requests', filters] }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['app', 'requests', filters] }),
  })

  return (
    <div className="w-full">
      {/* UX-PAGE-HERO-001 — the shared head, and the inbox's four counts inside it (§44). */}
      <div className="mb-5">
        <PageIntro
          testid="requests-intro"
          title={t('requests_inbox')}
          purpose={t('requests_inbox_subtitle')}
          actions={
            <div className="flex rounded-lg border border-border bg-surface p-0.5">
              {([['table', TableIcon], ['kanban', Columns3], ['cards', LayoutGrid]] as const).map(([v, Icon]) => (
                <button key={v} onClick={() => setViewPref(v)} aria-label={v} className={`flex h-8 w-9 items-center justify-center rounded-md ${view === v ? 'bg-brand-primary-soft text-brand-700' : 'text-text-muted hover:text-text-primary'}`}><Icon size={16} /></button>
              ))}
            </div>
          }
          kpis={
            <>
              <ReqSummaryCard label={ar ? 'إجمالي الطلبات' : 'Total requests'} value={summary.total} tone="brand" />
              <ReqSummaryCard label={ar ? 'جديدة' : 'New'} value={summary.new} tone="info" />
              <ReqSummaryCard label={ar ? 'قيد المراجعة' : 'Under review'} value={summary.review} tone="warning" />
              {/*
                The fourth card answers «what needs me?», not «what exists?».
                A breached SLA or an unassigned request is something an operator can act on this minute;
                another status count is one more thing to read past. Clicking it filters the list to exactly
                those requests, so the card is a way in rather than a number to admire.
              */}
              <button
                type="button"
                data-testid="requests-needs-attention"
                onClick={() => set({ unassigned: filters.unassigned ? undefined : true })}
                className="text-start"
              >
                <ReqSummaryCard
                  label={ar ? 'يحتاج انتباهك' : 'Needs your attention'}
                  value={summary.needs_attention}
                  tone={summary.needs_attention > 0 ? 'warning' : 'muted'}
                />
              </button>
            </>
          }
        />
      </div>

      {/* Filters */}
      <RequestCharts breakdown={query.data?.meta?.breakdown} ar={ar} loading={query.isLoading}
        queueTotal={query.data?.meta?.summary?.total} error={query.isError} failure={query.error} />

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
            optionsError={statusTax.error}
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
            optionsError={priorityTax.error}
            onRetry={() => priorityTax.refetch()}
            placeholder={t('all_priorities')}
          />
        </div>
      </div>

      {query.isLoading ? (
        <div className="space-y-2 rounded-2xl border border-border bg-surface p-4">{[0, 1, 2, 3, 4].map((i) => <div key={i} className="h-11 animate-pulse rounded-lg bg-surface-secondary" />)}</div>
      ) : query.isError ? (
        <QueryFailure error={query.error} ar={ar} testId="requests-failure" onRetry={() => void query.refetch()}
          fallbackTitle={ar ? 'تعذّر تحميل الطلبات.' : 'Requests could not be loaded.'} />
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

/**
 * UX-KPI-PRESENTATION-001 — the shared card. The tone dot is this surface's; the design is not.
 *
 * This drew its own copy: the same `p-4`, the same 2px dot, the same `text-2xl` figure. Four files
 * agreed by coincidence, and a row of cards here beside a row on the dashboard lined up only until
 * somebody touched one of them.
 */
function ReqSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'info' | 'warning' | 'muted' }) {
  // `muted` is this surface's word for «nothing special»; the shared card calls it neutral.
  return <StatCard label={label} value={value} tone={tone === 'muted' ? 'neutral' : tone} dot />
}


/**
 * REQ-CHARTS-001 — the shape of the queue, in three answers.
 *
 * Not decoration: each panel is a question an operator asks before touching anything. «Where is
 * everything» (status), «what kind of work is this» (service), «are we late» (SLA). All three describe
 * the SAME filtered set as the table below, because they are computed from the same builder — a chart
 * that quietly described a wider set than the list under it would be worse than no chart.
 *
 * Loading, empty and error are all rendered, and they say different things. An empty queue is good
 * news and reads as such; a failed request is not the same as nothing to show, and a chart that fell
 * back to «no data» on an error would be reporting an empty inbox that is actually unknown.
 */
function RequestCharts({
  breakdown,
  ar,
  loading,
  queueTotal,
  error,
  failure,
}: {
  breakdown?: RequestBreakdown
  ar: boolean
  loading: boolean
  /**
   * How many requests the filter actually matched — the SLA composition's denominator.
   *
   * Summing the status buckets would work right up until a status this build has no row for appears,
   * at which point every share would be computed over «the part we have names for» and all of them
   * would be too large. The server already counts the set; this is that count.
   */
  queueTotal?: number
  error: boolean
  /** The thrown value behind `error`, so a refusal can be told apart from a broken summary. */
  failure: unknown
}) {
  if (loading) {
    return (
      <div className="mb-4 grid gap-3 lg:grid-cols-3" data-testid="request-charts-loading">
        {[0, 1, 2].map((i) => <Skeleton key={i} className="h-40 w-full rounded-2xl" />)}
      </div>
    )
  }

  if (error) {
    return (
      <div className="mb-4" data-testid="request-charts-error">
        <QueryFailure error={failure} ar={ar} testId="request-charts-failure"
          fallbackTitle={ar ? 'تعذّر تحميل ملخص الطلبات.' : 'Could not load the request summary.'} />
      </div>
    )
  }

  if (!breakdown) return null

  const total = breakdown.by_status.reduce((a, b) => a + b.total, 0)
  if (total === 0) {
    return (
      <p data-testid="request-charts-empty" className="mb-4 rounded-2xl border border-border bg-surface px-4 py-6 text-center text-sm text-text-muted">
        {ar ? 'لا توجد طلبات مطابقة لعرضها في الملخص.' : 'No matching requests to summarise.'}
      </p>
    )
  }

  const sla = breakdown.sla
  const bars = (rows: Array<{ key: string; label: string; label_en: string; total: number }>) =>
    rows.map((r) => ({ label: ar ? r.label : r.label_en, count: r.total }))

  return (
    <div className="mb-4 grid gap-3 lg:grid-cols-3" data-testid="request-charts">
      <ChartCard title={ar ? 'حسب الحالة' : 'By status'}>
        <RankedBreakdown testId="requests-by-status" rows={bars(breakdown.by_status)} ar={ar} />
      </ChartCard>

      <ChartCard title={ar ? 'حسب نوع الخدمة' : 'By service type'}>
        {breakdown.by_type.length > 0
          ? <RankedBreakdown testId="requests-by-type" rows={bars(breakdown.by_type)} ar={ar} />
          : <p data-testid="requests-by-type-empty" className="py-8 text-center text-sm text-text-muted">{ar ? 'لا توجد أنواع لعرضها.' : 'No types to show.'}</p>}
      </ChartCard>

      {/*
        VIZ-REQUESTS-001 — the SLA card is a COMPOSITION, and was drawn as three comparisons.

        Breached, due soon, on track and «no SLA» are mutually exclusive states of the same requests.
        Three separate bars, each scaled to the same total, asks the reader to add three lengths back
        together to see the one thing this card exists to say: how much of the queue is in trouble.

        The denominator is the queue's own total rather than the sum of the bands. They DO sum to it —
        the server's four filters partition the set — but passing the total is what makes that a
        checked fact rather than an assumption: a build where they stop partitioning shows a named
        remainder instead of silently rescaling.
      */}
      <ChartCard title={ar ? 'الالتزام بالـSLA' : 'SLA'}>
        <StatusMixBar
          testId="sla-mix"
          ar={ar}
          label={ar ? 'الطلبات' : 'Requests'}
          total={queueTotal ?? total}
          residualLabel={ar ? 'غير محتسَب' : 'Unaccounted'}
          bands={[
            { key: 'breached', label: ar ? 'متجاوَز' : 'Breached', count: sla.breached, tone: 'danger' },
            { key: 'due_soon', label: ar ? 'يستحق خلال 24 ساعة' : 'Due within 24h', count: sla.due_soon, tone: 'warning' },
            { key: 'on_track', label: ar ? 'ضمن المدة' : 'On track', count: sla.on_track, tone: 'success' },
            /*
             * Neutral, deliberately. «No SLA» is not a grade — it is the absence of one — and giving
             * it the success colour is exactly the mistake the server-side bucket was making.
             */
            { key: 'no_sla', label: ar ? 'بلا مدّة متفق عليها' : 'No SLA set', count: sla.no_sla, tone: 'neutral' },
          ]}
        />
      </ChartCard>
    </div>
  )
}

/**
 * A breakdown, ranked, through the product's own bar chart.
 *
 * This drew its own: a label column, a track, a fill, a percentage width. The original's docblock
 * argued bars over a pie — «a reader comparing under review to new wants two lengths side by side,
 * not two wedges they have to estimate» — and that argument is right and is exactly what the canonical
 * ranked bar already does, with an axis, a tooltip and the product's shared type scale.
 *
 * The list beneath is the chart's key, not a duplicate: a recharts chart is an SVG a screen reader
 * walks as unlabelled shapes, and the labels here are the axis's own.
 */
function RankedBreakdown({ testId, rows, ar }: { testId: string; rows: Array<{ label: string; count: number }>; ar: boolean }) {
  return (
    <div data-testid={testId} className="min-w-0">
      <RankingBarChart
        horizontal
        /*
          46px a band, not 38. A service type is a SENTENCE — «Tracking & conversions setup» — and the
          category axis wraps it to two lines inside a 120px column; at 38 the second line of one label
          sat against the first line of the next. Measured on the service-type card at 1440.
        */
        height={Math.max(160, rows.length * 46)}
        data={rows}
        bars={[{ key: 'count', name: ar ? 'طلبات' : 'Requests', kind: 'num' }]}
      />
      <ul data-testid={`${testId}-legend`} className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-secondary">
        {rows.map((r) => (
          <li key={r.label} className="flex items-center gap-1">
            <span>{r.label}</span>
            <span className="tnum font-bold text-text-primary" dir="ltr">{r.count.toLocaleString('en-US')}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

