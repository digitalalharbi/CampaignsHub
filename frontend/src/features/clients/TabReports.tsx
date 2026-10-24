import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Link2, Plus, Share2 } from 'lucide-react'
import { listClientReports, type ClientDetail, type ClientReport } from './api'
import { LiveLinkBuilder } from '@/features/reports/LiveLinkBuilder'
import { ReportBuilder, ShareManager } from '@/features/reports/ReportsPage'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

function statusLabel(s: ClientReport['status'], t: ReturnType<typeof useT>): string {
  return { processing: t('rp_processing'), completed: t('rp_completed'), failed: t('rp_failed'), draft: t('rp_draft') }[s]
}
function statusTone(s: ClientReport['status']): string {
  return s === 'completed' ? 'bg-success/15 text-success' : s === 'failed' ? 'bg-danger/15 text-danger' : 'bg-surface-secondary text-text-muted'
}

const COPY = {
  ar: { newReport: 'تقرير جديد', live: 'رابط مباشر', manage: 'المشاركة', project: 'المشروع', hint: 'التقرير الجديد يتيح اختيار «مباشر» (يتحدّث مع البيانات) أو «ثابت» (نسخة للفترة)، ونفس إعدادات الحسابات والمؤشرات والأقسام والهوية في صفحة التقارير.' },
  en: { newReport: 'New report', live: 'Live link', manage: 'Sharing', project: 'Project', hint: 'A new report can be Live (follows the synced data) or a Snapshot (fixed for its period), with the same accounts, metrics, sections and identity controls as the Reports page.' },
}

/**
 * CLIENT-REPORTS-UNIFIED-001 — the client file's Reports tab is the SAME report builder and share manager the Reports page uses.
 *
 * It used to carry a thin form of its own (name, type, audience) and a one-click share that minted a link with no scope, no
 * pause and no revoke. Creation, live links and link management are now the existing components, started from here with this
 * client's projects, so nothing is configured twice and nothing here can drift from the Reports page.
 */
export function TabReports({ d }: { d: ClientDetail }) {
  const t = useT()
  const c = COPY[useUi((s) => s.locale) === 'ar' ? 'ar' : 'en']
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['app', 'client', d.id, 'reports'], queryFn: () => listClientReports(d.id) })
  const [projectId, setProjectId] = useState(d.projects[0]?.id ?? '')
  const [open, setOpen] = useState<'report' | 'live' | null>(null)
  const [sharing, setSharing] = useState<ClientReport | null>(null)

  const reports = q.data?.reports ?? []
  const refresh = () => qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'reports'] })
  const field = 'h-10 rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'
  const button = 'flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold'

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="max-w-prose text-xs text-text-muted">{t('rp_internal_note')} {c.hint}</p>
        {d.can.view_reports && (
          <div className="flex flex-wrap items-center gap-2">
            {d.projects.length > 1 && (
              <select aria-label={c.project} data-testid="client-reports-project" className={field} value={projectId} onChange={(e) => setProjectId(e.target.value)}>
                {d.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            )}
            <button type="button" data-testid="client-reports-new" disabled={!projectId} onClick={() => setOpen('report')} className={`${button} bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-60`}><Plus size={15} /> {c.newReport}</button>
            <button type="button" data-testid="client-reports-live" disabled={!projectId} onClick={() => setOpen('live')} className={`${button} border border-border text-text-primary hover:bg-surface-secondary disabled:opacity-60`}><Link2 size={15} /> {c.live}</button>
          </div>
        )}
      </div>

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
                  <button type="button" data-testid={`client-report-share-${r.id}`} onClick={() => setSharing(r)} className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary"><Share2 size={13} /> {c.manage}</button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {open === 'report' && projectId && <ReportBuilder projectId={projectId} onClose={() => setOpen(null)} onCreated={() => { setOpen(null); refresh() }} />}
      {open === 'live' && projectId && <LiveLinkBuilder projectId={projectId} onClose={() => { setOpen(null); refresh() }} />}
      {sharing && <ShareManager projectId={sharing.project_id} reportId={sharing.id} onClose={() => setSharing(null)} />}
    </div>
  )
}
