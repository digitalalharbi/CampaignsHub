import { useQuery } from '@tanstack/react-query'
import { BarChart3, Download, Eye, ShieldCheck } from 'lucide-react'
import { formatDate, listClientReports, type PortalReport } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'التقارير', subtitle: 'تقارير الأداء المشاركة معك عبر رابط آمن.',
    none: 'لا توجد تقارير مُتاحة لك بعد.', error: 'تعذّر تحميل التقارير.',
    period: 'الفترة', generated: 'صدر في', to: '←',
    can_download: 'مُتاح للتنزيل', view_only: 'للعرض فقط', watermark: 'يحمل علامة مائية',
    expires: 'ينتهي في', shared_note: 'تُشارك التقارير عبر رابط آمن يُرسَل إليك؛ لا يُعرض الرابط هنا.',
  },
  en: {
    title: 'Reports', subtitle: 'Performance reports shared with you over a secure link.',
    none: 'No reports available to you yet.', error: 'Could not load reports.',
    period: 'Period', generated: 'Generated', to: '→',
    can_download: 'Available to download', view_only: 'View only', watermark: 'Watermarked',
    expires: 'Expires', shared_note: 'Reports are shared via a secure link sent to you; the link is not exposed here.',
  },
}

export function ClientReportsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'reports'], queryFn: listClientReports, retry: false })
  usePortalGuard(q.isError, q.error)

  const rows = q.data ?? []

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {q.isLoading ? (
        <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <div key={i} className="h-24 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><BarChart3 size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <div className="flex flex-col gap-3">
          <p className="flex items-center gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
            <ShieldCheck size={14} className="text-brand-600" /> {t.shared_note}
          </p>
          {rows.map((report) => <ReportCard key={report.id} report={report} t={t} />)}
        </div>
      )}
    </PortalShell>
  )
}

function ReportCard({ report, t }: { report: PortalReport; t: typeof COPY.ar }) {
  const share = report.share
  const canDownload = share?.allow_download ?? false

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-start justify-between gap-2">
        <div className="flex flex-col gap-1">
          <span className="font-semibold text-text-primary">{report.name}</span>
          <span className="text-xs text-text-secondary">
            <span className="tnum">{t.period}: {formatDate(report.period_start)} {t.to} {formatDate(report.period_end)}</span>
          </span>
          {report.generated_at && <span className="text-[11px] text-text-muted"><span className="tnum">{t.generated}: {formatDate(report.generated_at)}</span></span>}
        </div>
        <span className="whitespace-nowrap rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">{report.type}</span>
      </div>

      {/* Honest affordance: the backend never hands us a raw share link, so this is a status indicator,
          not a fabricated download button. It reflects exactly what the active share permits. */}
      <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
        {canDownload ? (
          <span className="inline-flex items-center gap-1.5 rounded-lg bg-success/15 px-2.5 py-1 text-xs font-semibold text-success"><Download size={13} /> {t.can_download}</span>
        ) : (
          <span className="inline-flex items-center gap-1.5 rounded-lg bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary"><Eye size={13} /> {t.view_only}</span>
        )}
        {share?.watermark && <span className="rounded-md bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-muted">{t.watermark}</span>}
        {share?.expires_at && <span className="text-[11px] text-text-muted"><span className="tnum">{t.expires}: {formatDate(share.expires_at)}</span></span>}
      </div>
    </div>
  )
}
