import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { Switch } from '@/components/ui/Switch'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import {
  getReportSections,
  listScopeTemplates,
  updateReportSections,
  updateTemplateSections,
  type ReportSectionsState,
  type ResolvedSectionRow,
  type SectionReason,
} from './api'

/**
 * REPORT-SECTION-MODEL-001 — the operator's section switches for one report, with a preview.
 *
 * One compact row per section, the optional breakdowns under their own heading, and beside them the
 * section set a client will actually get — which is the server's resolution, not this component's
 * guess: a switched-on section can still be absent because the platforms or the objective cannot
 * produce it, or because this period has no figures for it, and the preview says which.
 *
 * Saving is immediate and server-checked. An operator without `reports.manage` gets the server's
 * refusal shown under the switches; hiding the UI would not be the control, the API is.
 */

const REASON: Record<SectionReason, { ar: string; en: string }> = {
  disabled_by_operator: { ar: 'مُطفأ', en: 'Off' },
  unsupported_by_provider_or_objective: { ar: 'غير مدعوم للمنصات أو الهدف', en: 'Not supported for these platforms or objective' },
  data_unavailable: { ar: 'لا بيانات في هذه الفترة', en: 'No data in this period' },
}

export function ReportSectionControls({ projectId, reportId }: { projectId: string; reportId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const key = ['report-sections', projectId, reportId]
  const state = useQuery({ queryKey: key, queryFn: () => getReportSections(projectId, reportId) })
  const templates = useQuery({ queryKey: ['report-scope-templates', projectId], queryFn: () => listScopeTemplates(projectId), retry: false })
  const [error, setError] = useState<string | null>(null)
  const [templateId, setTemplateId] = useState('')

  const onSaved = (next: ReportSectionsState) => {
    setError(null)
    qc.setQueryData(key, next)
  }
  const onFailed = (e: unknown) => setError(toApiError(e).message)

  const toggle = useMutation({
    mutationFn: ({ section, on }: { section: string; on: boolean }) => updateReportSections(projectId, reportId, { sections: { [section]: on } }),
    onSuccess: onSaved,
    onError: onFailed,
  })
  const fromTemplate = useMutation({
    mutationFn: (id: string) => updateReportSections(projectId, reportId, { template_id: id }),
    onSuccess: onSaved,
    onError: onFailed,
  })
  const toTemplate = useMutation({
    mutationFn: (id: string) => updateTemplateSections(projectId, id, state.data?.effective ?? {}),
    onSuccess: () => setError(null),
    onError: onFailed,
  })

  if (state.isLoading) return <Skeleton className="h-64 w-full" />
  if (!state.data) {
    return <p className="text-sm text-text-secondary">{ar ? 'تعذّر تحميل أقسام التقرير.' : 'The report sections could not be loaded.'}</p>
  }

  const { resolved, effective, availability_judged: judged } = state.data
  const main = resolved.filter((r) => !r.breakdown)
  const breakdowns = resolved.filter((r) => r.breakdown)
  const busy = toggle.isPending || fromTemplate.isPending
  const title = (r: ResolvedSectionRow) => (ar ? r.title_ar : r.title_en)

  const row = (r: ResolvedSectionRow) => (
    <li key={r.key} data-testid={`section-row-${r.key}`} className="flex items-center justify-between gap-3 py-1.5">
      <span className="min-w-0">
        <span className="block truncate text-sm font-semibold text-text-primary">{title(r)}</span>
        {effective[r.key] && !r.visible && r.reason && r.reason !== 'disabled_by_operator' && (
          <span data-testid={`section-reason-${r.key}`} className="block text-[11px] text-text-muted">
            {ar ? REASON[r.reason].ar : REASON[r.reason].en}
          </span>
        )}
      </span>
      <Switch
        id={`section-toggle-${r.key}`}
        testId={`section-toggle-${r.key}`}
        checked={effective[r.key] ?? false}
        disabled={busy}
        onCheckedChange={(on) => toggle.mutate({ section: r.key, on })}
      />
    </li>
  )

  return (
    <div className="grid gap-4 md:grid-cols-[1fr_15rem]" data-testid="report-section-controls">
      <div>
        <ul className="divide-y divide-border">{main.map(row)}</ul>
        <p className="mt-4 mb-1 text-xs font-bold text-text-muted">{ar ? 'تفصيلات اختيارية' : 'Optional breakdowns'}</p>
        <ul className="divide-y divide-border">{breakdowns.map(row)}</ul>

        {(templates.data?.templates.length ?? 0) > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-3 text-xs">
            <select
              value={templateId}
              onChange={(e) => setTemplateId(e.target.value)}
              data-testid="section-template-select"
              className="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs"
            >
              <option value="">{ar ? 'قالب…' : 'Template…'}</option>
              {templates.data?.templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
            <button
              type="button"
              disabled={!templateId || busy}
              onClick={() => fromTemplate.mutate(templateId)}
              data-testid="section-template-apply"
              className="rounded-lg border border-border px-2 py-1.5 font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
            >
              {ar ? 'تطبيق أقسام القالب' : 'Use template sections'}
            </button>
            <button
              type="button"
              disabled={!templateId || toTemplate.isPending}
              onClick={() => toTemplate.mutate(templateId)}
              data-testid="section-template-save"
              className="rounded-lg border border-border px-2 py-1.5 font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
            >
              {ar ? 'حفظها في القالب' : 'Save to template'}
            </button>
          </div>
        )}

        {error && <p role="alert" data-testid="section-controls-error" className="mt-3 text-xs text-danger">{error}</p>}
      </div>

      <aside data-testid="section-preview" className="rounded-2xl border border-border bg-surface-secondary p-3">
        <p className="mb-2 text-xs font-bold text-text-primary">{ar ? 'ما سيراه العميل' : 'What the client sees'}</p>
        <ol className="space-y-1">
          {resolved.map((r) => (
            <li
              key={r.key}
              data-testid={`section-preview-${r.key}`}
              data-visible={r.visible ? 'true' : 'false'}
              className={`flex items-center gap-1.5 text-xs ${r.visible ? 'text-text-primary' : 'text-text-muted line-through'}`}
            >
              {r.visible ? <Eye size={12} aria-hidden /> : <EyeOff size={12} aria-hidden />}
              {title(r)}
            </li>
          ))}
        </ol>
        {!judged && (
          <p className="mt-2 text-[11px] text-text-muted">
            {ar ? 'توفّر البيانات يُفحص عند فتح العميل للرابط.' : 'Whether each section has data is checked when the client opens the link.'}
          </p>
        )}
      </aside>
    </div>
  )
}
