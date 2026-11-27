import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router-dom'
import { Download, Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { FitPage } from '@/components/fit/FitPage'
import { Pager } from '@/components/fit/Pager'
import { useFitRows } from '@/components/fit/useFitRows'
import { EmptyState, ErrorState, NoPermission } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useAgencyClient } from '@/stores/agencyClient'
import { AddBudgetDialog } from './AddBudgetDialog'
import { BoardHeader, BudgetBoardRow, ClassStrip, TotalsStrip } from './BudgetBoard'
import { FinancialDisclaimer } from './FinancialDisclaimer'
import { FxRateBar } from './FxRateBar'
import { RenewAllButton } from './RenewAllDialog'
import { BudgetDrawer } from './BudgetDrawer'
import { DeleteBudgetDialog, EditBudgetDialog } from './EditBudgetDialog'
import { UndoToast } from './UndoToast'
import { downloadBudgets, useBudget, useBudgets, useUndoBudget, type BudgetDetail } from './fundingApi'
import { L } from './labels'

type Lang = { ar: string; en: string }
/** One board row's height with its gap, in px — read with `useFitRows` so the count and the CSS cannot disagree. */
const ROW_HEIGHT = 53

type Toast = { projectId: string; entryId: string; message: Lang; undoable: boolean }

/**
 * «الميزانيات» — every budget, platform and project on ONE board.
 *
 * A slim line carries the title, the four totals and the actions; under it one dense row per project (period,
 * budget → spend → remaining as a bar, the platforms' parts, the state). Nothing expands in place: a row opens a
 * slide-over with the payments, the month and the history, where edit, renew, delete and undo live. The board
 * scrolls INSIDE itself only when there are more projects than the screen holds. Every figure comes from the server.
 */
export function BudgetsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const [params, setParams] = useSearchParams()
  const [showDeleted, setShowDeleted] = useState(false)
  // CLIENT-SCOPE-001 — the client chosen at the top of the agency portal narrows this board too (agency only).
  const inAgency = useLocation().pathname.startsWith('/agency')
  const scopedClient = useAgencyClient((s) => s.currentClientId)
  const { data, isLoading, error, refetch } = useBudgets(showDeleted, inAgency ? scopedClient : null)
  const allRows = data?.rows ?? []
  // BUDGET-LOGIC-001 — one class at a time; a class that no longer has a budget falls back to all.
  const [cls, setCls] = useState<(typeof allRows)[number]['status'] | null>(null)
  const filtered = cls === null ? allRows : allRows.filter((r) => r.status === cls)
  const rows = filtered.length > 0 ? filtered : allRows
  const open = params.get('project')
  const canManage = data?.canManage === true

  // ONE-BUDGET-001 — «أضف ميزانية» from a project with no budget lands here with `?add=1`; the names travel in router state.
  const location = useLocation()
  const [adding, setAdding] = useState(() => params.get('add') === '1')
  const handed = (location.state ?? null) as { clientName?: string; projectName?: string } | null
  const [renewing, setRenewing] = useState<BudgetDetail | null>(null)
  const [editing, setEditing] = useState<BudgetDetail | null>(null)
  const [deleting, setDeleting] = useState<BudgetDetail | null>(null)
  const [toast, setToast] = useState<Toast | null>(null)
  const [undoError, setUndoError] = useState<string | null>(null)
  const [exportFailed, setExportFailed] = useState(false)
  const undo = useUndoBudget()

  const choose = (id: string | null) => setParams((p) => { if (id) p.set('project', id); else p.delete('project'); return p }, { replace: true })
  const exportFile = (format: 'xlsx' | 'csv' | 'pdf', history = false) =>
    void downloadBudgets(format, format === 'pdf' ? { history: history ? '1' : undefined, lang: ar ? 'ar' : 'en' } : {}).then((ok) => setExportFailed(!ok))
  const announce = (projectId: string, entryId: string, message: Lang) => { setUndoError(null); setToast({ projectId, entryId, message, undoable: true }) }

  const runUndo = (projectId: string, entryId: string) => {
    setUndoError(null)
    undo.mutate({ projectId, entryId }, {
      onSuccess: () => setToast({ projectId, entryId, message: L.undone, undoable: false }),
      onError: () => setUndoError(tx(L.undoFailed)),
    })
  }

  // A detail the page already holds, for the renew dialog opened from a row-less place (the add button with a drawer open).
  const openDetail = useBudget(open)
  const defaults = useMemo(
    () => (open && openDetail.data ? { clientName: openDetail.data.client_name ?? '', projectName: openDetail.data.project_name ?? '' } : undefined),
    [open, openDetail.data],
  )

  /*
   * AGENCY-FIT-001 — the board shows the projects that FIT the screen, a page at a time, instead of scrolling inside itself.
   * A row is about 56px (two lines and its gap); the column header above it and the pager below it are outside the
   * measured box. A phone (or a window shorter than the lock) is not locked: every row is drawn and the page flows.
   */
  const fit = useFitRows({ rowHeight: ROW_HEIGHT, reserve: 0, min: 2, max: 40, fallback: rows.length || 1 })
  const perPage = fit.locked ? Math.max(1, fit.rows) : Math.max(1, rows.length)
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(rows.length / perPage))
  const current = Math.min(page, pages)
  useEffect(() => { setPage(1) }, [showDeleted, cls])
  const shown = rows.slice((current - 1) * perPage, current * perPage)

  return (
    <FitPage
      testid="budgets-page"
      introTestid="budgets-intro"
      slideOverAvoid
      title={tx(showDeleted ? L.deleted : L.title)}
      // One line: the four totals and the rate in use — the rest of the screen is for the board (eight projects fit 1280×720).
      kpis={<div className="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-xl border border-border bg-surface px-3 py-1.5"><TotalsStrip totals={data?.totals ?? null} ar={ar} /><FxRateBar /></div>}
      actions={
            <>
              <ExportMenu onPick={exportFile} ar={ar} />
              {exportFailed && <span role="alert" className="text-xs font-semibold text-danger">{tx(L.exportFailed)}</span>}
              {(data?.deletedCount ?? 0) > 0 || showDeleted ? (
                <Button variant="ghost" size="sm" onClick={() => { setShowDeleted((v) => !v); choose(null) }} aria-pressed={showDeleted} data-testid="budgets-deleted-toggle">
                  {showDeleted ? tx(L.back) : `${tx(L.deleted)} · ${data?.deletedCount ?? 0}`}
                </Button>
              ) : null}
              {canManage && !showDeleted && <RenewAllButton />}
              {canManage && !showDeleted && (
                <Button size="sm" onClick={() => setAdding(true)} data-testid="budgets-add"><Plus className="h-4 w-4" aria-hidden /> {tx(L.add)}</Button>
              )}
            </>
      }
    >

      {error ? (
        toApiError(error).status === 403 ? <NoPermission /> : <ErrorState error={error} onRetry={() => void refetch()} ar={ar} title={tx(L.title)} />
      ) : !isLoading && rows.length === 0 ? (
        <EmptyState title={tx(L.empty)} />
      ) : (
        <div className="flex min-h-0 flex-1 flex-col gap-1">
          <ClassStrip rows={allRows} value={cls} onChange={setCls} ar={ar} />
          <BoardHeader ar={ar} />
          <div ref={fit.ref as never} className="min-h-0 flex-1">
            <ul className="flex min-w-0 flex-col gap-0.5" data-testid="budgets-list">
              {shown.map((r) => (
                <li key={r.project_id} className="min-w-0">
                  <BudgetBoardRow row={r} active={r.project_id === open} onOpen={() => choose(r.project_id)} ar={ar} />
                </li>
              ))}
            </ul>
          </div>
          {fit.locked && pages > 1 && <Pager testid="budgets-pager" page={current} pages={pages} onPage={setPage} total={rows.length} ar={ar} compact />}
        </div>
      )}

      <FinancialDisclaimer className="shrink-0 px-1" />

      <BudgetDrawer
        projectId={open}
        onClose={() => choose(null)}
        ar={ar}
        canManage={canManage}
        onEdit={setEditing}
        onRenew={setRenewing}
        onDelete={setDeleting}
        onUndo={(b, entryId) => runUndo(b.project_id, entryId)}
        undoPending={undo.isPending}
      />

      <AddBudgetDialog
        open={adding}
        onClose={() => { setAdding(false); if (params.get('add')) setParams((p) => { p.delete('add'); return p }, { replace: true }) }}
        defaults={defaults ?? (handed?.projectName ? { clientName: handed.clientName ?? '', projectName: handed.projectName } : undefined)}
        onAdded={(projectId, entryId) => { choose(projectId); if (entryId) announce(projectId, entryId, L.addDone) }}
      />
      {renewing && (
        <AddBudgetDialog
          open
          onClose={() => setRenewing(null)}
          fixed={{ clientId: renewing.client_workspace_id, clientName: renewing.client_name ?? '', projectId: renewing.project_id, projectName: renewing.project_name ?? '',
            remaining: renewing.remaining, periodEnd: renewing.period_end, mode: renewing.payment_mode }}
          onAdded={(projectId, entryId) => { if (entryId) announce(projectId, entryId, L.addDone) }}
        />
      )}
      {editing && <EditBudgetDialog open onClose={() => setEditing(null)} budget={editing} onDone={(id) => announce(editing.project_id, id, L.editDone)} />}
      {deleting && (
        <DeleteBudgetDialog
          open
          onClose={() => setDeleting(null)}
          budget={deleting}
          onDone={(id) => { announce(deleting.project_id, id, L.delDone); choose(null) }}
        />
      )}

      {toast && (
        <UndoToast
          message={tx(toast.message)}
          undoLabel={tx(L.undo)}
          pending={undo.isPending}
          failed={undoError}
          onUndo={toast.undoable ? () => runUndo(toast.projectId, toast.entryId) : undefined}
          onDismiss={() => setToast(null)}
        />
      )}
    </FitPage>
  )
}

/** One «تصدير» button with the four files under it, so the header stays one slim line. */
function ExportMenu({ onPick, ar }: { onPick: (format: 'xlsx' | 'csv' | 'pdf', history?: boolean) => void; ar: boolean }) {
  const ref = useRef<HTMLDetailsElement>(null)
  const item = (label: string, testid: string, format: 'xlsx' | 'csv' | 'pdf', history = false) => (
    <button
      type="button"
      data-testid={testid}
      onClick={() => { onPick(format, history); if (ref.current) ref.current.open = false }}
      className="rounded-lg px-3 py-1.5 text-start text-sm font-semibold text-text-primary hover:bg-surface-hover"
    >
      {label}
    </button>
  )

  return (
    <details ref={ref} className="relative" data-testid="budgets-export-menu">
      <summary className="inline-flex h-9 cursor-pointer list-none items-center gap-2 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover" data-testid="budgets-export">
        <Download className="h-4 w-4" aria-hidden /> {ar ? L.export.ar : L.export.en}
      </summary>
      {/*
        Below `sm` the menu spans the screen less a 16px gutter (fixed, vertical position left at its place under the button):
        anchored to the button's end it opened past the phone's edge (at 360px it ran to 428px in Arabic).
      */}
      <div className="absolute end-0 top-full z-30 mt-1 flex w-44 flex-col rounded-xl border border-border bg-surface p-1 shadow-lg max-sm:fixed max-sm:inset-x-4 max-sm:top-auto max-sm:w-auto">
        {item('XLSX', 'budgets-export-xlsx', 'xlsx')}
        {item('CSV', 'budgets-export-csv', 'csv')}
        {item('PDF', 'budgets-export-pdf', 'pdf')}
        {item(`PDF · ${ar ? L.withHistory.ar : L.withHistory.en}`, 'budgets-export-pdf-history', 'pdf', true)}
      </div>
    </details>
  )
}
