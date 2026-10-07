import { useQuery } from '@tanstack/react-query'
import { StatCard } from '@/components/ui/StatCard'
import { AlertTriangle, CheckCircle2, Clock, XCircle } from 'lucide-react'
import { getClientAnalytics, type ClientAnalytics } from './api'
import { compact, money, moneyExact, num as fullNumber, ratio } from '@/features/analytics/format'
import { ChartCard, MetricLineChart, SpendRevenueAreaChart, StatusMixBar, type MixTone } from '@/features/analytics/charts'
import { objectiveLabel } from '@/features/campaigns/labels'
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

      <ClientTrends a={a} t={t} ar={ar} />

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
                    {/*
                      * A share of nothing is not a bar at zero.
                      *
                      * This drew `p.spend_share * 100` unguarded, and the server sends null when no
                      * spend was recorded in the window — so an empty fill appeared beside the «—»
                      * this same row prints for the figure, one reading «contributed nothing» and the
                      * other «nothing to take a share of». A dashed, unfilled track cannot be mistaken
                      * for a measured zero.
                      */}
                    {p.spend_share === null ? (
                      <div className="h-2 flex-1 rounded-full border border-dashed border-border" data-testid="spend-share-unmeasured" />
                    ) : (
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round(p.spend_share * 100)}%` }} /></div>
                    )}
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

          {/*
            Both cards absent is not one situation. A client whose money we hold and cannot convert
            was shown exactly what a client with no campaigns was shown: nothing. Ranking withheld
            money is genuinely impossible — a ROAS from a denominator we could not state is a made-up
            ratio — so the surface says which silence this is instead of leaving a gap.
          */}
          {!a.best_campaign && !a.worst_campaign && a.campaign_ranking_state === 'withheld' && (
            <p className="text-xs leading-relaxed text-text-muted" data-testid="client-ranking-withheld">
              {ar
                ? 'إنفاق هذه الحملات لم يُحوَّل إلى عملة التقرير، فلا يمكن ترتيب الأفضل والأضعف بدقة.'
                : 'Spend on these campaigns was not converted into the report’s currency, so best and weakest cannot be ranked accurately.'}
            </p>
          )}
        </>
      )}

      {a.objective_mix.length > 0 && (
        <ChartCard title={t('an_objective_mix')}>
          {/*
            The objective split is a COMPOSITION, so it is drawn as one divided bar rather than as a
            row of chips. The chips also printed the stored key: `sales`, `app_installs`, `leads` —
            the product has had a canonical label for those since the campaigns module, and this was
            the one surface not using it.

            It is a count, which is why it draws whatever the currency is doing. Blending riyals with
            dollars makes a money chart unreadable; it says nothing about how many campaigns carry
            which objective.
          */}
          <StatusMixBar
            testId="client-objective-mix"
            ar={ar}
            label={t('an_objective_mix')}
            total={a.objective_mix.reduce((sum, o) => sum + o.count, 0)}
            bands={a.objective_mix.map((o, i) => ({
              key: o.objective || 'unspecified',
              label: o.objective ? objectiveLabel(o.objective, ar ? 'ar' : 'en') : (ar ? 'غير محدد' : 'Unspecified'),
              count: o.count,
              tone: OBJECTIVE_TONES[i % OBJECTIVE_TONES.length],
            }))}
          />
        </ChartCard>
      )}
    </div>
  )
}

/**
 * The bands' colours, cycled.
 *
 * An objective has no semantic colour — «sales» is not better or worse than «awareness» — so these
 * are the chart layer's own sequence rather than the success/warning/danger set, which would read as
 * a judgement the data is not making.
 */
const OBJECTIVE_TONES: MixTone[] = ['brand', 'info', 'success', 'warning', 'danger']

/**
 * VIZ-CLIENT-001 — the series this tab has always served, finally drawn.
 *
 * `timeseries` — date, spend, clicks, impressions, conversions, revenue — has been in this payload
 * since the tab existed, and the tab rendered eight cards of period totals over it. «Spend 108K SAR»
 * answers «how much»; it cannot answer «when», which is the question somebody opens a client's
 * analytics to ask.
 *
 * ## What «mixed» actually does to this payload, and the note that nearly got it wrong
 *
 * `money_blended` reads like «these figures mix currencies». It means the opposite: the server sets
 * it TRUE on the branch where a single currency was established and the money was safe to aggregate,
 * and FALSE on the mixed branch where it refuses to aggregate at all. Reading the name rather than
 * the service produced a page that declined to draw a money line while printing «96.1K SAR» in the
 * card beside it — caught in the browser against a real client, not by a test.
 *
 * The mixed branch also sends `timeseries: []`. So on a mixed client there is no series of ANY kind,
 * counts included, and the emptiness is a REFUSAL rather than an absence. Saying «no measured days»
 * there would report withheld data as missing data, which is the one thing this product does not do
 * — hence a note of its own, naming the currency rule as the reason.
 *
 * Where a series does arrive, both charts draw: money because a single currency was established, and
 * counts because impressions, clicks and results carry no currency at all.
 *
 * ## And impressions get their own axis
 *
 * A daily impression count in the thousands beside a daily result count in single digits is one line
 * and one flat mark along the bottom, and a line pinned to the axis reads as «this was zero». The
 * chart layer's `rightAxisFor` exists for exactly this, and it follows the reader's direction.
 */
function ClientTrends({ a, t, ar }: { a: ClientAnalytics; t: ReturnType<typeof useT>; ar: boolean }) {
  const points = a.timeseries ?? []

  if (a.currency_mode === 'mixed') {
    /*
     * Not «no data» — a refusal. `ClientAnalyticsService` returns `timeseries: []` on this branch
     * deliberately, because a daily line over two currencies would add riyals to dollars at every
     * point. The per-project figures above carry the same money, each in its own currency.
     */
    return (
      <p data-testid="client-trend-currency-withheld" className="rounded-xl border border-dashed border-warning/40 bg-warning/5 px-4 py-5 text-center text-sm text-text-secondary">
        {ar
          ? 'إنفاق هذا العميل بأكثر من عملة، فلا تُرسل سلسلة يومية مجمّعة — كل نقطة عليها ستكون جمعًا لعملات مختلفة. أرقام كل مشروع أعلاه بعملته.'
          : 'This client’s spend spans more than one currency, so no combined daily series is sent — every point on it would add one currency to another. The per-project figures above are each in their own currency.'}
      </p>
    )
  }

  if (points.length === 0) {
    return (
      <p data-testid="client-trend-empty" className="rounded-xl border border-dashed border-border px-4 py-5 text-center text-sm text-text-muted">
        {ar ? 'لا توجد أيام مقيسة في هذه الفترة لرسمها.' : 'No measured days in this period to draw.'}
      </p>
    )
  }

  if (points.length < 2) {
    /*
     * One point is not a line. Drawing it produces a single dot on an empty grid, which reads as a
     * chart that failed to load rather than as a window one day long — and a trend asserted from one
     * day is a claim about direction that one day cannot make.
     */
    return (
      <p data-testid="client-trend-too-short" className="rounded-xl border border-dashed border-border px-4 py-5 text-center text-sm text-text-muted">
        {ar
          ? 'يوم واحد مقيس فقط — الاتجاه يحتاج يومين على الأقل.'
          : 'Only one measured day — a trend needs at least two.'}
      </p>
    )
  }

  /*
   * `money_blended` is NOT consulted. It is true on exactly the branch that establishes a single
   * currency, so testing it would either be redundant with this line or, read the way its name
   * suggests, invert it.
   */
  const drawMoney = a.currency_mode === 'single' && Boolean(a.currency)

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      <ChartCard
        title={ar ? 'الإنفاق مقابل الإيراد' : 'Spend against revenue'}
        subtitle={ar ? 'لكل يوم في الفترة المختارة.' : 'For each day of the selected period.'}
      >
        {drawMoney ? (
          <div data-testid="client-money-trend" className="min-w-0">
            <SpendRevenueAreaChart data={points} currency={a.currency ?? 'SAR'} height={240} />
          </div>
        ) : (
          <p data-testid="client-money-trend-withheld" className="rounded-xl border border-dashed border-warning/40 bg-warning/5 px-4 py-5 text-center text-sm text-text-secondary">
            {ar
              ? 'إنفاق هذا العميل بأكثر من عملة ولم يُحوَّل، فكل نقطة على الخط ستكون جمعًا لعملات مختلفة.'
              : 'This client’s spend spans more than one currency and was not converted, so every point on the line would be a sum of different currencies.'}
          </p>
        )}
      </ChartCard>

      <ChartCard
        title={ar ? 'النتائج والنقرات والظهور' : 'Results, clicks and impressions'}
        subtitle={ar ? 'أعداد — لا تتأثر بعملة الإنفاق.' : 'Counts — untouched by what the spend is priced in.'}
      >
        <div data-testid="client-count-trend" className="min-w-0">
          <MetricLineChart
            height={240}
            data={points}
            rightAxisFor="impressions"
            series={[
              { key: 'conversions', name: t('an_results'), kind: 'num' },
              { key: 'clicks', name: t('an_clicks'), kind: 'num' },
              { key: 'impressions', name: t('an_impressions'), kind: 'num' },
            ]}
          />
        </div>
      </ChartCard>
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
