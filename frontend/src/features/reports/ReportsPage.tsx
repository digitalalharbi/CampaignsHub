import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, Download, FileText, LayoutGrid, Link2, Loader2, Plus, RefreshCw, Rows3, Send, Share2, Trash2 } from 'lucide-react'
import {
  createReport,
  createShare,
  deleteReport,
  downloadUrl,
  exportReport,
  getReport,
  listReports,
  listShares,
  regenerateReport,
  revokeShare,
  sendReport,
} from './api'
import type { CreatedShare, ReportDetail, ReportFormat, ReportRow, ShareRow } from './api'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { DateField } from '@/components/ui/DateField'
import { Skeleton } from '@/components/ui/States'
import { ErrorSummary, SelectField, type FieldError } from '@/components/forms'
import { optionLabel } from '@/components/forms/types'
import { toApiError } from '@/lib/api/client'
import { useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { SchedulesPanel } from './SchedulesPanel'
import { DemoBadge } from '@/features/analytics/components'
import { InteractiveReport } from './InteractiveReport'
import { AnnotationsPanel } from './AnnotationsPanel'
import { useProject } from '@/stores/project'
import { FilterGroup, ViewCustomiser } from '@/components/ui/ViewCustomiser'
import { useUi } from '@/stores/ui'

const STATUS_STYLE: Record<string, string> = {
  completed: 'bg-[var(--positive-background)] text-success',
  processing: 'bg-[var(--info-background)] text-info',
  failed: 'bg-[var(--negative-background)] text-danger',
  draft: 'bg-surface-secondary text-text-secondary',
}
const STATUS_LABEL: Record<string, { ar: string; en: string }> = {
  completed: { ar: 'مكتمل', en: 'Completed' },
  processing: { ar: 'قيد المعالجة', en: 'Processing' },
  failed: { ar: 'فشل', en: 'Failed' },
  draft: { ar: 'مسودة', en: 'Draft' },
}

const today = () => new Date().toISOString().slice(0, 10)
const daysAgo = (n: number) => {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

/**
 * The reader's language (APP-100).
 *
 * This page was written Arabic-only, so choosing English flipped `dir` and left every heading,
 * column, button title and dialog in Arabic. Each component below is its own function, so each
 * reads the locale rather than threading a prop through six levels.
 */
const useAr = () => useUi((u) => u.locale) === 'ar'

/** A report status in the reader's language; an unknown code reads as itself rather than blank. */
const statusLabel = (code: string, ar: boolean) =>
  STATUS_LABEL[code] ? (ar ? STATUS_LABEL[code].ar : STATUS_LABEL[code].en) : code

export function ReportsPage() {
  const ar = useAr()
  const { currentProjectId } = useProject()
  const locale = useUi((s) => s.locale)
  const qc = useQueryClient()
  // Report-type labels come from the taxonomy engine (report.type) — no hardcoded type array.
  const reportTypes = useTaxonomyOptions('report.type')
  const typeLabel = (value: string) => {
    const opt = reportTypes.options.find((o) => o.value === value)
    return opt ? optionLabel(opt, locale) : value
  }
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [builderOpen, setBuilderOpen] = useState(false)
  const [previewId, setPreviewId] = useState<string | null>(null)
  const [shareId, setShareId] = useState<string | null>(null)
  const [view, setView] = useState<'table' | 'cards'>('table')
  const [typeFilter, setTypeFilter] = useState('')
  // REPORT-SCHEDULING: saved documents vs the schedules that produce them.
  const [section, setSection] = useState<'documents' | 'schedules'>('documents')

  const params = new URLSearchParams()
  if (status) params.set('status', status)
  if (search) params.set('search', search)

  const list = useQuery({
    queryKey: ['reports', currentProjectId, status, search],
    queryFn: () => listReports(currentProjectId!, params.toString()),
    enabled: Boolean(currentProjectId),
    refetchInterval: (q) => (q.state.data?.reports.some((r) => r.status === 'processing') ? 2500 : false),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['reports', currentProjectId] })
  const regen = useMutation({ mutationFn: (id: string) => regenerateReport(currentProjectId!, id), onSuccess: invalidate })
  const del = useMutation({ mutationFn: (id: string) => deleteReport(currentProjectId!, id), onSuccess: invalidate })
  const exp = useMutation({
    mutationFn: ({ id, format }: { id: string; format: ReportFormat }) => exportReport(currentProjectId!, id, format),
    onSuccess: () => setTimeout(invalidate, 1500),
  })
  const send = useMutation({
    mutationFn: ({ id, emails }: { id: string; emails: string[] }) => sendReport(currentProjectId!, id, emails),
    onSuccess: invalidate,
  })

  const allRows = list.data?.reports ?? []
  const presentTypes = [...new Set(allRows.map((r) => r.type))]
  const rows = typeFilter ? allRows.filter((r) => r.type === typeFilter) : allRows

  const s = list.data?.summary

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'التقارير' : 'Reports'}</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">{ar ? 'مستندات محفوظة قابلة للإنشاء والتصدير والإرسال' : 'Saved documents you can generate, export and send'}</p>
        </div>
        <Button size="lg" onClick={() => setBuilderOpen(true)}>
          <Plus size={18} /> {ar ? 'تقرير جديد' : 'New report'}
        </Button>
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          [ar ? 'الإجمالي' : 'Total', s?.total],
          [ar ? 'مكتملة' : 'Completed', s?.completed],
          [ar ? 'قيد المعالجة' : 'Processing', s?.processing],
          [ar ? 'فاشلة' : 'Failed', s?.failed],
        ].map(([label, v]) => (
          <div key={label as string} className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
            <div className="text-sm text-text-secondary">{label as string}</div>
            <div className="tnum mt-1 text-2xl font-extrabold text-text-primary">{v ?? '—'}</div>
          </div>
        ))}
      </div>

      {/* Section switcher — the documents themselves vs the schedules that produce them. */}
      <div className="inline-flex rounded-xl border border-border bg-surface-secondary p-1">
        {([['documents', ar ? 'التقارير' : 'Reports'], ['schedules', ar ? 'الجدولة' : 'Schedules']] as const).map(([id, label]) => (
          <button
            key={id}
            data-testid={`reports-section-${id}`}
            aria-pressed={section === id}
            onClick={() => setSection(id)}
            className={`rounded-lg px-4 py-1.5 text-sm font-semibold transition-colors ${
              section === id ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {section === 'schedules' ? (
        <SchedulesPanel projectId={currentProjectId!} />
      ) : (
      <>
      {/* Toolbar — search + status segmented filter (matches the platform's other command pages). */}
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={ar ? 'ابحث في التقارير…' : 'Search reports…'}
          className="h-10 w-full rounded-xl border border-border bg-surface-secondary px-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 sm:max-w-xs"
        />
        <div className="flex flex-wrap items-center gap-2">
          {/*
            Status and type fold; search and the view switcher stay (SIMPLIFY-003).
            A reports page is opened to FETCH a report — most often the newest one — and it opened
            with a status row, a type row and a view switcher above the first filename.
          */}
          <ViewCustomiser
            id="reports"
            ar={ar}
            active={status !== '' || typeFilter !== ''}
            summary={
              [
                status === '' ? null : statusLabel(status, ar),
                typeFilter === '' ? null : typeLabel(typeFilter),
              ].filter(Boolean).join(' · ')
              || (ar ? 'كل التقارير' : 'All reports')
            }
            onClear={() => { setStatus(''); setTypeFilter('') }}
          >
            <FilterGroup label={ar ? 'الحالة' : 'Status'}>
              {[
                ['', ar ? 'الكل' : 'All'],
                ['completed', statusLabel('completed', ar)],
                ['processing', statusLabel('processing', ar)],
                ['failed', statusLabel('failed', ar)],
              ].map(([value, label]) => (
                <button
                  key={value || 'all'}
                  onClick={() => setStatus(value)}
                  className={`rounded-full px-3 py-1 text-xs font-semibold ${
                    status === value ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
                  }`}
                >
                  {label}
                </button>
              ))}
            </FilterGroup>
            {presentTypes.length > 1 && (
              <FilterGroup label={ar ? 'النوع' : 'Type'}>
                <button onClick={() => setTypeFilter('')}
                  className={`rounded-full px-3 py-1 text-xs font-semibold ${typeFilter === '' ? 'bg-text-primary text-surface' : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>
                  {ar ? 'الكل' : 'All'}
                </button>
                {presentTypes.map((tp) => (
                  <button key={tp} onClick={() => setTypeFilter(typeFilter === tp ? '' : tp)}
                    className={`rounded-full px-3 py-1 text-xs font-semibold ${typeFilter === tp ? 'bg-text-primary text-surface' : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>
                    {typeLabel(tp)}
                  </button>
                ))}
              </FilterGroup>
            )}
          </ViewCustomiser>
          <span className="mx-1 h-4 w-px bg-border" aria-hidden />
          {/* View switch — table for scanning, cards for browsing. */}
          <div className="flex overflow-hidden rounded-lg border border-border">
            <button onClick={() => setView('table')} aria-label={ar ? 'جدول' : 'Table'} title={ar ? 'جدول' : 'Table'}
              className={`flex items-center px-2.5 py-1.5 ${view === 'table' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><Rows3 size={14} /></button>
            <button onClick={() => setView('cards')} aria-label={ar ? 'بطاقات' : 'Cards'} title={ar ? 'بطاقات' : 'Cards'}
              className={`flex items-center px-2.5 py-1.5 ${view === 'cards' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><LayoutGrid size={14} /></button>
          </div>
        </div>
      </div>

      {/* List */}
      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {list.isLoading ? (
          <div className="space-y-2 p-4">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 p-12 text-center">
            <FileText size={28} className="text-text-muted" />
            <p className="text-sm text-text-secondary">
              {status || search || typeFilter
                ? (ar ? 'لا تقارير تطابق البحث أو الفلتر.' : 'No reports match the search or filter.')
                : (ar ? 'لا توجد تقارير بعد — أنشئ تقريرك الأول.' : 'No reports yet — create your first one.')}
            </p>
          </div>
        ) : view === 'cards' ? (
          <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
            {rows.map((r) => (
              <button key={r.id} onClick={() => setPreviewId(r.id)}
                className="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-start transition-colors hover:border-brand-400">
                <div className="flex items-start justify-between gap-2">
                  <span className="line-clamp-1 font-bold text-text-primary">{r.name}</span>
                  <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_STYLE[r.status] ?? ''}`}>{statusLabel(r.status, ar)}</span>
                </div>
                <span className="text-[11px] text-text-muted">{typeLabel(r.type)}</span>
                <span className="tnum text-[11px] text-text-tertiary" dir="ltr">{r.period.from ?? '…'} → {r.period.to ?? '…'}</span>
              </button>
            ))}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-border text-text-muted">
                  <th className="p-3 text-start font-semibold">{ar ? 'التقرير' : 'Report'}</th>
                  <th className="p-3 text-start font-semibold">{ar ? 'الفترة' : 'Period'}</th>
                  <th className="p-3 text-start font-semibold">{ar ? 'الحالة' : 'Status'}</th>
                  <th className="p-3 text-start font-semibold">{ar ? 'أُنشئ' : 'Created'}</th>
                  <th className="p-3 text-end font-semibold">{ar ? 'إجراءات' : 'Actions'}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <ReportRowView
                    key={r.id}
                    report={r}
                    typeLabel={typeLabel(r.type)}
                    onPreview={() => setPreviewId(r.id)}
                    onShare={() => setShareId(r.id)}
                    onRegenerate={() => regen.mutate(r.id)}
                    onExport={(f) => exp.mutate({ id: r.id, format: f })}
                    onSend={() => {
                      const emails = window.prompt(ar ? 'البريد الإلكتروني للمستلمين (مفصولة بفواصل):' : 'Recipient emails (comma separated):')
                      if (emails) send.mutate({ id: r.id, emails: emails.split(',').map((e) => e.trim()).filter(Boolean) })
                    }}
                    onDelete={() => window.confirm(ar ? `حذف «${r.name}»؟` : `Delete “${r.name}”?`) && del.mutate(r.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      </>
      )}

      {builderOpen && (
        <ReportBuilder
          projectId={currentProjectId!}
          onClose={() => setBuilderOpen(false)}
          onCreated={() => {
            setBuilderOpen(false)
            invalidate()
          }}
        />
      )}
      {previewId && <ReportPreview projectId={currentProjectId!} id={previewId} onClose={() => setPreviewId(null)} />}
      {shareId && <ShareManager projectId={currentProjectId!} reportId={shareId} onClose={() => setShareId(null)} />}
    </div>
  )
}

function ReportRowView({
  report,
  typeLabel,
  onPreview,
  onShare,
  onRegenerate,
  onExport,
  onSend,
  onDelete,
}: {
  report: ReportRow
  typeLabel: string
  onPreview: () => void
  onShare: () => void
  onRegenerate: () => void
  onExport: (f: ReportFormat) => void
  onSend: () => void
  onDelete: () => void
}) {
  const ar = useAr()
  return (
    <tr className="border-b border-border last:border-0 hover:bg-surface-secondary">
      <td className="p-3">
        <button onClick={onPreview} className="text-start font-semibold text-text-primary hover:text-brand-600">
          {report.name}
        </button>
        <div className="text-xs text-text-muted">{typeLabel}</div>
      </td>
      <td className="tnum p-3 text-text-secondary">
        {report.period.from} → {report.period.to}
      </td>
      <td className="p-3">
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[report.status]}`}>
          {report.status === 'processing' && <Loader2 size={12} className="animate-spin" />}
          {statusLabel(report.status, ar)}
        </span>
        {report.status === 'failed' && report.error && <div className="mt-1 max-w-[220px] truncate text-xs text-danger" title={report.error}>{report.error}</div>}
      </td>
      <td className="tnum p-3 text-text-muted">{report.created_at ? new Date(report.created_at).toLocaleDateString('en-GB') : '—'}</td>
      <td className="p-3">
        <div className="flex items-center justify-end gap-1">
          {report.status === 'completed' && (
            <>
              {(['pdf', 'xlsx', 'csv'] as ReportFormat[]).map((f) => {
                const ready = report.exports.find((e) => e.format === f && e.status === 'completed' && e.token)
                return ready ? (
                  <a
                    key={f}
                    href={downloadUrl(ready.token!)}
                    data-testid={`download-${f}-${report.id}`}
                    className="rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
                    title={ar ? `تنزيل ${f.toUpperCase()}` : `Download ${f.toUpperCase()}`}
                  >
                    {f.toUpperCase()}
                  </a>
                ) : (
                  <button
                    key={f}
                    onClick={() => onExport(f)}
                    data-testid={`export-${f}-${report.id}`}
                    className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-muted hover:bg-surface-hover"
                    title={ar ? `تصدير ${f.toUpperCase()}` : `Export ${f.toUpperCase()}`}
                  >
                    <Download size={12} /> {f.toUpperCase()}
                  </button>
                )
              })}
              <IconBtn title={ar ? 'مشاركة رابط آمن' : 'Share a secure link'} onClick={onShare}><Share2 size={15} /></IconBtn>
              <IconBtn title={ar ? 'إرسال' : 'Send'} onClick={onSend}><Send size={15} /></IconBtn>
            </>
          )}
          <IconBtn title={ar ? 'إعادة الإنشاء' : 'Regenerate'} onClick={onRegenerate}><RefreshCw size={15} /></IconBtn>
          <IconBtn title={ar ? 'حذف' : 'Delete'} onClick={onDelete} danger><Trash2 size={15} /></IconBtn>
        </div>
      </td>
    </tr>
  )
}

function IconBtn({ children, title, onClick, danger }: { children: React.ReactNode; title: string; onClick: () => void; danger?: boolean }) {
  return (
    <button
      onClick={onClick}
      title={title}
      className={`flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover ${danger ? 'hover:text-danger' : 'hover:text-text-primary'}`}
    >
      {children}
    </button>
  )
}

function ReportBuilder({ projectId, onClose, onCreated }: { projectId: string; onClose: () => void; onCreated: () => void }) {
  const ar = useAr()
  const [name, setName] = useState('')
  const [type, setType] = useState<string | null>('executive')
  const [audience, setAudience] = useState<string | null>('client')
  const [from, setFrom] = useState(daysAgo(29))
  const [to, setTo] = useState(today())
  // Type & audience are fed by the taxonomy engine (report.type / report.audience) — system definitions, so
  // the option keys are exactly the values ReportController::TYPES / the audience Rule::in already accept.
  const types = useTaxonomyOptions('report.type')
  const audiences = useTaxonomyOptions('report.audience')
  const create = useMutation({
    mutationFn: () =>
      createReport(projectId, {
        name: name || (ar ? 'تقرير' : 'Report'),
        type: type ?? 'executive',
        audience: audience ?? 'client',
        period_start: from,
        period_end: to,
        currency: 'SAR',
      }),
    onSuccess: onCreated,
  })
  const RB_FIELD_IDS: Record<string, string> = { name: 'rb-name', period_start: 'rb-from', period_end: 'rb-to' }
  const summaryErrors: FieldError[] = create.isError
    ? (() => { const api = toApiError(create.error); return api.errors ? Object.entries(api.errors).flatMap(([f, m]) => (m?.length ? [{ field: RB_FIELD_IDS[f] ?? f, message: m[0] }] : [])) : [] })()
    : []
  return (
    <Modal open onClose={onClose} title={ar ? 'منشئ التقرير' : 'Report builder'}>
      <div className="space-y-4">
        {summaryErrors.length > 0 && <ErrorSummary errors={summaryErrors} title={ar ? 'يرجى تصحيح الأخطاء التالية' : 'Please correct the following'} />}
        <Field label={ar ? 'اسم التقرير' : 'Report name'} htmlFor="rb-name">
          <input id="rb-name" value={name} onChange={(e) => setName(e.target.value)} placeholder={ar ? 'مثال: التقرير الشهري — يوليو' : 'e.g. Monthly report — July'} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
        </Field>
        <SelectField
          label={ar ? 'نوع التقرير' : 'Report type'}
          value={type}
          onChange={setType}
          options={types.options}
          loading={types.isPending}
          optionsError={types.isError ? (ar ? 'تعذّر تحميل الخيارات' : 'The options could not be loaded') : null}
          onRetry={() => types.refetch()}
          clearable={false}
        />
        <SelectField
          label={ar ? 'هذا التقرير موجّه إلى' : 'This report is for'}
          value={audience}
          onChange={setAudience}
          options={audiences.options}
          loading={audiences.isPending}
          optionsError={audiences.isError ? (ar ? 'تعذّر تحميل الخيارات' : 'The options could not be loaded') : null}
          onRetry={() => audiences.refetch()}
          clearable={false}
        />
        <div className="grid grid-cols-2 gap-3">
          <Field label={ar ? 'من' : 'From'} htmlFor="rb-from"><DateField id="rb-from" value={from} onChange={setFrom} /></Field>
          <Field label={ar ? 'إلى' : 'To'} htmlFor="rb-to"><DateField id="rb-to" value={to} onChange={setTo} /></Field>
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
          <Button loading={create.isPending} onClick={() => create.mutate()}>{ar ? 'إنشاء وتوليد' : 'Create and generate'}</Button>
        </div>
      </div>
    </Modal>
  )
}

function ReportPreview({ projectId, id, onClose }: { projectId: string; id: string; onClose: () => void }) {
  const ar = useAr()
  const q = useQuery({ queryKey: ['report', projectId, id], queryFn: () => getReport(projectId, id) })
  const r = q.data as ReportDetail | undefined
  const platforms = (r?.data?.platforms ?? []).map((p) => String(p.provider))
  return (
    <Modal open onClose={onClose} title={r?.name ?? (ar ? 'معاينة التقرير' : 'Report preview')} size="xl">
      {q.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : !r?.data ? (
        <p className="py-8 text-center text-sm text-text-secondary">{r?.status === 'failed'
            ? (ar ? `فشل: ${r.error}` : `Failed: ${r.error}`)
            : (ar ? 'التقرير قيد المعالجة…' : 'The report is still processing…')}</p>
      ) : (
        <div className="max-h-[76vh] space-y-4 overflow-y-auto">
          <AnnotationsPanel projectId={projectId} reportId={id} />
          <InteractiveReport
            data={r.data as never}
            meta={{ reportName: r.name, platforms, isDemo: r.is_demo, agencyName: 'CampaignsHub' }}
          />
        </div>
      )}
    </Modal>
  )
}

function ShareManager({ projectId, reportId, onClose }: { projectId: string; reportId: string; onClose: () => void }) {
  const ar = useAr()
  const qc = useQueryClient()
  const shares = useQuery({ queryKey: ['shares', projectId, reportId], queryFn: () => listShares(projectId, reportId) })
  const [opts, setOpts] = useState({ password: '', allow_download: true, hide_spend: false, hide_revenue: false, hide_campaign_names: false, watermark: false, expires_at: '' })
  const [created, setCreated] = useState<CreatedShare | null>(null)
  const [copied, setCopied] = useState(false)

  const create = useMutation({
    mutationFn: () =>
      createShare(projectId, reportId, {
        allow_download: opts.allow_download,
        hide_spend: opts.hide_spend,
        hide_revenue: opts.hide_revenue,
        hide_campaign_names: opts.hide_campaign_names,
        watermark: opts.watermark,
        ...(opts.password ? { password: opts.password } : {}),
        ...(opts.expires_at ? { expires_at: opts.expires_at } : {}),
      }),
    onSuccess: (s) => {
      setCreated(s)
      qc.invalidateQueries({ queryKey: ['shares', projectId, reportId] })
    },
  })
  const revoke = useMutation({
    mutationFn: (id: string) => revokeShare(projectId, reportId, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['shares', projectId, reportId] }),
  })

  const fullUrl = created ? `${window.location.origin}${created.url}` : ''
  const copy = () => {
    navigator.clipboard.writeText(fullUrl).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    })
  }

  const Toggle = ({ k, label }: { k: keyof typeof opts; label: string }) => (
    <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
      <span>{label}</span>
      <input type="checkbox" checked={opts[k] as boolean} onChange={(e) => setOpts((o) => ({ ...o, [k]: e.target.checked }))} className="h-4 w-4 accent-brand-600" />
    </label>
  )

  return (
    <Modal open onClose={onClose} title={ar ? 'روابط العميل الآمنة' : 'Secure client links'} size="lg">
      <div className="space-y-5">
        {created ? (
          <div className="rounded-2xl border border-brand-200 bg-[var(--brand-background)] p-4">
            <div className="mb-2 flex items-center gap-2 text-sm font-bold text-brand-700"><Link2 size={16} /> {ar ? 'تم إنشاء الرابط — انسخه الآن (يُعرض مرة واحدة)' : 'Link created — copy it now, it is shown once'}</div>
            <div className="flex items-center gap-2">
              <input readOnly value={fullUrl} className="tnum flex-1 rounded-lg border border-border bg-surface px-3 py-2 text-xs" />
              <Button size="sm" onClick={copy}>{copied ? <Check size={15} /> : <Copy size={15} />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}</Button>
            </div>
            <button onClick={() => setCreated(null)} className="mt-2 text-xs font-semibold text-brand-700 hover:underline">{ar ? 'إنشاء رابط آخر' : 'Create another link'}</button>
          </div>
        ) : (
          <div className="space-y-3">
            <div className="grid gap-2 sm:grid-cols-2">
              <Toggle k="allow_download" label={ar ? 'السماح بالتنزيل' : 'Allow download'} />
              <Toggle k="watermark" label={ar ? 'علامة مائية' : 'Watermark'} />
              <Toggle k="hide_spend" label={ar ? 'إخفاء الإنفاق' : 'Hide spend'} />
              <Toggle k="hide_revenue" label={ar ? 'إخفاء الإيرادات' : 'Hide revenue'} />
              <Toggle k="hide_campaign_names" label={ar ? 'إخفاء أسماء الحملات' : 'Hide campaign names'} />
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label={ar ? 'كلمة مرور (اختياري)' : 'Password (optional)'}><input type="text" value={opts.password} onChange={(e) => setOpts((o) => ({ ...o, password: e.target.value }))} placeholder={ar ? '4 أحرف فأكثر' : '4 characters or more'} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base" /></Field>
              <Field label={ar ? 'تاريخ الانتهاء (اختياري)' : 'Expiry date (optional)'}><DateField value={opts.expires_at} onChange={(v) => setOpts((o) => ({ ...o, expires_at: v }))} /></Field>
            </div>
            <Button loading={create.isPending} onClick={() => create.mutate()}><Share2 size={16} /> {ar ? 'إنشاء رابط آمن' : 'Create a secure link'}</Button>
          </div>
        )}

        <div>
          <h4 className="mb-2 text-sm font-bold">{ar ? 'الروابط الحالية' : 'Existing links'}</h4>
          {shares.isLoading ? (
            <Skeleton className="h-16 w-full" />
          ) : (shares.data?.length ?? 0) === 0 ? (
            <p className="text-sm text-text-muted">{ar ? 'لا روابط بعد.' : 'No links yet.'}</p>
          ) : (
            <div className="space-y-2">
              {shares.data!.map((s: ShareRow) => (
                <div key={s.id} className="flex items-center justify-between rounded-xl border border-border p-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${s.active ? 'bg-[var(--positive-background)] text-success' : 'bg-surface-secondary text-text-muted'}`}>{s.active ? (ar ? 'نشط' : 'Active') : s.revoked_at ? (ar ? 'مُلغى' : 'Revoked') : (ar ? 'منتهٍ' : 'Expired')}</span>
                    {s.password_protected && <span className="text-xs text-text-muted">🔒 {ar ? 'محمي' : 'Protected'}</span>}
                    {s.hide_spend && <span className="text-xs text-text-muted">{ar ? 'إنفاق مخفي' : 'Spend hidden'}</span>}
                    <span className="tnum text-xs text-text-muted">{s.view_count} {ar ? 'مشاهدة' : 'views'}</span>
                    {s.expires_at && <span className="tnum text-xs text-text-muted">{ar ? 'ينتهي' : 'expires'} {new Date(s.expires_at).toLocaleDateString('en-GB')}</span>}
                  </div>
                  {s.active && (
                    <button onClick={() => revoke.mutate(s.id)} className="text-xs font-semibold text-danger hover:underline">{ar ? 'إلغاء' : 'Revoke'}</button>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </Modal>
  )
}
