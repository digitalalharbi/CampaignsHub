import { useQuery } from '@tanstack/react-query'
import { StatCard } from '@/components/ui/StatCard'
import { AlertTriangle, CheckCircle2, Clock, XCircle } from 'lucide-react'
import { getClientAnalytics, type ClientAnalytics } from './api'
import { compact, money, moneyExact, num as fullNumber, ratio } from '@/features/analytics/format'
import { useT } from '@/lib/i18n'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useUi } from '@/stores/ui'

/**
 * NUMBER-PRESENTATION-001 §58 — this surface had its own formatter, and it followed neither rule.
 *
 * Counts printed in full («1,425,443» in a card sixty pixels wide) and money printed in full beside
 * them, with no way to reach either figure's exact form because there was nothing to reach — the
 * display WAS the record. The canonical formatters are used now: a large figure abbreviates and
 * carries its full form on the value's own title, and a cost-per keeps its decimals.
 */
const num = (v: number | null | undefined, digits = 0): string =>
  v === null || v === undefined ? '—' : v.toLocaleString('en-US', { maximumFractionDigits: digits })

/** A count, abbreviated for the card with its full figure one hover away. */
const counted = (v: number | null | undefined): { value: string; exact?: string } => {
  if (v === null || v === undefined) return { value: '—' }
  const shown = compact(v)
  const full = fullNumber(v)

  return shown === full ? { value: shown } : { value: shown, exact: full }
}

/** A money total, same rule. A cost-per does NOT come through here — its decimals are the decision. */
const cash = (v: number | null | undefined, currency: string | null): { value: string; exact?: string } => {
  if (v === null || v === undefined) return { value: '—' }
  const shown = money(v, currency ?? undefined)
  const full = moneyExact(v, currency ?? null)

  return shown === full ? { value: shown } : { value: shown, exact: full }
}
const pct = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : `${(v * 100).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`)

function DeltaBadge({ v }: { v: number | null | undefined }) {
  if (v === null || v === undefined) return null
  const up = v >= 0
  return <span className={`ms-1 text-[11px] font-semibold ${up ? 'text-success' : 'text-danger'}`}>{up ? '▲' : '▼'} {pct(Math.abs(v))}</span>
}

/** UX-KPI-PRESENTATION-001 — the shared card; `muted` is gone with the second background it named. */
function Kpi({ label, value, exact, delta }: { label: string; value: string; exact?: string; delta?: number | null; muted?: boolean }) {
  return <StatCard label={label} value={value} exact={exact} trailing={<DeltaBadge v={delta} />} />
}

function FreshnessBanner({ a, t }: { a: ClientAnalytics; t: ReturnType<typeof useT> }) {
  const f = a.freshness
  const map = {
    fresh: { icon: <CheckCircle2 size={15} />, cls: 'border-success/30 bg-success/10 text-success', label: t('an_freshness_fresh') },
    partial: { icon: <Clock size={15} />, cls: 'border-warning/30 bg-warning/10 text-warning', label: t('an_freshness_partial') },
    stale: { icon: <Clock size={15} />, cls: 'border-warning/30 bg-warning/10 text-warning', label: t('an_freshness_stale') },
    sync_failed: { icon: <XCircle size={15} />, cls: 'border-danger/30 bg-[var(--negative-background)] text-danger', label: t('an_freshness_sync_failed') },
    no_data: { icon: <AlertTriangle size={15} />, cls: 'border-border bg-surface-secondary text-text-muted', label: t('an_freshness_no_data') },
  }[f.state]
  return (
    <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border px-3 py-2 text-xs font-semibold ${map.cls}`}>
      <span className="flex items-center gap-1.5">{map.icon} {map.label}</span>
      {f.last_sync_at && <span className="font-normal opacity-80">{t('an_last_sync')}: {new Date(f.last_sync_at).toLocaleString('en-CA')}</span>}
      {f.missing_days > 0 && <span className="font-normal opacity-80">{t('an_missing_days')}: {f.missing_days}</span>}
      {a.attribution.windows.length > 0 && <span className="font-normal opacity-80">{t('an_attribution')}: {a.attribution.windows.join(', ')}</span>}
      <span className="font-normal opacity-80">{t('an_source_of_truth')}: {a.source_of_truth}</span>
    </div>
  )
}

export function TabAnalytics({ clientId }: { clientId: string }) {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useQuery({ queryKey: ['app', 'client', clientId, 'analytics'], queryFn: () => getClientAnalytics(clientId) })

  if (q.isLoading) return <div className="h-40 animate-pulse rounded-xl bg-surface-secondary" />
  // `clients.view_analytics` is a real boundary here, so the refusal must not read as a broken tab.
  if (q.isError) {
    return (
      <QueryFailure error={q.error} ar={ar} testId="client-analytics-failure" onRetry={() => void q.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل التحليلات.' : 'Analytics could not be loaded.'} />
    )
  }
  const a = q.data!
  const cur = a.currency ? ` ${a.currency}` : ''

  return (
    <div className="grid gap-4">
      <FreshnessBanner a={a} t={t} />

      {!a.roas_is_primary && a.currency_mode !== 'none' && (
        <p className="rounded-lg border border-info/25 bg-info/10 px-3 py-2 text-xs text-info">{t('an_roas_not_primary')}</p>
      )}

      {a.currency_mode === 'mixed' ? (
        <>
          <p className="rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-warning">{t('an_mixed_currency_note')}</p>
          <div className="grid grid-cols-3 gap-3">
            <Kpi label={t('an_impressions')} {...counted(a.counts?.impressions)} />
            <Kpi label={t('an_clicks')} {...counted(a.counts?.clicks)} />
            <Kpi label={t('an_results')} {...counted(a.counts?.conversions)} />
          </div>
          <div>
            <h3 className="mb-2 text-sm font-bold text-text-primary">{t('an_projects')}</h3>
            <ul className="space-y-1.5">
              {a.projects.map((p) => (
                <li key={p.project_id} className="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm">
                  <span className="text-text-primary">{p.name}</span>
                  <span className="tnum font-semibold text-text-secondary">{num(p.spend, 2)} {p.currency ?? ''}</span>
                </li>
              ))}
            </ul>
          </div>
        </>
      ) : a.currency_mode === 'none' || !a.totals ? (
        <p className="rounded-xl border border-border bg-surface-secondary p-6 text-center text-sm text-text-muted">{t('an_no_metrics')}</p>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Kpi label={`${t('an_spend')}${cur}`} {...cash(a.totals.spend, a.currency ?? null)} delta={a.delta?.spend} />
            <Kpi label={t('an_results')} {...counted(a.totals.conversions)} delta={a.delta?.conversions} />
            <Kpi label={`${t('an_revenue')}${cur}`} {...cash(a.totals.revenue, a.currency ?? null)} delta={a.delta?.revenue} />
            <Kpi label={t('an_roas')} value={ratio(a.totals.roas)} delta={a.delta?.roas} muted={!a.roas_is_primary} />
            <Kpi label={`${t('an_cpa')}${cur}`} value={num(a.totals.cpa, 2)} delta={a.delta?.cpa} />
            <Kpi label={t('an_ctr')} value={pct(a.totals.ctr)} delta={a.delta?.ctr} />
            <Kpi label={`${t('an_cpc')}${cur}`} value={num(a.totals.cpc, 2)} delta={a.delta?.cpc} />
            <Kpi label={`${t('an_cpm')}${cur}`} value={num(a.totals.cpm, 2)} delta={a.delta?.cpm} />
          </div>
          <p className="text-[11px] text-text-muted">{t('an_vs_prev')}</p>

          {a.platforms.length > 0 && (
            <div>
              <h3 className="mb-2 text-sm font-bold text-text-primary">{t('an_platforms')}</h3>
              <ul className="space-y-1.5">
                {a.platforms.map((p) => (
                  <li key={p.provider} className="flex items-center gap-3 rounded-lg border border-border px-3 py-2 text-sm">
                    <span className="w-20 font-medium text-text-primary">{p.provider}</span>
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round(p.spend_share * 100)}%` }} /></div>
                    <span className="tnum w-28 text-end text-text-secondary">{num(p.spend, 2)}{cur}</span>
                    <span className="tnum w-12 text-end text-xs text-text-muted">{pct(p.spend_share)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="grid gap-3 sm:grid-cols-2">
            {a.best_campaign && <CampaignCard title={t('an_best')} c={a.best_campaign} good />}
            {a.worst_campaign && <CampaignCard title={t('an_worst')} c={a.worst_campaign} />}
          </div>
        </>
      )}

      {a.objective_mix.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className="font-semibold text-text-secondary">{t('an_objective_mix')}:</span>
          {a.objective_mix.map((o) => <span key={o.objective} className="rounded-full bg-surface-secondary px-2 py-0.5 text-text-secondary">{o.objective} · {o.count}</span>)}
        </div>
      )}
    </div>
  )
}

function CampaignCard({ title, c, good }: { title: string; c: Record<string, unknown>; good?: boolean }) {
  return (
    <div className={`rounded-xl border p-3 ${good ? 'border-success/30 bg-success/5' : 'border-border bg-surface-secondary'}`}>
      <div className="text-xs font-semibold text-text-muted">{title}</div>
      <div className="mt-0.5 truncate text-sm font-bold text-text-primary">{String(c.campaign_name ?? c.client_display_name ?? '—')}</div>
      <div className="mt-1 flex gap-3 text-[11px] text-text-secondary">
        <span>ROAS {ratio(c.roas as number | null | undefined)}</span>
        <span>CPA {num((c.cpa as number) ?? null, 2)}</span>
        <span>Spend {num((c.spend as number) ?? null, 2)}</span>
      </div>
    </div>
  )
}
