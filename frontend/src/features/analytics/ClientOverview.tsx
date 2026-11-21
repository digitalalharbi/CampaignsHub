import { KpiCards } from './KpiCards'
import { RateTrend, SERIES } from './components'
import { compact, money, ratio } from './format'
import { periodRates, TrendPanel, type OverviewData } from './OverviewCompositions'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { Num } from '@/components/ui/Num'
import { MetricTable } from '@/components/ui/MetricTable'
import type { CampaignRow, PlatformRow, Summary } from './api'
import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

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
  const { ar, s, series, ts, chartCurrency, comparable, unlikeNote, vm, points, objective, projectId, range } = d
  /*
   * CLIENT-RESULTS-001 — the project's own Results, as its reports count them (NASEL: a native form lead or a landing
   * completion). Where the project defines them they lead the cards, and the platforms' generic «conversions» and the
   * messaging «leads» — other things, which told NASEL 2 leads where its report told 899 — step aside.
   */
  const results = useQuery({
    queryKey: ['metrics', 'results', projectId, range.from, range.to],
    queryFn: () => getData<ProjectResults>(`/projects/${projectId}/metrics/results?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })
  const r = results.data?.configured ? results.data : null
  const summary = useMemo(() => withResults(s.data, r), [s.data, r])
  const current = (summary?.current ?? {}) as Record<string, unknown>
  const has = (k: string) => k in current
  const order = r ? CLIENT_KPI_ORDER.filter((k) => !['leads', 'conversions', 'purchases'].includes(k)) : CLIENT_KPI_ORDER
  const shown = order.filter((k) => has(k) && current[k] !== null && current[k] !== undefined && current[k] !== 0)
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
        summary={summary}
        ar={ar}
        series={points}
        comparable={comparable}
        loading={s.isPending || results.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
        defaults={shown.length > 0 ? shown.slice(0, 4) : ['impressions', 'clicks', 'ctr', 'reach']}
      />
      {r ? (
        // Results have no day-by-day count; the days are drawn by what the platforms report every day: reach and clicks.
        <div className="grid gap-3 lg:grid-cols-2" data-testid="client-delivery-trend">
          <RateTrend title={ar ? 'الظهور يومًا بيوم' : 'Impressions, day by day'} data={series.rows} dataKey="impressions" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => compact(v)} headline={Number(current.impressions ?? 0)} />
          <RateTrend title={ar ? 'النقرات يومًا بيوم' : 'Clicks, day by day'} data={series.rows} dataKey="clicks" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => compact(v)} headline={Number(current.clicks ?? 0)} />
        </div>
      ) : (
        <TrendPanel {...d} title={showMoney ? undefined : (ar ? 'تحويلات المنصة يومًا بيوم' : 'Platform conversions, day by day')} description={showMoney ? undefined : (ar ? 'الاتجاه اليومي للنتائج خلال الفترة' : 'Results across the period, day by day')} />
      )}
      {rates > 0 && (
        <div className={`grid gap-3 ${rates === 1 ? '' : rates === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-3'}`}>
          {has('roas') && <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} headline={periodRates(s.data?.current).roas} />}
          {has('cpa') && !r && <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} headline={periodRates(s.data?.current).cpa} />}
          {has('ctr') && <RateTrend title={ar ? 'معدل النقر' : 'CTR'} data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} headline={periodRates(s.data?.current).ctr} />}
        </div>
      )}
      {showMoney && !r
        ? <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} projectId={null} />
        : <ClientResults platforms={d.platformRows.data ?? []} campaigns={d.campaigns.data ?? []} results={r} ar={ar} />}
    </div>
  )
}

/** CLIENT-RESULTS-001 — the project's Results as `GET …/metrics/results` answers them. */
export interface ProjectResults {
  configured: boolean
  total?: number | null
  cost?: number | null
  previous_total?: number | null
  delta?: number | null
  by_platform?: Record<string, { results: number; metric: string; spend?: number; cost?: number | null }>
  by_campaign?: Array<{ campaign_id: string; campaign_name?: string | null; provider: string; results: number; metric: string }>
}

/** The summary with the project's Results put where the cards read them: `results` and, with money, its cost. */
function withResults(summary: Summary | undefined, r: ProjectResults | null): Summary | undefined {
  if (!summary || !r || r.total === null || r.total === undefined) return summary
  const out = { ...summary, current: { ...summary.current, results: r.total }, previous: { ...summary.previous, results: r.previous_total ?? null } } as Summary
  const withDelta = out as unknown as { delta?: Record<string, number | null>; reported?: Record<string, boolean> }
  if (withDelta.delta) withDelta.delta = { ...withDelta.delta, results: r.delta ?? null }
  if (withDelta.reported) withDelta.reported = { ...withDelta.reported, results: true }
  if (r.cost !== null && r.cost !== undefined && 'spend' in summary.current) {
    (out.current as unknown as Record<string, unknown>).cost_per_result = r.cost
  }

  return out
}

/** The order a client's headline cards are offered in, results first; only those the server sent are drawn. */
const CLIENT_KPI_ORDER: readonly string[] = ['results', 'cost_per_result', 'purchases', 'leads', 'conversions', 'revenue', 'roas', 'spend', 'cpa', 'reach', 'impressions', 'clicks', 'ctr']

/**
 * The platform and campaign comparison for a client who is not shown money: delivery and results only, platforms by
 * results and the campaigns that brought the most results. A platform or campaign with nothing in the window is left out.
 */
function ClientResults({ platforms, campaigns, results, ar }: { platforms: PlatformRow[]; campaigns: CampaignRow[]; results: ProjectResults | null; ar: boolean }) {
  const resultOf = (provider: string) => (results ? (results.by_platform?.[provider]?.results ?? null) : null)
  const score = (p: PlatformRow) => (results ? (resultOf(p.provider) ?? 0) : (p.conversions ?? 0))
  const pRows = platforms.filter((p) => (p.impressions ?? 0) > 0 || score(p) > 0)
    .sort((a, b) => score(b) - score(a) || (b.impressions ?? 0) - (a.impressions ?? 0))
  const cRows = results
    ? (results.by_campaign ?? []).map((c) => ({ id: c.campaign_id, name: c.campaign_name ?? null, provider: c.provider, value: c.results }))
    : campaigns.filter((c) => (c.conversions ?? 0) > 0).sort((a, b) => (b.conversions ?? 0) - (a.conversions ?? 0)).slice(0, 8)
      .map((c) => ({ id: c.campaign_id, name: c.campaign_name, provider: c.provider, value: c.conversions ?? 0 }))
  if (pRows.length === 0 && cRows.length === 0) return null
  const n = (v: number | null | undefined) => (v === null || v === undefined ? '—' : <Num>{new Intl.NumberFormat('en').format(Math.round(v))}</Num>)
  const resultWord = results ? (ar ? 'النتائج' : 'Results') : (ar ? 'تحويلات المنصة' : 'Conversions')

  return (
    <div className="grid gap-3" data-testid="client-results">
      {pRows.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-4">
          <h3 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'المنصات' : 'Platforms'}</h3>
          <MetricTable
            testId="client-platforms"
            head={[ar ? 'المنصة' : 'Platform', ar ? 'الظهور' : 'Impressions', ar ? 'النقرات' : 'Clicks', resultWord]}
            rows={pRows.map((p) => [
              <span key="p" data-testid={`client-platform-${p.provider}`} className="flex items-center gap-2"><ProviderMark provider={p.provider} size={18} />{providerLabel(p.provider, ar ? 'ar' : 'en')}</span>,
              n(p.impressions), n(p.clicks), <b key="c">{n(results ? resultOf(p.provider) : p.conversions)}</b>,
            ])}
            values={pRows.map((p) => [providerLabel(p.provider, ar ? 'ar' : 'en'), p.impressions ?? null, p.clicks ?? null, results ? resultOf(p.provider) : (p.conversions ?? null)])}
          />
        </section>
      )}
      {cRows.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-4">
          <h3 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'أعلى الحملات نتائج' : 'Campaigns with the most results'}</h3>
          <ul className="divide-y divide-border/60">
            {cRows.map((c, i) => (
              <li key={c.id || i} className="flex items-center gap-2 py-2" data-testid={`client-campaign-${c.id}`}>
                <ProviderMark provider={c.provider} size={18} />
                <span dir="auto" className="min-w-0 flex-1 truncate text-sm text-text-primary">{c.name ?? `${providerLabel(c.provider, ar ? 'ar' : 'en')} · ${ar ? 'حملة' : 'Campaign'} ${i + 1}`}</span>
                <span className="shrink-0 text-sm font-bold text-text-primary">{n(c.value)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
