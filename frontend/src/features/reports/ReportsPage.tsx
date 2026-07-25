import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileText, Loader2, Plus, RefreshCw, Send, Trash2 } from 'lucide-react'
import {
  REPORT_TYPES,
  createReport,
  deleteReport,
  downloadUrl,
  exportReport,
  getReport,
  listReports,
  regenerateReport,
  sendReport,
} from './api'
import type { ReportDetail, ReportFormat, ReportRow } from './api'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { DemoBadge } from '@/features/analytics/components'
import { money, num, ratio } from '@/features/analytics/format'
import { useProject } from '@/stores/project'

const STATUS_STYLE: Record<string, string> = {
  completed: 'bg-[var(--positive-background)] text-success',
  processing: 'bg-[var(--info-background)] text-info',
  failed: 'bg-[var(--negative-background)] text-danger',
  draft: 'bg-surface-secondary text-text-secondary',
}
const STATUS_LABEL: Record<string, string> = { completed: 'مكتمل', processing: 'قيد المعالجة', failed: 'فشل', draft: 'مسودة' }

const today = () => new Date().toISOString().slice(0, 10)
const daysAgo = (n: number) => {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

export function ReportsPage() {
  const { currentProjectId } = useProject()
  const qc = useQueryClient()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [builderOpen, setBuilderOpen] = useState(false)
  const [previewId, setPreviewId] = useState<string | null>(null)

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

  const s = list.data?.summary

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">التقارير</h1>
            <DemoBadge />
          </div>
          <p className="mt-1 text-sm text-text-secondary">مستندات محفوظة قابلة للإنشاء والتصدير والإرسال</p>
        </div>
        <Button size="lg" onClick={() => setBuilderOpen(true)}>
          <Plus size={18} /> تقرير جديد
        </Button>
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          ['الإجمالي', s?.total],
          ['مكتملة', s?.completed],
          ['قيد المعالجة', s?.processing],
          ['فاشلة', s?.failed],
        ].map(([label, v]) => (
          <div key={label as string} className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
            <div className="text-sm text-text-secondary">{label as string}</div>
            <div className="tnum mt-1 text-2xl font-extrabold text-text-primary">{v ?? '—'}</div>
          </div>
        ))}
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="ابحث في التقارير…"
          className="h-10 flex-1 rounded-xl border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="h-10 rounded-xl border border-border bg-surface px-3 text-sm font-semibold"
        >
          <option value="">كل الحالات</option>
          <option value="completed">مكتمل</option>
          <option value="processing">قيد المعالجة</option>
          <option value="failed">فشل</option>
        </select>
      </div>

      {/* List */}
      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {list.isLoading ? (
          <div className="space-y-2 p-4">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : (list.data?.reports.length ?? 0) === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 p-12 text-center">
            <FileText size={28} className="text-text-muted" />
            <p className="text-sm text-text-secondary">لا توجد تقارير بعد — أنشئ تقريرك الأول.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-border text-text-muted">
                  <th className="p-3 text-start font-semibold">التقرير</th>
                  <th className="p-3 text-start font-semibold">الفترة</th>
                  <th className="p-3 text-start font-semibold">الحالة</th>
                  <th className="p-3 text-start font-semibold">أُنشئ</th>
                  <th className="p-3 text-end font-semibold">إجراءات</th>
                </tr>
              </thead>
              <tbody>
                {list.data?.reports.map((r) => (
                  <ReportRowView
                    key={r.id}
                    report={r}
                    onPreview={() => setPreviewId(r.id)}
                    onRegenerate={() => regen.mutate(r.id)}
                    onExport={(f) => exp.mutate({ id: r.id, format: f })}
                    onSend={() => {
                      const emails = window.prompt('البريد الإلكتروني للمستلمين (مفصولة بفواصل):')
                      if (emails) send.mutate({ id: r.id, emails: emails.split(',').map((e) => e.trim()).filter(Boolean) })
                    }}
                    onDelete={() => window.confirm(`حذف «${r.name}»؟`) && del.mutate(r.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

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
    </div>
  )
}

function ReportRowView({
  report,
  onPreview,
  onRegenerate,
  onExport,
  onSend,
  onDelete,
}: {
  report: ReportRow
  onPreview: () => void
  onRegenerate: () => void
  onExport: (f: ReportFormat) => void
  onSend: () => void
  onDelete: () => void
}) {
  const typeLabel = REPORT_TYPES.find((t) => t.value === report.type)?.label ?? report.type
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
          {STATUS_LABEL[report.status] ?? report.status}
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
                    className="rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
                    title={`تنزيل ${f.toUpperCase()}`}
                  >
                    {f.toUpperCase()}
                  </a>
                ) : (
                  <button
                    key={f}
                    onClick={() => onExport(f)}
                    className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-muted hover:bg-surface-hover"
                    title={`تصدير ${f.toUpperCase()}`}
                  >
                    <Download size={12} /> {f.toUpperCase()}
                  </button>
                )
              })}
              <IconBtn title="إرسال" onClick={onSend}><Send size={15} /></IconBtn>
            </>
          )}
          <IconBtn title="إعادة الإنشاء" onClick={onRegenerate}><RefreshCw size={15} /></IconBtn>
          <IconBtn title="حذف" onClick={onDelete} danger><Trash2 size={15} /></IconBtn>
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
  const [name, setName] = useState('')
  const [type, setType] = useState('executive')
  const [from, setFrom] = useState(daysAgo(29))
  const [to, setTo] = useState(today())
  const create = useMutation({
    mutationFn: () => createReport(projectId, { name: name || 'تقرير', type, period_start: from, period_end: to, currency: 'SAR' }),
    onSuccess: onCreated,
  })
  return (
    <Modal open onClose={onClose} title="منشئ التقرير">
      <div className="space-y-4">
        <Field label="اسم التقرير">
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="مثال: التقرير الشهري — يوليو" className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
        </Field>
        <Field label="نوع التقرير">
          <select value={type} onChange={(e) => setType(e.target.value)} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base font-semibold">
            {REPORT_TYPES.map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="من"><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base" /></Field>
          <Field label="إلى"><input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base" /></Field>
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
          <Button loading={create.isPending} onClick={() => create.mutate()}>إنشاء وتوليد</Button>
        </div>
      </div>
    </Modal>
  )
}

function ReportPreview({ projectId, id, onClose }: { projectId: string; id: string; onClose: () => void }) {
  const q = useQuery({ queryKey: ['report', projectId, id], queryFn: () => getReport(projectId, id) })
  const r = q.data as ReportDetail | undefined
  const k = r?.data?.kpis
  return (
    <Modal open onClose={onClose} title={r?.name ?? 'معاينة التقرير'}>
      {q.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : !r?.data ? (
        <p className="py-8 text-center text-sm text-text-secondary">{r?.status === 'failed' ? `فشل: ${r.error}` : 'التقرير قيد المعالجة…'}</p>
      ) : (
        <div className="max-h-[70vh] space-y-5 overflow-y-auto">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              ['الإنفاق', money(Number(k?.spend))],
              ['الإيرادات', money(Number(k?.revenue))],
              ['ROAS', ratio(k?.roas ?? null)],
              ['النتائج', num(Number(k?.conversions))],
            ].map(([l, v]) => (
              <div key={l} className="rounded-xl border border-border bg-surface-secondary p-3">
                <div className="text-xs text-text-muted">{l}</div>
                <div className="tnum text-lg font-bold">{v}</div>
              </div>
            ))}
          </div>
          {(r.data.summary?.length ?? 0) > 0 && (
            <div>
              <h4 className="mb-1 text-sm font-bold">الملخص التنفيذي</h4>
              <ul className="list-disc space-y-1 ps-5 text-sm text-text-secondary">
                {r.data.summary.map((line, i) => <li key={i}>{line}</li>)}
              </ul>
            </div>
          )}
          <div>
            <h4 className="mb-1 text-sm font-bold">أداء المنصات</h4>
            <table className="w-full text-sm">
              <thead><tr className="border-b border-border text-text-muted"><th className="py-1.5 text-start">المنصة</th><th className="py-1.5 text-end">الإنفاق</th><th className="py-1.5 text-end">ROAS</th><th className="py-1.5 text-end">CPA</th></tr></thead>
              <tbody>
                {r.data.platforms.map((p, i) => (
                  <tr key={i} className="border-b border-border last:border-0">
                    <td className="py-1.5 font-semibold">{String(p.provider)}</td>
                    <td className="tnum py-1.5 text-end">{money(Number(p.spend))}</td>
                    <td className="tnum py-1.5 text-end">{ratio(p.roas as number)}</td>
                    <td className="tnum py-1.5 text-end">{money(p.cpa as number)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Modal>
  )
}
