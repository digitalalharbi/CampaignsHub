import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { Switch } from '@/components/ui/Switch'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { providerLabel } from '@/features/campaigns/labels'
import { fmtDate } from '@/lib/datetime'
import {
  getReportSections,
  getShareSections,
  listShares,
  scopeOptions,
  updateShareSections,
  type BusinessStreamDefinition,
  type ShareSectionsState,
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
  /*
   * Coordinator decision — one list, two levels. The report's switches, or one link's: the same
   * registry rows, where a link can only switch OFF what the report shows and says «hidden by the
   * report» for the rest. An executive link with no drill-down is a link-level choice.
   */
  const [level, setLevel] = useState('')
  const shares = useQuery({ queryKey: ['report-shares', projectId, reportId], queryFn: () => listShares(projectId, reportId), retry: false })
  const linkKey = ['share-sections', projectId, reportId, level]
  const link = useQuery({ queryKey: linkKey, queryFn: () => getShareSections(projectId, reportId, level), enabled: level !== '' })
  const toggleLink = useMutation({
    mutationFn: ({ section, on }: { section: string; on: boolean }) => updateShareSections(projectId, reportId, level, { [section]: on }),
    onSuccess: (next: ShareSectionsState) => {
      setError(null)
      qc.setQueryData(linkKey, next)
    },
    onError: (e: unknown) => setError(toApiError(e).message),
  })

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

  const onLink = level !== ''
  const linkData = onLink ? link.data : undefined
  const { effective } = state.data
  const resolved = linkData?.resolved ?? state.data.resolved
  const judged = linkData?.availability_judged ?? state.data.availability_judged
  const linkState = Object.fromEntries((linkData?.sections ?? []).map((r) => [r.key, r.state]))
  // The rows are always the registry list; only the preview follows the chosen level.
  const main = state.data.resolved.filter((r) => !r.breakdown)
  const breakdowns = state.data.resolved.filter((r) => r.breakdown)
  const busy = toggle.isPending || fromTemplate.isPending
  const title = (r: ResolvedSectionRow) => (ar ? r.title_ar : r.title_en)

  const row = (r: ResolvedSectionRow) => (
    <li key={r.key} data-testid={`section-row-${r.key}`} className="flex items-center justify-between gap-3 py-1.5">
      <span className="min-w-0">
        <span className="block truncate text-sm font-semibold text-text-primary">{title(r)}</span>
        {!onLink && effective[r.key] && !r.visible && r.reason && r.reason !== 'disabled_by_operator' && (
          <span data-testid={`section-reason-${r.key}`} className="block text-[11px] text-text-muted">
            {ar ? REASON[r.reason].ar : REASON[r.reason].en}
          </span>
        )}
      </span>
      {onLink && linkState[r.key] === 'hidden_by_report' ? (
        <span data-testid={`section-link-hidden-by-report-${r.key}`} className="shrink-0 text-[11px] text-text-muted">
          {ar ? 'مخفي في التقرير' : 'Hidden by the report'}
        </span>
      ) : (
        <Switch
          id={`section-toggle-${r.key}`}
          testId={`section-toggle-${r.key}`}
          checked={onLink ? linkState[r.key] === 'shown' : (effective[r.key] ?? false)}
          disabled={onLink ? toggleLink.isPending || !linkData : busy}
          onCheckedChange={(on) => (onLink ? toggleLink.mutate({ section: r.key, on }) : toggle.mutate({ section: r.key, on }))}
        />
      )}
    </li>
  )

  return (
    <div className="grid gap-4 md:grid-cols-[1fr_15rem]" data-testid="report-section-controls">
      <div>
        {(shares.data?.length ?? 0) > 0 && (
          <label className="mb-2 flex items-center gap-2 text-xs text-text-secondary">
            {ar ? 'المستوى' : 'Level'}
            <select
              value={level}
              onChange={(e) => setLevel(e.target.value)}
              data-testid="section-level"
              className="rounded-lg border border-border bg-surface px-2 py-1 text-xs"
            >
              <option value="">{ar ? 'التقرير' : 'The report'}</option>
              {(shares.data ?? []).filter((sh) => sh.active).map((sh, i) => (
                <option key={sh.id} value={sh.id}>
                  {(ar ? 'رابط ' : 'Link ') + (i + 1) + (sh.created_at ? ` · ${fmtDate(sh.created_at)}` : '')}
                </option>
              ))}
            </select>
          </label>
        )}
        {onLink && (
          <p className="mb-1 text-[11px] text-text-muted">
            {ar ? 'يمكن للرابط إخفاء أقسام يعرضها التقرير فقط، لا إظهار ما يخفيه.' : 'A link can only hide sections the report shows — never show what it hides.'}
          </p>
        )}
        <ul className="divide-y divide-border">{main.map(row)}</ul>
        <p className="mt-4 mb-1 text-xs font-bold text-text-muted">{ar ? 'تفصيلات اختيارية' : 'Optional breakdowns'}</p>
        <ul className="divide-y divide-border">{breakdowns.map(row)}</ul>

        {!onLink && effective.advanced_segmentation && (
          <StreamsEditor projectId={projectId} reportId={reportId} saved={state.data.streams ?? []} ar={ar} onSaved={onSaved} onFailed={onFailed} />
        )}

        {!onLink && (templates.data?.templates.length ?? 0) > 0 && (
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

/** Neutral labels an operator can start from; any other label may be typed. */
const STREAM_PRESETS = ['التوعية في الفروع', 'المبيعات عبر الإنترنت', 'الاستحواذ', 'إعادة الاستهداف']

/**
 * REPORT-SECTION-STREAMS-001 — the operator names the business streams and maps platforms or ad
 * accounts to them. Campaigns are deliberately not offered: a stream divides the client's business,
 * it does not publish how the buying was arranged.
 */
function StreamsEditor({
  projectId,
  reportId,
  saved,
  ar,
  onSaved,
  onFailed,
}: {
  projectId: string
  reportId: string
  saved: BusinessStreamDefinition[]
  ar: boolean
  onSaved: (next: ReportSectionsState) => void
  onFailed: (e: unknown) => void
}) {
  const options = useQuery({ queryKey: ['report-scope-options', projectId], queryFn: () => scopeOptions(projectId), retry: false })
  const [draft, setDraft] = useState<BusinessStreamDefinition[] | null>(null)
  const streams = draft ?? saved
  const save = useMutation({
    mutationFn: () => updateReportSections(projectId, reportId, { streams: streams.filter((s) => s.label.trim() !== '') }),
    onSuccess: (next) => {
      setDraft(null)
      onSaved(next)
    },
    onError: onFailed,
  })

  const change = (i: number, next: Partial<BusinessStreamDefinition>) =>
    setDraft(streams.map((s, j) => (j === i ? { ...s, ...next } : s)))
  const flip = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value])

  return (
    <div data-testid="streams-editor" className="mt-3 rounded-xl border border-border p-3">
      <p className="text-xs font-bold text-text-primary">{ar ? 'مسارات العمل' : 'Business streams'}</p>
      <p className="mb-2 text-[11px] text-text-muted">
        {ar ? 'أسماء محايدة تحدّدها أنت، وتُربط بالمنصات أو الحسابات الإعلانية — لا بالحملات.' : 'Neutral names you choose, mapped to platforms or ad accounts — never campaigns.'}
      </p>
      <datalist id={`stream-presets-${reportId}`}>
        {STREAM_PRESETS.map((p) => <option key={p} value={p} />)}
      </datalist>
      <ul className="space-y-3">
        {streams.map((stream, i) => (
          <li key={i} data-testid={`stream-${i}`} className="rounded-lg bg-surface-secondary p-2">
            <div className="flex gap-2">
              <input
                value={stream.label}
                list={`stream-presets-${reportId}`}
                maxLength={60}
                onChange={(e) => change(i, { label: e.target.value })}
                placeholder={ar ? 'اسم المسار' : 'Stream name'}
                data-testid={`stream-label-${i}`}
                className="min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 py-1 text-xs"
              />
              <button type="button" onClick={() => setDraft(streams.filter((_, j) => j !== i))} className="text-xs text-text-muted hover:text-danger">
                {ar ? 'حذف' : 'Remove'}
              </button>
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
              {(options.data?.providers ?? []).map((p) => (
                <button
                  key={p}
                  type="button"
                  aria-pressed={stream.providers.includes(p)}
                  data-testid={`stream-${i}-provider-${p}`}
                  onClick={() => change(i, { providers: flip(stream.providers, p) })}
                  className={`rounded-md border px-2 py-0.5 text-[11px] ${stream.providers.includes(p) ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary'}`}
                >
                  {providerLabel(p, ar ? 'ar' : 'en')}
                </button>
              ))}
              {(options.data?.accounts ?? []).map((a) => (
                <button
                  key={a.id}
                  type="button"
                  aria-pressed={stream.account_ids.includes(a.id)}
                  onClick={() => change(i, { account_ids: flip(stream.account_ids, a.id) })}
                  className={`rounded-md border px-2 py-0.5 text-[11px] ${stream.account_ids.includes(a.id) ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary'}`}
                >
                  {a.name}
                </button>
              ))}
            </div>
          </li>
        ))}
      </ul>
      <div className="mt-2 flex gap-2">
        <button
          type="button"
          disabled={streams.length >= 8}
          onClick={() => setDraft([...streams, { label: '', providers: [], account_ids: [] }])}
          data-testid="stream-add"
          className="rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
        >
          {ar ? 'إضافة مسار' : 'Add stream'}
        </button>
        <button
          type="button"
          disabled={draft === null || save.isPending}
          onClick={() => save.mutate()}
          data-testid="stream-save"
          className="rounded-lg bg-brand-600 px-2 py-1 text-xs font-semibold text-white disabled:opacity-50"
        >
          {ar ? 'حفظ المسارات' : 'Save streams'}
        </button>
      </div>
    </div>
  )
}
