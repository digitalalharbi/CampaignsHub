import { useQuery } from '@tanstack/react-query'
import { ExternalLink, FolderOpen } from 'lucide-react'
import { fileModifiedAt, formatDate, listClientFiles, type PortalFile } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'الملفات', subtitle: 'كل الملفات المشتركة عبر طلباتك ومساحاتك في مكان واحد.',
    none: 'لا توجد ملفات بعد.', error: 'تعذّر تحميل الملفات.',
    name: 'الملف', type: 'النوع', source: 'المصدر', modified: 'آخر تحديث', open: 'فتح',
    src_request: 'طلب', src_drive: 'المساحة', unknown_type: 'غير محدّد',
  },
  en: {
    title: 'Files', subtitle: 'Every file shared across your requests and client spaces, in one place.',
    none: 'No files yet.', error: 'Could not load files.',
    name: 'File', type: 'Type', source: 'Source', modified: 'Modified', open: 'Open',
    src_request: 'Request', src_drive: 'Drive', unknown_type: 'Unknown',
  },
}

/** Compact, human type from a mime string (e.g. "application/pdf" → "PDF", "image/png" → "PNG"). */
function typeLabel(mime: string | null, fallback: string): string {
  if (!mime) return fallback
  const sub = mime.split('/')[1] ?? mime
  return sub.split(/[.+]/).pop()!.toUpperCase()
}

export function ClientFilesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'files'], queryFn: listClientFiles, retry: false })
  usePortalGuard(q.isError, q.error)

  const rows = q.data ?? []

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {q.isLoading ? (
        <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <div key={i} className="h-14 animate-pulse rounded-xl bg-surface-secondary" />)}</div>
      ) : q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><FolderOpen size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <>
        {/*
          CP-MOBILE-001 — a card per file on a phone. The table needs 560px and on a 375px screen became
          a sideways scroll with the open button off the edge; below `sm` each file is a card with its
          name, type, source, date and the open button, and the table returns from `sm` up.
        */}
        <ul data-testid="portal-files-cards" className="flex flex-col gap-2 sm:hidden">
          {rows.map((f) => <FileCard key={`${f.source}-${f.id}`} file={f} t={t} />)}
        </ul>
        <div className="hidden overflow-x-auto rounded-2xl border border-border bg-surface sm:block">
          <table className="w-full min-w-[560px] text-sm">
            <thead className="bg-surface-secondary text-xs text-text-secondary">
              <tr>
                <th className="p-3 text-start font-semibold">{t.name}</th>
                <th className="p-3 text-start font-semibold">{t.type}</th>
                <th className="p-3 text-start font-semibold">{t.source}</th>
                <th className="p-3 text-start font-semibold">{t.modified}</th>
                <th className="p-3 text-end font-semibold" />
              </tr>
            </thead>
            <tbody>
              {rows.map((f) => <FileRow key={`${f.source}-${f.id}`} file={f} t={t} />)}
            </tbody>
          </table>
        </div>
        </>
      )}
    </PortalShell>
  )
}

function FileCard({ file, t }: { file: PortalFile; t: typeof COPY.ar }) {
  const isDrive = file.source === 'drive'
  return (
    <li data-testid={`portal-file-card-${file.id}`} className="flex min-w-0 items-start justify-between gap-3 rounded-xl border border-border bg-surface p-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-text-primary" title={file.name}>{file.name}</p>
        <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-muted">
          <span className="rounded-md bg-surface-secondary px-1.5 py-0.5 font-semibold text-text-secondary">{typeLabel(file.mime, t.unknown_type)}</span>
          <span>{isDrive ? t.src_drive : t.src_request}</span>
          <span className="tnum">{formatDate(fileModifiedAt(file))}</span>
        </p>
        {file.request_reference && <p className="mt-1 font-mono text-[11px] text-text-muted"><bdi>{file.request_reference}</bdi></p>}
      </div>
      {file.web_view_link && (
        <a href={file.web_view_link} target="_blank" rel="noopener noreferrer" className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-brand-600">
          {t.open} <ExternalLink size={13} />
        </a>
      )}
    </li>
  )
}

function FileRow({ file, t }: { file: PortalFile; t: typeof COPY.ar }) {
  const isDrive = file.source === 'drive'
  return (
    <tr className="border-t border-border">
      <td className="max-w-[240px] p-3 font-medium text-text-primary">
        <span className="block truncate" title={file.name}>{file.name}</span>
        {file.request_reference && <span className="font-mono text-[11px] text-text-muted" dir="ltr">{file.request_reference}</span>}
      </td>
      <td className="p-3 text-text-secondary"><span className="rounded-md bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold">{typeLabel(file.mime, t.unknown_type)}</span></td>
      <td className="p-3 text-text-secondary">{isDrive ? t.src_drive : t.src_request}</td>
      <td className="p-3 text-text-muted"><span className="tnum">{formatDate(fileModifiedAt(file))}</span></td>
      <td className="p-3 text-end">
        {file.web_view_link ? (
          <a href={file.web_view_link} target="_blank" rel="noopener noreferrer"
            className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-brand-600 hover:border-brand-400">
            {t.open} <ExternalLink size={13} />
          </a>
        ) : (
          <span className="text-[11px] text-text-muted">—</span>
        )}
      </td>
    </tr>
  )
}
