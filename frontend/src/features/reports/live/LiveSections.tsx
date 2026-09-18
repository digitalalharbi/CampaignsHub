import { useMemo } from 'react'
import { AlertTriangle } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { ChartCard, ConversionFunnelChart, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { moneyState, rankableMoney, type MoneyTotals } from '@/lib/money/contract'
import { Num } from '@/components/ui/Num'
import { platformColor } from '@/features/analytics/components'
import { ratio } from '@/features/analytics/format'
import type { LivePayload } from '../api'
import type { useLiveMetricReader } from './liveMetrics'
import { sectionShown } from '../reportSections'

/*
 * The sections a live link composes its modes from — lifted out of the single long page so each mode
 * uses the same block rather than a copy of it. Their bodies are unchanged: every money decision, every
 * «unreported is not zero» rule and every comment stating why travelled with them.
 */

type Reader = ReturnType<typeof useLiveMetricReader>

const MONEY_METRICS = new Set(['cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cpe', 'cost_per_lpv'])

/**
 * The strongest and weakest PLATFORM inside each objective path — as two figures, not a sentence.
 *
 * Ranked inside a path, never across paths (a brand path is not weak for producing no revenue). The
 * figure is the path's own ranking metric in the same notation every other card uses: a cost per
 * result exact, a return as a multiplier, a rate as a percentage.
 */
export function ObjectiveLeaders({ payload, ar, reader }: { payload: LivePayload; ar: boolean; reader: Reader }) {
  const paths = (payload.objective_leaders?.paths ?? []).filter((p) => p.comparable && p.strongest && p.weakest)
  if (paths.length === 0) return null

  const value = (metric: string, v: number | null | undefined): string => {
    if (v === null || v === undefined) return '—'
    if (MONEY_METRICS.has(metric)) return reader.asExactMoney(v)
    if (metric === 'roas') return ratio(v)
    if (metric === 'ctr' || metric.endsWith('_rate')) return `${(v * 100).toFixed(2)}%`
    return reader.count(v).text
  }
  const label = (metric: string) => (reader.meta[metric] ? (ar ? reader.meta[metric].ar : reader.meta[metric].en) : metric)

  return (
    <section data-testid="live-objective-leaders">
      <h3 className="mb-3 text-base font-bold tracking-tight text-text-primary">
        {ar ? 'الأقوى والأضعف لكل هدف' : 'Strongest and weakest, per objective'}
      </h3>
      <div className={`grid gap-3 ${paths.length > 1 ? 'md:grid-cols-2' : ''}`}>
        {paths.map((path) => (
          <div key={path.path} data-testid={`live-leaders-${path.path}`} className="rounded-2xl border border-border bg-surface p-4">
            <div className="mb-3 flex items-baseline justify-between gap-2">
              <span className="font-semibold text-text-primary">{ar ? path.label_ar : path.label_en}</span>
              <span className="text-xs text-text-muted">{label(path.strongest?.metric ?? path.metric)}</span>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {([['strongest', path.strongest, 'text-success', '▲'], ['weakest', path.weakest, 'text-danger', '▼']] as const).map(([kind, leader, tone, mark]) => {
                const key = canonicalPlatform(leader?.name ?? '')

                return (
                  <div key={kind} data-testid={`live-leaders-${path.path}-${kind}`} className="min-w-0 rounded-xl bg-surface-secondary p-3">
                    <div className={`text-[11px] font-semibold ${tone}`}>
                      <span aria-hidden>{mark} </span>{kind === 'strongest' ? (ar ? 'الأقوى' : 'Strongest') : (ar ? 'الأضعف' : 'Weakest')}
                    </div>
                    <div className="mt-1 flex items-center gap-1.5 truncate text-sm font-bold text-text-primary">
                      <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(key) }} aria-hidden />
                      {providerLabel(key, ar ? 'ar' : 'en')}
                    </div>
                    <div className="tnum mt-1 text-lg font-extrabold text-text-primary">
                      <Num>{value(leader?.metric ?? path.metric, leader?.value)}</Num>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

export function ObjectiveSplit({ payload, ar, reader }: { payload: LivePayload; ar: boolean; reader: Reader }) {
  const { asMoney, asExactMoney, revealed } = reader

  return (
    <>
        {payload.objective_performance && (
          <div data-testid="live-objective-split" className="mt-6 rounded-2xl border border-border bg-surface p-4">
            <h3 className="text-base font-bold text-text-primary">
              {ar ? 'المباشر مقابل المخلوط' : 'Direct against blended'}
            </h3>

            <div className="mt-2 grid gap-3 sm:grid-cols-2">
              {([['direct', payload.objective_performance.direct], ['blended', payload.objective_performance.blended]] as const).map(([kind, block]) => (
                <div key={kind} data-testid={`live-objective-${kind}`} className="rounded-xl border border-border p-3">
                  {/* The definition is a hover away rather than a paragraph under every figure — the client came for the figures. */}
                  <div
                    className="text-sm font-semibold text-text-primary"
                    title={kind === 'direct'
                      ? (ar ? 'إنفاق مسار المبيعات وحده، مقسومًا على طلباته.' : 'The sales path’s spend alone, over the orders it produced.')
                      : (ar ? 'كل الإنفاق في الفترة، بما فيه ما لم يكن يشتري طلبًا.' : 'All the spend in the window, including what was not buying an order.')}
                  >
                    {ar ? block.label_ar : block.label_en}
                  </div>
                  <dl className="mt-1 grid grid-cols-2 gap-1.5 text-xs">
                    <div>
                      <dt className="text-text-muted">{ar ? 'الإنفاق' : 'Spend'}</dt>
                      {/*
                        NUMBER-PRESENTATION-001 — the last two abbreviations on this page.
                        A census of the rendered report found six compact money figures; the KPI
                        cards and both detail tables revealed theirs, and these two did not. They
                        are the same «10.8K USD» the reader sees above, and a client comparing
                        direct against blended is doing arithmetic on figures the page rounded.
                      */}
                      <dd
                       
                        className="text-start tnum font-semibold text-text-primary"
                        title={revealed(block.spend)}
                      >
                        <Num>{asMoney(block.spend)}</Num>
                      </dd>
                    </div>
                    <div>
                      <dt className="text-text-muted">{ar ? 'تكلفة الطلب' : 'Cost per order'}</dt>
                      <dd className="tnum font-semibold text-text-primary">
                        <Num>{/*
                          Null stays «—». A cost per order that nobody could compute is not a cost of
                          zero, and this is the figure a client acts on.
                        */}
                        {/*
                          NUMBER-PRESENTATION-001 — a cost per order keeps its decimals.
                        
                          `asMoney()` is the COMPACT reader: it rounds 17.62 to «18 USD». Production
                          printed «تكلفة الطلب 17 USD» in this block beside «17.62» on the KPI card
                          above it — the same figure, twice, differently, on one page. And the whole
                          point of this panel is the COMPARISON between direct and blended: rounded to
                          whole units, 17.62 against 18.40 reads as «17 vs 18», a gap a third bigger
                          than the real one, on the one number a merchant decides from.
                        
                          Every other cost-per on this page already goes through `asExactMoney` —
                          `cpa`, `cpm`, `cpc`, `cpl`, `cpi`, `cpe` and cost per visit all do. This
                          block was the exception.
                        */}
                        {kind === 'direct'
                          ? (block as typeof payload.objective_performance.direct).cpa === null
                            ? '—'
                            : asExactMoney((block as typeof payload.objective_performance.direct).cpa)
                          : (block as typeof payload.objective_performance.blended).blended_cpa === null
                            ? '—'
                            : asExactMoney((block as typeof payload.objective_performance.blended).blended_cpa)}</Num>
                      </dd>
                    </div>
                  </dl>
                </div>
              ))}
            </div>
          </div>
        )}
    </>
  )
}

/** Whether a money chart may draw the scope's spend — PARTIAL-WITHHELD-001 (client charts). */
export function useSpendCharting(payload: LivePayload, currency: string) {
  return useMemo(() => {
    const spendState = moneyState(payload.totals as MoneyTotals, 'spend').state
    return {
      spendChartable: spendState === 'complete_converted' || spendState === 'zero',
      platformSpendRank: rankableMoney((payload.platforms ?? []) as MoneyTotals[], 'spend', currency),
    }
  }, [payload, currency])
}

export function TrendAndDistribution({ payload, ar, currency }: { payload: LivePayload; ar: boolean; currency: string }) {
  const { spendChartable, platformSpendRank } = useSpendCharting(payload, currency)
  // One card per section: the trend belongs to `trends`, the spend split to `platform_comparison`.
  const trend = sectionShown(payload, 'trends')
  const split = sectionShown(payload, 'platform_comparison')
  if (!trend && !split) return null

  return (
    <>
        <div className="mt-3 grid gap-3 lg:grid-cols-3" data-testid="live-platforms">
          {trend && (
          <ChartCard title={ar ? 'الأداء بمرور الوقت' : 'Performance over time'} className={split ? 'lg:col-span-2' : 'lg:col-span-3'}>
            <MetricLineChart
              data={payload.timeseries ?? []}
              currency={currency}
              height={220}
              /* Results on their own axis: on the spend axis a few hundred orders draw as a flat line at zero. */
              rightAxisFor="conversions"
              series={[
                // The spend line is drawn only when spend is in the report currency; otherwise it would
                // label a withheld/partial figure with a currency it is not in.
                ...(spendChartable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color: 'var(--brand-600)', kind: 'money' as const }] : []),
                { key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const },
                { key: 'conversions', name: ar ? 'النتائج' : 'Results', color: 'var(--purple)', kind: 'num' as const },
              ]}
            />
            {!spendChartable && (
              <p className="mt-1 text-center text-[11px] text-text-muted">{ar ? 'خط الإنفاق غير معروض: المبالغ بانتظار سعر صرف أو بعملات متعددة' : 'Spend line hidden: amounts await an exchange rate or span currencies'}</p>
            )}
          </ChartCard>
          )}
          {split && (
          <ChartCard title={ar ? 'توزيع الإنفاق' : 'Spend by platform'} className={trend ? undefined : 'lg:col-span-3'}>
            {platformSpendRank === null ? (
              <p className="flex h-[220px] items-center justify-center text-center text-sm text-text-muted">{ar ? 'توزيع الإنفاق غير متاح — مبالغ بانتظار سعر صرف أو بعملات متعددة لا تُجمع' : 'Spend share unavailable — amounts await a rate or span currencies'}</p>
            ) : (
              <>
                <PlatformDonutChart
                  data={(payload.platforms ?? []).flatMap((p, i) => {
                    const value = platformSpendRank.values[i]
                    // The platform's NAME, not its key: «snapchat» is a database value, «سناب شات» is a platform.
                    return value === null ? [] : [{ name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value, key: canonicalPlatform(p.provider) }]
                  })}
                  currency={platformSpendRank.currency ?? currency}
                  height={220}
                />
                {platformSpendRank.dropped > 0 && (
                  <p className="mt-1 text-center text-[11px] text-text-muted">
                    {ar
                      ? `${platformSpendRank.dropped} منصة غير مُدرجة: مبالغ بانتظار سعر صرف أو بعملات متعددة`
                      : `${platformSpendRank.dropped} platform(s) not included: amounts await a rate or span currencies`}
                  </p>
                )}
              </>
            )}
          </ChartCard>
          )}
        </div>
    </>
  )
}

export function FunnelSection({ payload, ar, currency }: { payload: LivePayload; ar: boolean; currency: string }) {
  return (
    <>
        {(payload.funnel ?? []).length > 0 && (
        <div className="mt-3 grid gap-3" data-testid="live-funnel">
          <ChartCard title={ar ? 'قمع الأداء' : 'Performance funnel'}>
            <ConversionFunnelChart stages={payload.funnel ?? []} currency={currency} ar={ar} />
            {/* FUNNEL-NULL-001 — said once in a sentence as well as drawn. The client has no second
                view of their account to check a gap against, so the gap must explain itself. */}
            {(payload.funnel ?? []).some((s) => !s.reported) && (
              <p className="mt-3 text-xs text-text-muted" data-testid="shared-funnel-unreported">
                {ar
                  ? `لم ترسل أي منصة هذه المراحل في هذه الفترة: ${(payload.funnel ?? []).filter((s) => !s.reported).map((s) => s.label).join('، ')}. الفراغ ليس صفرًا.`
                  : `No platform reported these stages in this period: ${(payload.funnel ?? []).filter((s) => !s.reported).map((s) => s.label).join(', ')}. A gap is not a zero.`}
              </p>
            )}
          </ChartCard>
        </div>
        )}
    </>
  )
}

export function StoreFunnelSection({ payload, ar }: { payload: LivePayload; ar: boolean }) {
  return (
    <>
        {payload.store_funnel && (
          <div data-testid="shared-store-funnel" className="rounded-2xl border border-border bg-surface p-4">
            <h3 className="font-bold text-text-primary">{ar ? 'الفانل والمتجر' : 'Funnel & store'}</h3>
            <ol className="mt-3 space-y-1.5">
              {payload.store_funnel.stages.map((stage) => (
                <li key={stage.key} data-testid={`shared-stage-${stage.key}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-sm">
                  <span className="flex items-center gap-2">
                    <span className="font-semibold text-text-primary">{ar ? stage.label_ar : stage.label_en}</span>
                    <span className="text-[11px] text-text-muted">
                      {stage.source.kind === 'stores'
                        ? (ar ? 'المتجر' : 'Store')
                        : stage.source.kind === 'ad_platforms'
                          ? (ar ? 'بكسل المنصات' : 'Platform pixel')
                          : (ar ? 'لا يوجد مصدر' : 'No source')}
                    </span>
                  </span>
                  <span className="tnum font-extrabold text-text-primary">
                    {stage.value === null
                      ? <span className="text-xs font-semibold text-text-muted">{ar ? 'لا يُقاس' : 'Not measured'}</span>
                      : stage.value.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')}
                  </span>
                </li>
              ))}
            </ol>
            {/*
              * COMMERCE-FX-001 — if an order could not be converted, the revenue above is short and
              * the client is told so. They have no second view of their own account to check against.
              */}
            {payload.store_funnel.coverage.orders_with_money_withheld > 0 && (
              <p data-testid="shared-money-withheld" className="mt-2 text-[11px] text-warning">
                {ar
                  ? `${payload.store_funnel.coverage.orders_with_money_withheld} طلبًا بعملة (${payload.store_funnel.coverage.money_withheld_currencies.join('، ')}) لا يوجد لها سعر صرف مؤرّخ، فلم تُحتسب ضمن الإيرادات أعلاه.`
                  : `${payload.store_funnel.coverage.orders_with_money_withheld} order(s) in ${payload.store_funnel.coverage.money_withheld_currencies.join(', ')} have no dated exchange rate and are NOT included in the revenue above.`}
              </p>
            )}
            {/*
              * COMMERCE-TZ-001 — the client link says which clock its days were measured on. The
              * reader has no second view of their own account to check a boundary against.
              */}
            {(payload.store_funnel.coverage.orders_with_assumed_timezone ?? 0) > 0 && (
              <p data-testid="shared-assumed-timezone" className="mt-2 text-[11px] text-warning">
                {ar
                  ? `${payload.store_funnel.coverage.orders_with_assumed_timezone} طلبًا لم يذكر متجرها المنطقة الزمنية، فاعتُبرت UTC.`
                  : `${payload.store_funnel.coverage.orders_with_assumed_timezone} order(s) come from a store that states no timezone, so UTC was assumed.`}
              </p>
            )}
            <p className="mt-2 text-[11px] text-text-muted">
              {ar ? 'الطلبات في الفترة' : 'Orders in the period'}:{' '}
              {payload.store_funnel.coverage.reporting_timezone && (
                <>
                  <span data-testid="shared-reporting-timezone" className="tnum" dir="ltr">
                    {payload.store_funnel.coverage.reporting_timezone}
                  </span>
                  {' · '}
                </>
              )}
              <span className="tnum">{payload.store_funnel.coverage.orders_in_window}</span>
              {/*
                CLIENT-DIAGNOSTIC-SEPARATION-001 — the store's sync clock left this page.

                «Store last synced: 18 Aug 23:59» is a fact about our plumbing. A client reading
                their own report cannot act on it, cannot ask us to change it, and cannot tell
                whether it means their orders are wrong. The order COUNT and the timezone stay,
                because those are facts about their period; the clock is ours and belongs in the
                operator's Data Quality surface, where it is the whole point.
              */}
            </p>
          </div>
        )}
    </>
  )
}

export function FreshnessStrip({
  freshness,
  ar,
}: {
  freshness: LivePayload['freshness']
  ar: boolean
}) {
  /*
   * CLIENT-DIAGNOSTIC-SEPARATION-001 — what a client can act on, and nothing else.
   *
   * This printed a sync clock per platform — «ميتا: 18 أغسطس 23:59» — and, for an unconnected one,
   * «بانتظار بيانات الاعتماد». Both are facts about US. A client cannot act on the timestamp,
   * cannot ask anyone to change it, and «credentials» is a word from our side of the wall.
   *
   * The fact underneath is theirs and must NOT be lost: a total that silently omits a platform is
   * worse than any diagnostic. So the sentence survives, in their vocabulary — these figures do not
   * include Snapchat — and everything about our plumbing goes. The platform is NAMED rather than
   * keyed, because `snapchat` is a database value and «سناب شات» is a platform they buy on.
   */
  const excluded = freshness.filter((f) => f.state === 'awaiting_credentials')

  if (excluded.length === 0) return null

  const names = excluded
    .map((f) => providerLabel(canonicalPlatform(f.provider), ar ? 'ar' : 'en'))
    .join(ar ? '، ' : ', ')

  return (
    <p
      data-testid="live-freshness"
      className="flex items-start gap-1.5 rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs text-warning"
    >
      <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden />
      <span>
        {ar
          ? `الأرقام في هذا التقرير لا تشمل: ${names}.`
          : `The figures in this report do not include: ${names}.`}
      </span>
    </p>
  )
}

