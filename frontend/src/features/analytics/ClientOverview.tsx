import { KpiCards } from './KpiCards'
import { RateTrend, SERIES } from './components'
import { money, ratio } from './format'
import { periodRates, TrendPanel, type OverviewData } from './OverviewCompositions'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { Num } from '@/components/ui/Num'
import type { CampaignRow, PlatformRow } from './api'

/**
 * CLIENT-DASHBOARD-PARITY-001 — the client's dashboard: the agency Overview's own figures and drawings, without its
 * internal reading.
 *
 * Same data (`useOverviewData`), same cards, same curve, same platform and campaign comparison. Left out: the diagnosis
 * and change-driver blocks (an agency's working notes, CLIENT-DIAGNOSTIC-SEPARATION-001) and the store ledger. The
 * cards open on the figures the server actually sent: a figure the agency keeps from this client is absent from the
 * answer, so no card is drawn for it — never a dash where a number would be.
 */
export function ClientOverview(d: OverviewData) {
  const { ar, s, series, ts, chartCurrency, comparable, unlikeNote, vm, points, objective } = d
  const current = (s.data?.current ?? {}) as Record<string, unknown>
  const shown = CLIENT_KPI_ORDER.filter((k) => k in current && current[k] !== null && current[k] !== undefined && current[k] !== 0)
  const has = (k: string) => k in current
  // The agency keeps money from this client: no spend-based block is drawn, rather than one reading «$ 0».
  const showMoney = has('spend')
  const rates = ['roas', 'cpa', 'ctr'].filter(has).length

  return (
    <div className="space-y-4" data-testid="client-overview" data-composition="client">
      {!comparable && s.data && (
        <p data-testid="no-comparison-period" className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
          <span className="font-semibold text-text-primary">{ar ? 'لا توجد مقارنة: ' : 'No comparison: '}</span>
          {unlikeNote}
        </p>
      )}
      <KpiCards
        id="client"
        objective={objective}
        summary={s.data}
        ar={ar}
        series={points}
        comparable={comparable}
        loading={s.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
        defaults={shown.length > 0 ? shown.slice(0, 4) : ['impressions', 'clicks', 'ctr', 'conversions']}
      />
      <TrendPanel {...d} title={showMoney ? undefined : (ar ? 'تحويلات المنصة يومًا بيوم' : 'Platform conversions, day by day')} description={showMoney ? undefined : (ar ? 'الاتجاه اليومي للنتائج خلال الفترة' : 'Results across the period, day by day')} />
      {rates > 0 && (
        <div className={`grid gap-3 ${rates === 1 ? '' : rates === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-3'}`}>
          {has('roas') && <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} headline={periodRates(s.data?.current).roas} />}
          {has('cpa') && <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} headline={periodRates(s.data?.current).cpa} />}
          {has('ctr') && <RateTrend title="CTR" data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} headline={periodRates(s.data?.current).ctr} />}
        </div>
      )}
      {showMoney
        ? <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} projectId={null} />
        : <ClientResults platforms={d.platformRows.data ?? []} campaigns={d.campaigns.data ?? []} ar={ar} />}
    </div>
  )
}

/** The order a client's headline cards are offered in, results first; only those the server sent are drawn. */
const CLIENT_KPI_ORDER = ['results', 'purchases', 'leads', 'conversions', 'revenue', 'roas', 'spend', 'cpa', 'reach', 'impressions', 'clicks', 'ctr'] as const

/**
 * The platform and campaign comparison for a client who is not shown money: delivery and results only, platforms by
 * results and the campaigns that brought the most results. A platform or campaign with nothing in the window is left out.
 */
function ClientResults({ platforms, campaigns, ar }: { platforms: PlatformRow[]; campaigns: CampaignRow[]; ar: boolean }) {
  const pRows = platforms.filter((p) => (p.impressions ?? 0) > 0 || (p.conversions ?? 0) > 0)
    .sort((a, b) => (b.conversions ?? 0) - (a.conversions ?? 0) || (b.impressions ?? 0) - (a.impressions ?? 0))
  const cRows = campaigns.filter((c) => (c.conversions ?? 0) > 0)
    .sort((a, b) => (b.conversions ?? 0) - (a.conversions ?? 0)).slice(0, 8)
  if (pRows.length === 0 && cRows.length === 0) return null
  const head = 'px-2 py-2 text-xs font-semibold text-text-muted'
  const cell = 'px-2 py-2 text-sm text-text-primary'
  const n = (v: number | null | undefined) => (v === null || v === undefined ? '—' : <Num>{new Intl.NumberFormat('en').format(Math.round(v))}</Num>)

  return (
    <div className="grid gap-3 lg:grid-cols-2" data-testid="client-results">
      {pRows.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-4">
          <h3 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'المنصات' : 'Platforms'}</h3>
          <table className="w-full text-start">
            <thead><tr className="border-b border-border text-start">
              <th className={`${head} text-start`}>{ar ? 'المنصة' : 'Platform'}</th>
              <th className={`${head} text-end`}>{ar ? 'الظهور' : 'Impressions'}</th>
              <th className={`${head} text-end`}>{ar ? 'النقرات' : 'Clicks'}</th>
              <th className={`${head} text-end`}>{ar ? 'تحويلات المنصة' : 'Conversions'}</th>
            </tr></thead>
            <tbody>
              {pRows.map((p) => (
                <tr key={p.provider} className="border-b border-border/60 last:border-0" data-testid={`client-platform-${p.provider}`}>
                  <td className={cell}><span className="flex items-center gap-2"><ProviderMark provider={p.provider} size={18} />{providerLabel(p.provider, ar ? 'ar' : 'en')}</span></td>
                  <td className={`${cell} text-end`}>{n(p.impressions)}</td>
                  <td className={`${cell} text-end`}>{n(p.clicks)}</td>
                  <td className={`${cell} text-end font-bold`}>{n(p.conversions)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
      {cRows.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-4">
          <h3 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'أعلى الحملات نتائج' : 'Campaigns with the most results'}</h3>
          <ul className="divide-y divide-border/60">
            {cRows.map((c) => (
              <li key={c.campaign_id} className="flex items-center gap-2 py-2" data-testid={`client-campaign-${c.campaign_id}`}>
                <ProviderMark provider={c.provider} size={18} />
                <span dir="auto" className="min-w-0 flex-1 truncate text-sm text-text-primary">{c.campaign_name ?? providerLabel(c.provider, ar ? 'ar' : 'en')}</span>
                <span className="shrink-0 text-sm font-bold text-text-primary">{n(c.conversions)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
