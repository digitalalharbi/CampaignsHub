import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, FileSpreadsheet, FileText, FileType, FolderGit2, LayoutGrid, Link2, Rows3, Search } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { fmtDateTime } from '@/lib/datetime'
import { getFilesLibrary } from './api'

const COPY = {
  ar: {
    title: 'الملفات', subtitle: 'مكتبة موحّدة لكل ملفات مساحة العمل — مرفقات الطلبات وتصديرات التقارير ومجلدات Drive.',
    search_ph: 'ابحث بالاسم أو العميل…', all: 'الكل',
    sum_total: 'إجمالي الملفات', sum_requests: 'مرفقات الطلبات', sum_reports: 'تصديرات التقارير', sum_drive: 'مجلدات Drive',
    src_request: 'طلب', src_report: 'تقرير',
    vis_internal: 'داخلي', vis_client: 'مرئي للعميل',
    col_name: 'الملف', col_source: 'المصدر', col_client: 'العميل', col_related: 'مرتبط بـ', col_size: 'الحجم', col_uploaded: 'أُضيف', col_visibility: 'الظهور',
    download: 'تنزيل', drive_cta: 'إدارة مجلدات Drive', view_grid: 'شبكة', view_table: 'جدول',
    none: 'لا توجد ملفات بعد — تظهر هنا مرفقات الطلبات وتصديرات التقارير.', no_match: 'لا ملفات تطابق البحث أو الفلاتر.',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل الملفات.',
  },
  en: {
    title: 'Files', subtitle: 'One library for all workspace files — request attachments, report exports, and Drive folders.',
    search_ph: 'Search by name or client…', all: 'All',
    sum_total: 'Total files', sum_requests: 'Request attachments', sum_reports: 'Report exports', sum_drive: 'Drive folders',
    src_request: 'Request', src_report: 'Report',
    vis_internal: 'Internal', vis_client: 'Client-visible',
    col_name: 'File', col_source: 'Source', col_client: 'Client', col_related: 'Related to', col_size: 'Size', col_uploaded: 'Added', col_visibility: 'Visibility',
    download: 'Download', drive_cta: 'Manage Drive folders', view_grid: 'Grid', view_table: 'Table',
    none: 'No files yet — request attachments and report exports appear here.', no_match: 'No files match your search or filters.',
    loading: 'Loading…', error: 'Could not load files.',
  },
}
function fmtSize(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} MB`
}

export function FilesLibraryPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]

  const [term, setTerm] = useState('')
  const [source, setSource] = useState<'all' | string>('all')
  const [visibility, setVisibility] = useState<'all' | string>('all')
  const [view, setView] = useState<'grid' | 'table'>('grid')

  const q = useQuery({ queryKey: ['files', 'library'], queryFn: getFilesLibrary })
  const files = q.data?.files ?? []
  const driveLinks = q.data?.drive_links ?? 0

  const summary = {
    total: files.length,
    requests: files.filter((f) => f.source === 'request').length,
    reports: files.filter((f) => f.source === 'report').length,
  }

  const needle = term.trim().toLowerCase()
  const items = files.filter((f) => {
    if (source !== 'all' && f.source !== source) return false
    if (visibility !== 'all' && f.visibility !== visibility) return false
    if (needle && !`${f.name} ${f.client_name ?? ''} ${f.related.label ?? ''}`.toLowerCase().includes(needle)) return false
    return true
  })

  const srcLabel = (s: string) => (s === 'request' ? c.src_request : s === 'report' ? c.src_report : s)

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
        <Link to="/app/integrations/drive"
          className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600">
          <FolderGit2 size={15} /> {c.drive_cta}
        </Link>
      </header>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <FileSummaryCard label={c.sum_total} value={summary.total} tone="brand" />
        <FileSummaryCard label={c.sum_requests} value={summary.requests} tone="info" />
        <FileSummaryCard label={c.sum_reports} value={summary.reports} tone="success" />
        <FileSummaryCard label={c.sum_drive} value={driveLinks} tone="muted" />
      </div>

      {/* Search + filters */}
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="relative flex w-full items-center sm:max-w-xs">
          <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
          <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder={c.search_ph}
            className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none" />
        </label>
        <div className="flex flex-wrap items-center gap-1.5">
          {(['all', 'request', 'report'] as const).map((s) => (
            <Chip key={s} active={source === s} onClick={() => setSource(s)}>{s === 'all' ? `${c.col_source}: ${c.all}` : srcLabel(s)}</Chip>
          ))}
          <span className="mx-1 h-4 w-px bg-border" aria-hidden />
          {(['all', 'client_visible', 'internal'] as const).map((v) => (
            <Chip key={v} tone="dark" active={visibility === v} onClick={() => setVisibility(v)}>
              {v === 'all' ? `${c.col_visibility}: ${c.all}` : v === 'internal' ? c.vis_internal : c.vis_client}
            </Chip>
          ))}
          <span className="mx-1 h-4 w-px bg-border" aria-hidden />
          {/* View switch — grid for browsing, table for scanning (same language as Content). */}
          <div className="flex overflow-hidden rounded-lg border border-border">
            <button onClick={() => setView('grid')} aria-label={c.view_grid} title={c.view_grid}
              className={`flex items-center px-2.5 py-1.5 ${view === 'grid' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><LayoutGrid size={14} /></button>
            <button onClick={() => setView('table')} aria-label={c.view_table} title={c.view_table}
              className={`flex items-center px-2.5 py-1.5 ${view === 'table' ? 'bg-brand-500 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}><Rows3 size={14} /></button>
          </div>
        </div>
      </div>

      {/* Body */}
      {q.isLoading ? (
        <p className="rounded-xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : q.isError ? (
        <p className="rounded-xl border border-danger/30 bg-danger/5 p-10 text-center text-sm text-danger">{c.error}</p>
      ) : items.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-12 text-center text-text-secondary">
          <FileText size={24} /><span className="text-sm">{files.length === 0 ? c.none : c.no_match}</span>
        </div>
      ) : view === 'grid' ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {items.map((f) => (
            <div key={`${f.source}-${f.id}`} className="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface">
              {/* Type-honest tile: we never fabricate a thumbnail for a private file. */}
              <div className="flex aspect-video items-center justify-center bg-surface-hover text-text-muted">
                <FileIcon name={f.name} type={f.type} />
              </div>
              <div className="flex flex-col gap-1 p-3">
                <span className="line-clamp-1 text-sm font-semibold text-text-primary" title={f.name}>{f.name}</span>
                <span className="line-clamp-1 text-[11px] text-text-tertiary">{f.client_name ?? '—'} · {f.related.label ?? srcLabel(f.source)}</span>
                <div className="mt-1 flex items-center justify-between">
                  <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${f.visibility === 'internal' ? 'bg-surface-hover text-text-secondary' : 'bg-info/15 text-info'}`}>
                    {f.visibility === 'internal' ? c.vis_internal : c.vis_client}
                  </span>
                  <span className="flex items-center gap-2">
                    <span className="tnum text-[11px] text-text-tertiary" dir="ltr">{fmtSize(f.size)}</span>
                    {f.download_url && (
                      <a href={f.download_url} target="_blank" rel="noopener noreferrer" title={c.download} aria-label={c.download}
                        className="text-text-secondary hover:text-brand-600"><Download size={13} /></a>
                    )}
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-border">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-surface-hover text-xs text-text-secondary">
              <tr>
                <th className="p-3 text-start font-semibold">{c.col_name}</th>
                <th className="p-3 text-start font-semibold">{c.col_source}</th>
                <th className="p-3 text-start font-semibold">{c.col_client}</th>
                <th className="p-3 text-start font-semibold">{c.col_related}</th>
                <th className="p-3 text-start font-semibold">{c.col_visibility}</th>
                <th className="p-3 text-end font-semibold">{c.col_size}</th>
                <th className="p-3 text-start font-semibold">{c.col_uploaded}</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {items.map((f) => (
                <tr key={`${f.source}-${f.id}`} className="border-t border-border hover:bg-surface-hover">
                  <td className="max-w-[260px] truncate p-3 font-semibold text-text-primary" title={f.name}>{f.name}</td>
                  <td className="p-3"><span className="rounded-full bg-surface-hover px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{srcLabel(f.source)}</span></td>
                  <td className="p-3 text-text-secondary">{f.client_name ?? '—'}</td>
                  <td className="p-3 text-text-secondary">{f.related.label ?? '—'}</td>
                  <td className="p-3">
                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${f.visibility === 'internal' ? 'bg-surface-hover text-text-secondary' : 'bg-info/15 text-info'}`}>
                      {f.visibility === 'internal' ? c.vis_internal : c.vis_client}
                    </span>
                  </td>
                  <td className="tnum p-3 text-end text-text-secondary" dir="ltr">{fmtSize(f.size)}</td>
                  <td className="tnum p-3 text-text-tertiary" dir="ltr">{fmtDateTime(f.uploaded_at)}</td>
                  <td className="p-3 text-end">
                    {f.download_url ? (
                      <a href={f.download_url} target="_blank" rel="noopener noreferrer" title={c.download} aria-label={c.download}
                        className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600">
                        <Download size={13} />
                      </a>
                    ) : <Link2 size={13} className="text-text-muted" aria-hidden />}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function Chip({ active, onClick, children, tone }: { active: boolean; onClick: () => void; children: React.ReactNode; tone?: 'dark' }) {
  const on = tone === 'dark' ? 'bg-text-primary text-surface' : 'bg-brand-500 text-white'
  return <button onClick={onClick} className={`rounded-full px-3 py-1 text-xs font-semibold ${active ? on : 'bg-surface-hover text-text-secondary hover:text-text-primary'}`}>{children}</button>
}

function FileSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'info' | 'success' | 'muted' }) {
  const dot: Record<typeof tone, string> = { brand: 'bg-brand-500', info: 'bg-info', success: 'bg-success', muted: 'bg-text-muted' }
  return (
    <div className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${dot[tone]}`} aria-hidden />
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
      </div>
      <span className="tnum text-2xl font-extrabold text-text-primary" dir="ltr">{value}</span>
    </div>
  )
}

/** An honest type icon derived from the file's extension/mime — never a fabricated preview image. */
function FileIcon({ name, type }: { name: string; type: string | null }) {
  const ext = (name.split('.').pop() ?? '').toLowerCase()
  const t = `${type ?? ''} ${ext}`.toLowerCase()
  if (/pdf/.test(t)) return <FileType size={26} />
  if (/csv|xls|sheet/.test(t)) return <FileSpreadsheet size={26} />
  return <FileText size={26} />
}
