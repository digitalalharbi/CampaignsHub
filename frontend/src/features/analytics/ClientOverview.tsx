import { KpiCards } from './KpiCards'
import { RateTrend, SERIES } from './components'
import { money, ratio } from './format'
import { periodRates, TrendPanel, type OverviewData } from './OverviewCompositions'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'

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
      <TrendPanel {...d} />
      {(has('roas') || has('cpa') || has('ctr')) && (
        <div className="grid gap-3 lg:grid-cols-3">
          {has('roas') && <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} headline={periodRates(s.data?.current).roas} />}
          {has('cpa') && <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} headline={periodRates(s.data?.current).cpa} />}
          {has('ctr') && <RateTrend title="CTR" data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} headline={periodRates(s.data?.current).ctr} />}
        </div>
      )}
      <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} projectId={null} />
    </div>
  )
}

/** The order a client's headline cards are offered in, results first; only those the server sent are drawn. */
const CLIENT_KPI_ORDER = ['results', 'purchases', 'leads', 'conversions', 'revenue', 'roas', 'spend', 'cpa', 'reach', 'impressions', 'clicks', 'ctr'] as const
