import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, FileText, Plus, Share2 } from 'lucide-react'
import { createClientReport, listClientReports, shareClientReport, type ClientDetail, type ClientReport } from './api'
import { useT } from '@/lib/i18n'

const REPORT_TYPES = ['executive', 'monthly', 'weekly', 'project', 'campaign', 'platform', 'platform_comparison', 'custom']

function statusLabel(s: ClientReport['status'], t: ReturnType<typeof useT>): string {
  return { processing: t('rp_processing'), completed: t('rp_completed'), failed: t('rp_failed'), draft: t('rp_draft') }[s]
}
function statusTone(s: ClientReport['status']): string {
  return s === 'completed' ? 'bg-success/15 text-success' : s === 'failed' ? 'bg-danger/15 text-danger' : 'bg-surface-secondary text-text-muted'
}

export function TabReports({ d }: { d: ClientDetail }) {
  const t = useT()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['app', 'client', d.id, 'reports'], queryFn: () => listClientReports(d.id) })
  const [showNew, setShowNew] = useState(false)
  const [shareLink, setShareLink] = useState<string | null>(null)
  const [form, setForm] = useState({ project_id: d.projects[0]?.id ?? '', name: '', type: 'monthly', audience: 'client' as ClientReport['audience'] })

  const create = useMutation({
    mutationFn: () => createClientReport(d.id, form),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'reports'] }); setShowNew(false); setForm((f) => ({ ...f, name: '' })) },
  })
  const share = useMutation({
    mutationFn: (reportId: string) => shareClientReport(d.id, reportId, { allow_download: true }),
    onSuccess: (r) => setShareLink(r.url),
  })

  const reports = q.data?.reports ?? []
  const field = 'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <p className="text-xs text-text-muted">{t('rp_internal_note')}</p>
        {d.can.view_reports && (
          <button onClick={() => setShowNew((v) => !v)} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700"><Plus size={15} /> {t('rp_new')}</button>
        )}
      </div>

      {showNew && (
        <form onSubmit={(e) => { e.preventDefault(); create.mutate() }} className="grid gap-3 rounded-xl border border-border bg-surface-secondary p-4 sm:grid-cols-2">
          <label className="text-xs font-semibold text-text-secondary">{t('rp_name')}
            <input className={field} required value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
          </label>
          <label className="text-xs font-semibold text-text-secondary">{t('rp_project')}
            <select className={field} value={form.project_id} onChange={(e) => setForm((f) => ({ ...f, project_id: e.target.value }))}>
              {d.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>
          <label className="text-xs font-semibold text-text-secondary">{t('rp_type')}
            <select className={field} value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
              {REPORT_TYPES.map((ty) => <option key={ty} value={ty}>{ty}</option>)}
            </select>
          </label>
          <label className="text-xs font-semibold text-text-secondary">{t('rp_audience')}
            <select className={field} value={form.audience} onChange={(e) => setForm((f) => ({ ...f, audience: e.target.value as ClientReport['audience'] }))}>
              <option value="client">{t('rp_aud_client')}</option>
              <option value="internal">{t('rp_aud_internal')}</option>
              <option value="executive">{t('rp_aud_executive')}</option>
            </select>
          </label>
          <div className="sm:col-span-2">
            <button type="submit" disabled={create.isPending || !form.project_id} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">{t('rp_create')}</button>
          </div>
        </form>
      )}

      {shareLink && (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
          <span className="truncate">{t('rp_shared')} <span className="font-mono text-xs" dir="ltr">{shareLink}</span></span>
          <button onClick={() => navigator.clipboard?.writeText(shareLink)} className="flex items-center gap-1 whitespace-nowrap text-xs font-semibold"><Copy size={13} /> {t('rp_copy')}</button>
        </div>
      )}

      {q.isLoading ? (
        <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />
      ) : reports.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-10 text-center text-text-muted"><FileText size={22} /><span className="text-sm">{t('rp_empty')}</span></div>
      ) : (
        <ul className="space-y-2">
          {reports.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-4 py-3 text-sm">
              <div className="flex items-center gap-2">
                <span className="font-medium text-text-primary">{r.name}</span>
                <span className="rounded bg-surface-secondary px-1.5 py-0.5 text-[11px] text-text-secondary">{r.type}</span>
                <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${r.audience === 'internal' ? 'bg-warning/15 text-warning' : 'bg-info/15 text-info'}`}>{t(`rp_aud_${r.audience}` as 'rp_aud_client')}</span>
              </div>
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusTone(r.status)}`}>{statusLabel(r.status, t)}</span>
                {r.shareable && d.can.view_reports && (
                  <button onClick={() => share.mutate(r.id)} disabled={share.isPending} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary"><Share2 size={13} /> {t('rp_share')}</button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
