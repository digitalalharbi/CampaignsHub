import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CLIENT_PRESET_HIDDEN, METRIC_CHOICES, METRIC_GROUPS } from './metricVisibility'
import { reportMetricTemplate, saveReportMetricTemplate } from './api'
import { toApiError } from '@/lib/api/client'

/**
 * REPORT-KPI-VISIBILITY-001 — every metric a client can be shown is its own switch.
 *
 * Grouped the way a reader thinks about a result (delivery, results, value, costs) rather than as one flat column of thirteen
 * names. A TICKED metric is shown; unticking it removes it from every client surface — the page, the live link, the PDF and the
 * files — on the server, not just here. The cost per purchase starts unticked for a client report because it is the figure an
 * agency most often does not want a client dividing out for themselves.
 */
export function MetricVisibilityPicker({
  ar,
  hidden,
  onChange,
  projectId,
}: {
  ar: boolean
  /** The metrics NOT shown. */
  hidden: readonly string[]
  onChange: (next: string[]) => void
  /** When given, the client's template can be saved from here. */
  projectId?: string
}) {
  const off = new Set(hidden)
  const toggle = (key: string) => onChange(METRIC_CHOICES.map((c) => c.key).filter((k) => (k === key ? !off.has(k) : off.has(k))))

  return (
    <fieldset className="grid gap-3 rounded-2xl border border-border bg-surface-secondary p-3" data-testid="kpi-visibility">
      <legend className="px-1 text-xs font-bold text-text-primary">{ar ? 'المؤشرات التي يراها العميل' : 'Metrics the client sees'}</legend>
      <p className="text-[11px] leading-5 text-text-muted">
        {ar
          ? 'المؤشر المُلغى يختفي من الصفحة والرابط المباشر وملف PDF وملفات CSV وXLSX، ولا يظهر في أي رسم أو جدول أو مقارنة.'
          : 'An unticked metric disappears from the page, the live link, the PDF and the CSV and XLSX files — and from every chart, table and comparison.'}
      </p>
      <div className="grid gap-3 sm:grid-cols-2">
        {METRIC_GROUPS.map((group) => (
          <div key={group.key} className="grid content-start gap-1.5" data-testid={`kpi-group-${group.key}`}>
            <span className="text-[11px] font-bold uppercase tracking-wide text-text-muted">{ar ? group.ar : group.en}</span>
            {METRIC_CHOICES.filter((c) => c.group === group.key).map((c) => (
              <label key={c.key} className="flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-border bg-surface px-2.5 text-sm text-text-primary">
                <input
                  type="checkbox"
                  checked={!off.has(c.key)}
                  onChange={() => toggle(c.key)}
                  data-testid={`kpi-vis-${c.key}`}
                  className="h-4 w-4 accent-[var(--brand-600)]"
                />
                <span>{ar ? c.ar : c.en}</span>
              </label>
            ))}
          </div>
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => onChange([...CLIENT_PRESET_HIDDEN])}
          data-testid="kpi-vis-preset"
          className="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover"
        >
          {ar ? 'الإعداد الافتراضي للعميل' : 'Client default'}
        </button>
        <button
          type="button"
          onClick={() => onChange([])}
          data-testid="kpi-vis-all"
          className="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover"
        >
          {ar ? 'إظهار الكل' : 'Show all'}
        </button>
        {projectId !== undefined && <TemplateSave ar={ar} projectId={projectId} hidden={hidden} />}
      </div>
    </fieldset>
  )
}

function TemplateSave({ ar, projectId, hidden }: { ar: boolean; projectId: string; hidden: readonly string[] }) {
  const qc = useQueryClient()
  const save = useMutation({
    mutationFn: () => saveReportMetricTemplate(projectId, [...hidden]),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['report-metric-template', projectId] }),
  })

  return (
    <>
      <button
        type="button"
        onClick={() => save.mutate()}
        disabled={save.isPending}
        data-testid="kpi-vis-save-template"
        className="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-60"
      >
        {ar ? 'حفظ كقالب لهذا العميل' : 'Save as this client’s template'}
      </button>
      {save.isSuccess && <span className="text-[11px] text-success" role="status" data-testid="kpi-vis-saved">{ar ? 'تم حفظ القالب.' : 'Template saved.'}</span>}
      {save.isError && <span className="text-[11px] text-danger" role="alert">{toApiError(save.error).message}</span>}
    </>
  )
}

/** The metrics a NEW report starts hiding: the client's template, else the preset. Undefined while loading. */
export function useStartingHidden(projectId: string): string[] | undefined {
  const q = useQuery({ queryKey: ['report-metric-template', projectId], queryFn: () => reportMetricTemplate(projectId), staleTime: 30_000 })

  return q.data?.starting_point
}
