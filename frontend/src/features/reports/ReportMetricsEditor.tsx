import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { MetricVisibilityPicker } from './MetricVisibilityPicker'
import { CLIENT_PRESET_HIDDEN } from './metricVisibility'
import { updateReportHiddenMetrics, type ReportRow } from './api'

/**
 * REPORT-KPI-VISIBILITY-001 — reopen a saved report and change which metrics a client sees.
 *
 * A report made before the choice existed carries no list and shows everything it always showed, so the editor starts from «show
 * all» for it rather than from the client preset: opening the dialog must not silently hide anything.
 */
export function ReportMetricsEditor({ projectId, report, onClose }: { projectId: string; report: ReportRow | undefined; onClose: () => void }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const stored = report?.config?.hidden_metrics
  const [hidden, setHidden] = useState<string[]>(stored ?? [])
  const save = useMutation({
    mutationFn: () => updateReportHiddenMetrics(projectId, report!.id, hidden),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['reports'] })
      onClose()
    },
  })

  return (
    <Modal open onClose={onClose} title={ar ? 'المؤشرات الظاهرة للعميل' : 'Metrics the client sees'} size="lg">
      <div className="grid gap-3" data-testid="metrics-editor">
        <MetricVisibilityPicker ar={ar} hidden={hidden} onChange={setHidden} projectId={projectId} />
        {stored === undefined && (
          <p className="text-[11px] text-text-muted">
            {ar ? `لم يُحدَّد هذا التقرير مؤشرات مخفية من قبل، لذلك يعرض كل المؤشرات. الإعداد الافتراضي للعميل يخفي: ${CLIENT_PRESET_HIDDEN.join('، ')}.` : `This report never chose, so it shows every metric. The client default hides: ${CLIENT_PRESET_HIDDEN.join(', ')}.`}
          </p>
        )}
        {save.isError && <p className="text-xs text-danger" role="alert">{toApiError(save.error).message}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-border px-3 py-1.5 text-sm">{ar ? 'إلغاء' : 'Cancel'}</button>
          <button
            type="button"
            onClick={() => save.mutate()}
            disabled={save.isPending || report === undefined}
            data-testid="metrics-save"
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {ar ? 'حفظ' : 'Save'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
