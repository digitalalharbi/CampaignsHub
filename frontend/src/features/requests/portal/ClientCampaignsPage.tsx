import { useQuery } from '@tanstack/react-query'
import { Megaphone } from 'lucide-react'
import { formatDate, formatNumber, listClientCampaigns, type PortalCampaign } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'الحملات', subtitle: 'متابعة مباشرة لأداء حملاتك — المؤشرات الرئيسية دون تفاصيل التكلفة.',
    none: 'لا توجد حملات بعد.', error: 'تعذّر تحميل الحملات.',
    objective: 'الهدف', period: 'الفترة', to: '←',
    impressions: 'الظهور', clicks: 'النقرات', conversions: 'التحويلات', ctr: 'نسبة النقر',
  },
  en: {
    title: 'Campaigns', subtitle: 'A live view of your campaign delivery — headline metrics, no cost details.',
    none: 'No campaigns yet.', error: 'Could not load campaigns.',
    objective: 'Objective', period: 'Period', to: '→',
    impressions: 'Impressions', clicks: 'Clicks', conversions: 'Conversions', ctr: 'CTR',
  },
}

const STATUS_TONE: Record<string, string> = {
  active: 'bg-success/15 text-success',
  running: 'bg-success/15 text-success',
  paused: 'bg-warning/15 text-warning',
  scheduled: 'bg-info/15 text-info',
  draft: 'bg-surface-secondary text-text-muted',
  ended: 'bg-surface-secondary text-text-muted',
  completed: 'bg-surface-secondary text-text-muted',
  archived: 'bg-surface-secondary text-text-muted',
}

function statusTone(status: string): string {
  return STATUS_TONE[status] ?? 'bg-surface-secondary text-text-muted'
}

export function ClientCampaignsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'campaigns'], queryFn: listClientCampaigns, retry: false })
  usePortalGuard(q.isError, q.error)

  const rows = q.data ?? []

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {q.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2">{[0, 1].map((i) => <div key={i} className="h-44 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Megaphone size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {rows.map((campaign) => <CampaignCard key={campaign.id} campaign={campaign} t={t} />)}
        </div>
      )}
    </PortalShell>
  )
}

function CampaignCard({ campaign, t }: { campaign: PortalCampaign; t: typeof COPY.ar }) {
  const m = campaign.metrics
  const ctr = m.ctr === null ? '—' : `${(m.ctr * 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`
  const hasPeriod = campaign.starts_on || campaign.ends_on

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-start justify-between gap-2">
        <span className="font-semibold text-text-primary">{campaign.name}</span>
        <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${statusTone(campaign.status)}`}>{campaign.status}</span>
      </div>

      <div className="flex flex-col gap-1 text-xs text-text-secondary">
        {campaign.objective && <span>{t.objective}: <span className="font-medium text-text-primary">{campaign.objective}</span></span>}
        {hasPeriod && (
          <span className="tnum">{t.period}: {formatDate(campaign.starts_on)} {t.to} {formatDate(campaign.ends_on)}</span>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2 border-t border-border pt-3">
        <Metric label={t.impressions} value={formatNumber(m.impressions)} />
        <Metric label={t.clicks} value={formatNumber(m.clicks)} />
        <Metric label={t.conversions} value={formatNumber(m.conversions)} />
        <Metric label={t.ctr} value={ctr} />
      </div>
    </div>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{label}</span>
      <span className="tnum text-lg font-extrabold text-text-primary" dir="ltr">{value}</span>
    </div>
  )
}
