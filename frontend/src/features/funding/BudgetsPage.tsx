import { useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download, Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { PageIntro } from '@/components/ui/PageIntro'
import { EmptyState, ErrorState, NoPermission } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { AddBudgetDialog } from './AddBudgetDialog'
import { BudgetBoardRow, TotalsStrip } from './BudgetBoard'
import { BudgetDrawer } from './BudgetDrawer'
import { DeleteBudgetDialog, EditBudgetDialog } from './EditBudgetDialog'
import { UndoToast } from './UndoToast'
import { downloadBudgets, useBudget, useBudgets, useUndoBudget, type BudgetDetail } from './fundingApi'
import { L } from './labels'

type Lang = { ar: string; en: string }
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
  const { data, isLoading, error, refetch } = useBudgets(showDeleted)
  const rows = data?.rows ?? []
  const open = params.get('project')
  const canManage = data?.canManage === true

  const [adding, setAdding] = useState(false)
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

  return (
    <div className="mx-auto flex w-full max-w-[1400px] min-w-0 flex-col gap-2" data-testid="budgets-page">
      <div className="sticky top-0 z-10 rounded-[var(--radius-card)] border border-border bg-surface px-3 py-2">
        <PageIntro
          title={tx(showDeleted ? L.deleted : L.title)}
          testid="budgets-intro"
          meta={<TotalsStrip totals={data?.totals ?? null} ar={ar} />}
          actions={
            <>
              <ExportMenu onPick={exportFile} ar={ar} />
              {exportFailed && <span role="alert" className="text-xs font-semibold text-danger">{tx(L.exportFailed)}</span>}
              {(data?.deletedCount ?? 0) > 0 || showDeleted ? (
                <Button variant="ghost" size="sm" onClick={() => { setShowDeleted((v) => !v); choose(null) }} aria-pressed={showDeleted} data-testid="budgets-deleted-toggle">
                  {showDeleted ? tx(L.back) : `${tx(L.deleted)} · ${data?.deletedCount ?? 0}`}
                </Button>
              ) : null}
              {canManage && !showDeleted && (
                <Button size="sm" onClick={() => setAdding(true)} data-testid="budgets-add"><Plus className="h-4 w-4" aria-hidden /> {tx(L.add)}</Button>
              )}
            </>
          }
        />
      </div>

      {error ? (
        toApiError(error).status === 403 ? <NoPermission /> : <ErrorState error={error} onRetry={() => void refetch()} ar={ar} title={tx(L.title)} />
      ) : !isLoading && rows.length === 0 ? (
        <EmptyState title={tx(L.empty)} />
      ) : (
        <>
          <ul className="flex min-w-0 flex-col gap-1 overflow-y-auto lg:max-h-[calc(100dvh-17rem)]" data-testid="budgets-list">
            {rows.map((r) => (
              <li key={r.project_id} className="min-w-0">
                <BudgetBoardRow row={r} active={r.project_id === open} onOpen={() => choose(r.project_id)} ar={ar} />
              </li>
            ))}
          </ul>
        </>
      )}

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
        onClose={() => setAdding(false)}
        defaults={defaults}
        onAdded={(projectId, entryId) => { choose(projectId); if (entryId) announce(projectId, entryId, L.addDone) }}
      />
      {renewing && (
        <AddBudgetDialog
          open
          onClose={() => setRenewing(null)}
          fixed={{ clientId: renewing.client_workspace_id, clientName: renewing.client_name ?? '', projectId: renewing.project_id, projectName: renewing.project_name ?? '' }}
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
    </div>
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
      <div className="absolute end-0 top-full z-30 mt-1 flex w-44 flex-col rounded-xl border border-border bg-surface p-1 shadow-lg">
        {item('XLSX', 'budgets-export-xlsx', 'xlsx')}
        {item('CSV', 'budgets-export-csv', 'csv')}
        {item('PDF', 'budgets-export-pdf', 'pdf')}
        {item(`PDF · ${ar ? L.withHistory.ar : L.withHistory.en}`, 'budgets-export-pdf-history', 'pdf', true)}
      </div>
    </details>
  )
}
