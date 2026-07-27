import { useQuery } from '@tanstack/react-query'
import { Download, FileText, Lock } from 'lucide-react'
import { listClientFiles, type ClientFile } from './api'
import { useT } from '@/lib/i18n'

function fmtSize(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} MB`
}

export function TabFiles({ clientId }: { clientId: string }) {
  const t = useT()
  const q = useQuery({ queryKey: ['app', 'client', clientId, 'files'], queryFn: () => listClientFiles(clientId) })
  const files = q.data?.files ?? []

  if (q.isLoading) return <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />
  if (files.length === 0) return <div className="flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-10 text-center text-text-muted"><FileText size={22} /><span className="text-sm">{t('fl_empty')}</span></div>

  const sourceLabel = (s: ClientFile['source']) => (s === 'request' ? t('fl_source_request') : t('fl_source_report'))

  return (
    <div className="overflow-x-auto rounded-xl border border-border">
      <table className="w-full min-w-[720px] text-start text-sm">
        <thead className="border-b border-border bg-surface-secondary text-[11px] uppercase tracking-wide text-text-muted">
          <tr>
            <th className="p-3 text-start font-semibold">{t('fl_name')}</th>
            <th className="p-3 text-start font-semibold">{t('fl_source')}</th>
            <th className="p-3 text-start font-semibold">{t('fl_related')}</th>
            <th className="p-3 text-start font-semibold">{t('fl_visibility')}</th>
            <th className="p-3 text-end font-semibold">{t('fl_uploaded')}</th>
            <th className="p-3"></th>
          </tr>
        </thead>
        <tbody>
          {files.map((f) => (
            <tr key={`${f.source}:${f.id}`} className="border-b border-border/60 last:border-0">
              <td className="p-3"><div className="font-medium text-text-primary">{f.name}</div><div className="text-[11px] text-text-muted">{fmtSize(f.size)}{f.type ? ` · ${f.type}` : ''}</div></td>
              <td className="p-3 text-text-secondary">{sourceLabel(f.source)}</td>
              <td className="p-3 text-text-secondary">{f.related_entity.label ?? '—'}</td>
              <td className="p-3">
                {f.visibility === 'internal'
                  ? <span className="inline-flex items-center gap-1 rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-semibold text-warning"><Lock size={11} /> {t('fl_internal')}</span>
                  : <span className="rounded-full bg-info/15 px-2 py-0.5 text-[11px] font-semibold text-info">{t('fl_client_visible')}</span>}
              </td>
              <td className="p-3 text-end text-text-secondary">{f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString('en-CA') : '—'}</td>
              <td className="p-3 text-end">
                <a href={f.download_url} className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary"><Download size={13} /> {t('fl_download')}</a>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
