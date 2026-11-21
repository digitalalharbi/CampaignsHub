import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { History, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { useProjectCapabilities } from '@/features/projects/capabilities'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { listReportVersions, restoreReportVersion, type ReportVersionRow } from './versionsApi'

const useAr = () => useUi((u) => u.locale) === 'ar'

/** What the history needs to know about the report it belongs to. A `ReportRow` satisfies it. */
export interface HistoryReport {
  id: string
  name: string
  status?: string
  generated_at?: string | null
}

/**
 * REPORT-SNAPSHOT-PROTECT-001 — the copies of a report that a rebuild or a restore replaced, and putting one back.
 *
 * Self-contained on purpose: it reads the project and the reader's capabilities on it itself, so wherever the reports page keeps
 * its edits it is one line to mount. Two shapes of the same thing:
 *
 * - by default, an icon button for the report's row that opens the history in a dialog;
 * - `inline`, a labelled item that opens the history in place, for mounting inside a dialog that is already open
 *   (a dialog inside a dialog closes both on Escape, so the inline shape opens none).
 *
 * The confirmation is asked in the list itself, on the copy it is about, for the same reason.
 */
export function ReportVersionHistory({ report, inline = false }: { report: HistoryReport; inline?: boolean }) {
  const ar = useAr()
  const projectId = useProject((p) => p.currentProjectId)
  const [open, setOpen] = useState(false)
  const label = ar ? 'سجل النسخ' : 'Copy history'

  const body = open && (
    projectId
      ? <VersionList projectId={projectId} report={report} ar={ar} />
      : <p className="text-sm text-text-secondary">{ar ? 'اختر مشروعًا لعرض سجل النسخ.' : 'Select a project to see the copy history.'}</p>
  )

  if (inline) {
    return (
      <div className="min-w-0">
        <button
          type="button"
          data-testid={`report-history-open-${report.id}`}
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
          className="flex w-full items-center gap-2 rounded-xl border border-border px-3 py-2.5 text-start text-sm font-semibold text-text-primary hover:border-brand-400"
        >
          <History size={15} /> {ar ? 'سجل النسخ واستعادة نسخة سابقة' : 'Copy history and restoring an earlier copy'}
        </button>
        {open && <div className="mt-2 rounded-xl border border-border p-3">{body}</div>}
      </div>
    )
  }

  return (
    <>
      <button
        type="button"
        data-testid={`report-history-open-${report.id}`}
        onClick={() => setOpen(true)}
        title={label}
        aria-label={label}
        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover hover:text-text-primary"
      >
        <History size={15} />
      </button>
      {open && (
        <Modal
          open
          onClose={() => setOpen(false)}
          title={ar ? `سجل النسخ: ${report.name}` : `Copy history: ${report.name}`}
          footer={<Button variant="secondary" onClick={() => setOpen(false)}>{ar ? 'إغلاق' : 'Close'}</Button>}
        >
          {body}
        </Modal>
      )}
    </>
  )
}

function VersionList({ projectId, report, ar }: { projectId: string; report: HistoryReport; ar: boolean }) {
  const qc = useQueryClient()
  // The project's own capability, the one the restore route asks for. Unknown (still loading, or the question failed) offers the
  // button, as every menu here does: the server refuses a restore without it, and that refusal is shown below.
  const canRestore = useProjectCapabilities().can('reports.manage')
  const rebuilding = report.status === 'processing'
  const [asking, setAsking] = useState<ReportVersionRow | null>(null)
  const [restored, setRestored] = useState<string | null>(null)

  const list = useQuery({ queryKey: ['report-versions', projectId, report.id], queryFn: () => listReportVersions(projectId, report.id) })
  const restore = useMutation({
    mutationFn: (v: ReportVersionRow) => restoreReportVersion(projectId, report.id, v.id),
    onSuccess: (_data, v) => {
      setRestored(v.generated_at)
      setAsking(null)
      void qc.invalidateQueries({ queryKey: ['report-versions', projectId, report.id] })
      void qc.invalidateQueries({ queryKey: ['reports', projectId] })
    },
  })

  /** Why «استعادة» is disabled, or null when it is not. Said once under the list and on each button. */
  const blocked = !canRestore
    ? (ar ? 'الاستعادة تتطلب صلاحية إدارة التقارير. يمكنك عرض السجل فقط.' : 'Restoring needs the permission to manage reports. You can only view the history.')
    : rebuilding
      ? (ar ? 'التقرير قيد إعادة الإنشاء الآن. انتظر اكتمالها ثم استعد النسخة.' : 'This report is being rebuilt right now. Wait for it to finish, then restore the copy.')
      : null

  /** A restore puts a kept copy back as the current one and leaves it in the history too; that row is the current copy, not an older one. */
  const isCurrent = (v: ReportVersionRow) =>
    Boolean(v.generated_at && report.generated_at) && new Date(v.generated_at as string).getTime() === new Date(report.generated_at as string).getTime()

  const when = (iso: string | null) => <span className="tnum whitespace-nowrap" dir="ltr">{fmtDateTime(iso)}</span>

  return (
    <div className="min-w-0 space-y-3" data-testid="report-history">
      <p className="text-sm text-text-secondary">
        {ar
          ? 'كل نسخة محفوظة حلّت محلها نسخة أحدث تبقى هنا كما كانت. لا تُحذف نسخة ولا تُستبدل دون تأكيد.'
          : 'Every stored copy that a newer one replaced stays here as it was. No copy is deleted or replaced without a confirmation.'}
      </p>
      {report.generated_at && (
        <p className="text-xs text-text-muted" data-testid="report-history-current">
          {ar ? 'النسخة الحالية أُنشئت في ' : 'The current copy was generated on '}{when(report.generated_at)}
        </p>
      )}

      {restored !== null && (
        <p role="status" data-testid="report-version-restored" className="rounded-xl border border-border bg-[var(--positive-background)] p-2.5 text-sm text-success">
          {ar ? 'استُعيدت النسخة المُنشأة في ' : 'The copy generated on '}{when(restored)}
          {ar ? '، وهي الآن النسخة الحالية. النسخة التي كانت معروضة محفوظة في السجل.' : ' was restored and is now the current copy. The copy it replaced is kept in the history.'}
        </p>
      )}

      {list.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : list.isError ? (
        <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-danger">
          <span>{ar ? 'تعذّر تحميل سجل النسخ: ' : 'The copy history could not be loaded: '}{toApiError(list.error).message}</span>
          <Button size="sm" variant="secondary" data-testid="report-history-retry" onClick={() => void list.refetch()}>{ar ? 'إعادة المحاولة' : 'Try again'}</Button>
        </div>
      ) : (list.data ?? []).length === 0 ? (
        <p data-testid="report-history-empty" className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-text-muted">
          {ar ? 'لا توجد نسخ سابقة' : 'No earlier copies'}
        </p>
      ) : (
        <ul className="space-y-2">
          {(list.data ?? []).map((v) => (
            <li key={v.id} data-testid={`report-version-${v.id}`} className="min-w-0 rounded-xl border border-border p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0 text-sm">
                  <div className="font-semibold text-text-primary">{ar ? 'نسخة أُنشئت في ' : 'Copy generated on '}{when(v.generated_at)}</div>
                  <div className="text-xs text-text-muted">{ar ? 'حُفظت في السجل في ' : 'Kept in the history on '}{when(v.kept_at)}</div>
                </div>
                {isCurrent(v) ? (
                  <span data-testid={`report-version-current-${v.id}`} className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-semibold text-text-secondary">
                    {ar ? 'هي النسخة الحالية' : 'This is the current copy'}
                  </span>
                ) : (
                  <Button
                    size="sm"
                    variant="secondary"
                    data-testid={`report-version-restore-${v.id}`}
                    disabled={blocked !== null || restore.isPending}
                    title={blocked ?? undefined}
                    onClick={() => {
                      restore.reset()
                      setAsking(v)
                    }}
                  >
                    <RotateCcw size={14} /> {ar ? 'استعادة' : 'Restore'}
                  </Button>
                )}
              </div>

              {asking?.id === v.id && (
                <div data-testid="report-version-confirm" className="mt-3 space-y-2 rounded-lg bg-surface-secondary p-3">
                  <p className="text-sm text-text-primary">
                    {ar ? `استعادة النسخة المُنشأة في ` : 'Restore the copy generated on '}{when(v.generated_at)}
                    {ar ? ` لتصبح النسخة الحالية من «${report.name}»؟` : ` as the current copy of “${report.name}”?`}
                  </p>
                  <p className="text-sm text-text-secondary">
                    {ar
                      ? 'تبقى النسخة الحالية في السجل ويمكن إعادتها. كل من يفتح رابطًا ثابتًا لهذا التقرير سيرى النسخة المستعادة.'
                      : 'The current copy stays in the history and can be put back. Anyone opening a fixed link to this report will see the restored copy.'}
                  </p>
                  {restore.isError && (
                    <p role="alert" className="text-sm text-danger">
                      {ar ? 'لم تُستعد النسخة ولم يتغير شيء: ' : 'The copy was not restored and nothing changed: '}{toApiError(restore.error).message}
                    </p>
                  )}
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button size="sm" variant="secondary" data-testid="report-version-confirm-no" disabled={restore.isPending} onClick={() => setAsking(null)}>
                      {ar ? 'إلغاء' : 'Cancel'}
                    </Button>
                    <Button size="sm" data-testid="report-version-confirm-yes" loading={restore.isPending} onClick={() => restore.mutate(v)}>
                      {ar ? 'تأكيد الاستعادة' : 'Confirm restore'}
                    </Button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {blocked !== null && (list.data ?? []).length > 0 && (
        <p data-testid="report-version-restore-reason" className="text-xs text-text-muted">{blocked}</p>
      )}
    </div>
  )
}

/**
 * The sentence an editor shows where it saves, when that save rebuilds the report: the copy it replaces is not lost.
 *
 * Scope, section and recommendation saves are explicit edits, so they rebuild without a second question; this is what makes
 * that honest. One component so the three editors say it in the same words.
 */
export function KeptCopyNote({ className = '' }: { className?: string }) {
  const ar = useAr()
  return (
    <p data-testid="kept-copy-note" className={`text-[11px] text-text-muted ${className}`}>
      {ar
        ? 'إذا أعاد هذا الحفظ إنشاء التقرير، تبقى النسخة الحالية في «سجل النسخ» ويمكن استعادتها.'
        : 'If this save rebuilds the report, the current copy is kept in “Copy history” and can be restored.'}
    </p>
  )
}
