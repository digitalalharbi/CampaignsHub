import { useMemo } from 'react'
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MetricStrip } from '@/components/ui/MetricStrip'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { useOverviewVm } from '@/features/campaigns/overview/useOverviewVm'
import { Panel, RateTrend, SERIES, tooltipProps } from './components'
import { ChangeDiagnosis } from './ChangeDiagnosis'
import { DiagnosticPanel } from './DiagnosticPanel'
import { DistributionBars } from './DistributionBars'
import { Link } from 'react-router-dom'
import { ArrowUpRight } from 'lucide-react'
import { compact, money, num, ratio } from './format'
import { plotSeries } from './timeseriesMoney'
import { dashboardMetrics } from './metricCatalog'
import { useBudget, useCampaigns, useDrivers, useFreshness, usePlatforms, useSummary, useTimeseries } from './api'
import { useUi } from '@/stores/ui'
import type { CommerceSummary, MetricFilters } from './api'

/**
 * SURFACE-COMPOSITION-001 — one engine, two compositions.
 *
 * `/app/dashboard` and `/app/analytics` mount the same component, and for a while that meant they
 * were the same PAGE: whatever order the overview tab was given, both surfaces got it. #289 gave
 * that shared order the Dashboard's hierarchy — figures, curve, rates, distribution, then the
 * reasoning — and in doing so gave Analytics the Dashboard's hierarchy too, which is the exact
 * failure ANALYTICS-DIFFERENTIATION-001 exists to prevent.
 *
 * The owner's correction draws the line where it belongs: «Shared primitives/data are good. Shared
 * information architecture is NOT.»
 *
 * So everything expensive stays shared — one `useOverviewData` issuing one set of queries, one set
 * of derivations, one `MetricStrip`, one `ChangeDiagnosis`, one chart palette, one formatter set.
 * Nothing here re-fetches, re-derives or re-implements anything. What differs is the ORDER and the
 * emphasis, because the two surfaces answer different questions:
 *
 *   Dashboard  — WHAT IS HAPPENING NOW?   figures first, reasoning last.
 *   Analytics  — WHY DID IT HAPPEN?       the reasoning IS the page; the figures are its evidence.
 *
 * A regression in `primaryKpiHierarchy.test.ts` fails if the two ever render the same ordered major
 * sections again, because "they drifted back together" is precisely how this defect arrived.
 */

export type OverviewInput = {
  projectId: string | null
  range: { from: string; to: string }
  filters: MetricFilters
  objective: string
}

const useAr = () => useUi((u) => u.locale) === 'ar'

/**
 * Every request and every derivation the overview needs, in ONE place.
 *
 * Both compositions call this and neither owns a query, so the Dashboard and Analytics cannot end up
 * describing the same window from different reads — the class of defect that produced two surfaces
 * answering one question differently in the first place.
 */
export function useOverviewData({ projectId, range, filters, objective }: OverviewInput) {
  const ar = useAr()
  const s = useSummary(projectId, range, filters)
  const ts = useTimeseries(projectId, range, filters)
  const campaigns = useCampaigns(projectId, range, filters)
  const platformRows = usePlatforms(projectId, range, filters)
  const freshness = useFreshness(projectId, range, filters)
  const budget = useBudget(projectId, range, filters)

  const reportingCurrency = s.data?.currency ?? null
  const points = ts.data ?? []

  /*
   * ANALYTICS-TRUTH-002 — the charts read the same source the cards read.
   *
   * `points` carries the aggregator's coalesced zeros. Everything plotted below comes from this
   * reading instead, so a line and the card above it cannot disagree about the same window.
   */
  const series = plotSeries(points, reportingCurrency, ar)
  const chartCurrency = series.currency ?? reportingCurrency ?? 'SAR'

  /*
   * ANALYTICS-COMPARE-001 — six mute dashes were the page's way of saying «there is no yesterday».
   *
   * Production holds 15 days of rows and offers a 30-day range, so the entire comparison window sits
   * before the first row that exists. Every delta divided by zero and came back null, and each card
   * printed «— —» beneath a heading promising a comparison — indistinguishable from a month that did
   * not move. When the comparison window is empty the pills are not rendered at all and the page
   * says why once, above the strip.
   */
  const comparable = s.data?.previous_rows_in_scope !== false

  /*
   * ANALYTICS-AS-DASHBOARD-001 — the headline row is chosen BY the objective.
   *
   * Six fixed cards — spend, revenue, ROAS, results, CPA, CTR — answer a sales campaign well and an
   * awareness campaign not at all: they report a return on ad spend for a campaign that was never
   * bought to return anything, and never mention reach or frequency, which is what it WAS bought
   * for. `dashboardMetrics` picks the row the objective is actually judged on.
   *
   * UX-KPI-PRESENTATION-001 — «المؤشر والرقم والشارت»: the same series the chart is drawn from, so
   * each card's sparkline costs no request and cannot contradict the drawing under it.
   */
  const metrics = useMemo(
    () => dashboardMetrics(objective, s.data, ar, ts.data),
    [objective, s.data, ar, ts.data],
  )

  /*
   * ANALYTICS-DIFFERENTIATION-001 — the decomposition and the anomaly timeline, in one request.
   *
   * Both halves share the window AND its comparison, which is why they arrive together: two requests
   * could not be made to agree about which days «the previous period» meant, and a driver list
   * measured against a different baseline than the timeline beside it would be two diagnoses of one
   * account.
   */
  const drivers = useDrivers(projectId, range, 'provider', 'spend', filters)

  /*
   * With no comparison window, a delta is not «unchanged» — it does not exist. `undefined` removes
   * the pill; `null` would still render the «— —» this is here to remove.
   */
  const strip = useMemo(
    () =>
      comparable
        ? metrics
        : {
            primary: metrics.primary.map((m: (typeof metrics.primary)[number]) => ({ ...m, delta: undefined })),
            secondary: metrics.secondary.map((m: (typeof metrics.secondary)[number]) => ({ ...m, delta: undefined })),
          },
    [metrics, comparable],
  )

  const vm = useOverviewVm({
    campaigns: campaigns.data,
    platforms: platformRows.data,
    freshness: freshness.data?.rows,
    budget: budget.data,
    currency: reportingCurrency,
    source: s.data?.provenance?.source,
    ar,
  })

  return { ar, s, ts, objective, reportingCurrency, points, series, chartCurrency, comparable, drivers, strip, vm, campaigns, platformRows }
}

export type OverviewData = ReturnType<typeof useOverviewData>

const axis = { fill: 'var(--text-secondary)', fontSize: 12 }


/**
 * UNIFIED-001 — the connected store, from the funnel's own service.
 *
 * The KPI row above carries `revenue` as the ad platforms report it: a pixel's estimate of what it
 * believes its clicks caused. The shop's ledger is a different and better number, and the product
 * holds both. This strip is labelled as the store's, sits beside the platforms' figures rather than
 * replacing them, and links to the section that explains where each came from.
 *
 * It is on the DASHBOARD only. «ماذا يحدث الآن» is the question a merchant's own ledger answers
 * directly; Analytics has a whole «المتجر» tab that reads the same service in depth.
 *
 * ## Why this moved rather than being deleted
 *
 * It lived in `features/dashboard/DashboardPage.tsx`, which stopped being routed — so the block, and
 * the seven tests holding it, applied to a page nobody could open. Retiring that file without
 * bringing this across would have quietly removed a working section from the product, which is the
 * opposite of what retiring dead code is for. It reads `summary.data.commerce`, already fetched for
 * the strip, so it costs no request.
 */
function StoreLedger({ commerce, ar }: { commerce: CommerceSummary | null; ar: boolean }) {
  if (!commerce) return null

  const cur = commerce.reporting_currency || 'SAR'

  return (
    <div data-testid="dashboard-store" className="rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-lg font-bold text-text-primary">{ar ? 'المتجر المرتبط' : 'Connected store'}</h2>
          <p className="text-[13px] text-text-secondary">
            {ar ? 'من سجل التاجر نفسه — لا من بكسل المنصات.' : 'From the merchant’s own ledger — not the platforms’ pixel.'}
          </p>
        </div>
        <Link to="/app/analytics" className="inline-flex items-center gap-1 text-sm font-semibold text-text-secondary hover:text-text-primary">
          {ar ? 'الفانل والمتجر' : 'Funnel & store'} <ArrowUpRight size={14} aria-hidden />
        </Link>
      </div>

      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {[
          // COMMERCE-FX-001 — the currency the SERVER says these are in, not a constant.
          { key: 'revenue', label: ar ? 'إيرادات المتجر' : 'Store revenue', value: commerce.revenue == null ? '—' : money(commerce.revenue, cur) },
          { key: 'orders', label: ar ? 'الطلبات' : 'Orders', value: num(commerce.orders) },
          { key: 'aov', label: ar ? 'متوسط قيمة الطلب' : 'Average order value', value: commerce.aov == null ? '—' : money(commerce.aov, cur) },
          { key: 'roas', label: ar ? 'العائد على الإنفاق' : 'ROAS', value: commerce.roas == null ? '—' : ratio(commerce.roas, '×') },
        ].map((k) => (
          <div key={k.key} data-testid={`store-kpi-${k.key}`} className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <p className="text-[13px] text-text-secondary">{k.label}</p>
            <p className="tnum mt-0.5 text-xl font-bold text-text-primary">{k.value}</p>
          </div>
        ))}
      </div>

      {/*
        When the rest of the page is narrowed and this block is not, the block says so. An order does
        not belong to a platform the way a click does — a large share carry no attribution at all —
        so these figures cover the whole shop whatever the filter above says.
      */}
      {commerce.filtered_view && (
        <p data-testid="dashboard-store-unfiltered" className="mt-3 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-[13px] text-text-secondary">
          {ar ? commerce.unfiltered_note_ar : commerce.unfiltered_note_en}
        </p>
      )}

      {commerce.unattributed_orders > 0 && (
        <p data-testid="dashboard-store-unattributed" className="mt-3 text-[13px] text-text-secondary">
          {ar
            ? `${num(commerce.unattributed_orders)} من ${num(commerce.orders)} طلبًا وصلت بلا إسناد لأي حملة.`
            : `${num(commerce.unattributed_orders)} of ${num(commerce.orders)} orders arrived with no campaign attribution.`}
        </p>
      )}

      {/* COMMERCE-TZ-001 — an order whose store states no timezone may belong to the day either
          side of where it is counted, and the reader is told rather than left to assume. */}
      {(commerce.orders_with_assumed_timezone ?? 0) > 0 && (
        <p data-testid="dashboard-store-assumed-tz" className="mt-2 text-[13px] text-warning">
          {ar
            ? `${num(commerce.orders_with_assumed_timezone ?? 0)} طلبًا لم يذكر متجرها المنطقة الزمنية، فاعتُبرت UTC.`
            : `${num(commerce.orders_with_assumed_timezone ?? 0)} order(s) come from a store that states no timezone, so UTC was assumed.`}
        </p>
      )}

      {/* The revenue above is SHORT by these orders, and a short total must never look whole. */}
      {(commerce.orders_with_money_withheld ?? 0) > 0 && (
        <p data-testid="dashboard-store-withheld" className="mt-2 text-[13px] text-warning">
          {ar
            ? `${num(commerce.orders_with_money_withheld ?? 0)} طلبًا بعملة (${(commerce.money_withheld_currencies ?? []).join('، ')}) لا يوجد لها سعر صرف مؤرّخ، فلم تُحتسب ضمن الإيرادات.`
            : `${num(commerce.orders_with_money_withheld ?? 0)} order(s) in ${(commerce.money_withheld_currencies ?? []).join(', ')} have no dated exchange rate and are not included in the revenue.`}
        </p>
      )}
    </div>
  )
}

/**
 * DASHBOARD — «ماذا يحدث الآن؟»
 *
 * The order is the owner's, stated twice and unchanged by the split: primary KPI cards, the main
 * performance trend, the rate trends, the platform and campaign distribution, the secondary
 * weakness line, and the diagnosis LAST.
 *
 * «قوم بازالة البيانات هذه من لوحة التحكم نظرة عامة او اجعلها اخر نظرة عامة بالاسفل لان بالاساس
 * بالنظام الشارت والرسوم التفاعلية.» Nothing analytical may be rendered above the primary KPI
 * region, and nothing that DRAWS may be rendered below the first block that EXPLAINS.
 */
export function DashboardOverview(d: OverviewData) {
  const { ar, s, ts, series, chartCurrency, comparable, drivers, strip, vm, points, objective, reportingCurrency } = d

  return (
    <div className="space-y-4" data-testid="dashboard-overview" data-composition="dashboard">
        {!comparable && s.data && (
          <p
            data-testid="no-comparison-period"
            className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
          >
            <span className="font-semibold text-text-primary">{ar ? 'لا توجد مقارنة: ' : 'No comparison: '}</span>
            {ar
              ? `الفترة السابقة (${s.data.previous_range.from} → ${s.data.previous_range.to}) لا تحتوي أي بيانات، فلا يوجد شيء تُقاس عليه هذه الفترة.`
              : `The previous period (${s.data.previous_range.from} → ${s.data.previous_range.to}) holds no data, so there is nothing for this one to be measured against.`}
          </p>
        )}

        <MetricStrip
          id="dashboard"
          ar={ar}
          primary={strip.primary}
          secondary={strip.secondary}
          hasRows={s.data === undefined ? undefined : s.data.rows_in_scope}
          /*
            METRICS-REQUEST-STATE-001 — and a request that failed or has not answered says so.

            `data` is undefined for a failure and for a load alike, so without these the row rendered with
            nothing to read and every card printed «لا توجد بيانات» — a confident statement about this
            account's advertising, made by a request that never came back.
          */
          loading={s.isPending}
          error={s.isError ? s.error : undefined}
          onRetry={() => void s.refetch()}
        />
        {/*
         * REPORT-OBJECTIVE-005 — «النتائج» above is the SUM of what each platform claimed.
         *
         * One sale clicked from two platforms is reported in full by both, and there is no shared key
         * that would prove they are the same sale — so the figure is not a count of unique orders, and
         * it must not be read as one. The sentence comes from the API rather than being written here, so
         * the dashboard, the report and the client's link cannot end up saying different things about
         * the same number. Shown only when more than one platform contributed: a single platform cannot
         * overlap with itself, and a warning about an impossible risk trains readers to ignore warnings.
         */}
        {s.data?.conversions_basis?.may_double_count && (
          <p
            data-testid="conversions-basis"
            className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
          >
            <span className="font-semibold text-text-primary">{ar ? '«النتائج»: ' : 'Results: '}</span>
            {ar ? s.data.conversions_basis?.note_ar : s.data.conversions_basis?.note_en}
          </p>
        )}
        {/*
          ANALYTICS-TRUTH-002 — the chart the KPI strip contradicted.

          It plotted `dataKey="spend"` off the raw row and withdrew both money lines whenever the
          window's money was withheld, leaving a single «النتائج» line under a title naming three. The
          money was never missing — it was unconverted, and the card above already stated it. Both
          lines are drawn from the same reading, in whatever currency that reading is honestly in, and
          the axis says which.
        */}
        <Panel
          title={ar ? 'الإنفاق والنتائج والإيرادات' : 'Spend, results and revenue'}
          description={
            series.basis === 'original'
              ? ar
                ? `المال معروض بعملة المنصة (${series.currency}) — ${series.note ?? ''}`
                : `Money shown in the platform's own currency (${series.currency}) — ${series.note ?? ''}`
              : ar
                ? 'الاتجاه اليومي للإنفاق والإيرادات والنتائج'
                : 'Spend, revenue and results, day by day'
          }
          loading={ts.isLoading}
          error={ts.isError}
          empty={!ts.isLoading && series.rows.length === 0}
        >
          <div className="h-80">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={series.rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
                {/*
                  Money and counts are different units, so they get different axes. Drawing 4,787 USD
                  and 218 results against one scale flattens the smaller series onto the floor — which
                  is the shape the old chart had even before the money went missing.
                */}
                <YAxis yAxisId="money" tick={axis} tickFormatter={(v) => compact(Number(v))} width={52} />
                <YAxis yAxisId="count" orientation={ar ? 'left' : 'right'} tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                <Tooltip
                  {...tooltipProps}
                  formatter={(v: number, name: string) =>
                    name === (ar ? 'النتائج' : 'Results') ? num(v) : money(v, chartCurrency)
                  }
                />
                <Legend wrapperStyle={{ fontSize: 13 }} />
                {series.hasMoney && series.basis !== 'mixed' && (
                  <Line yAxisId="money" name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} dot={false} connectNulls />
                )}
                {series.hasMoney && series.basis !== 'mixed' && (
                  <Line yAxisId="money" name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} dot={false} connectNulls />
                )}
                <Line yAxisId="count" name={ar ? 'النتائج' : 'Results'} type="monotone" dataKey="conversions" stroke={SERIES.conversions} strokeWidth={2} dot={false} connectNulls />
              </LineChart>
            </ResponsiveContainer>
          </div>
          {series.basis === 'mixed' && (
            <p data-testid="series-currency-mixed" className="mt-2 text-xs text-text-secondary">{series.note}</p>
          )}
        </Panel>

        {/*
          Three metrics, three units — «3.20x», «21.96 USD» and «0.72%» share no axis. On one scale the
          two small numbers lie flat on the floor and the chart says nothing, which is what shipped:
          a single line at zero under a title naming three metrics, one of which was never plotted.

          Each gets its own panel and its own scale, so each is readable.
        */}
        <div className="grid gap-3 lg:grid-cols-3">
          <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} />
          <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} />
          <RateTrend title="CTR" data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} />
        </div>

        {/* The comparisons, the details and the alerts — «أ», shared with the marketing preview. */}
        <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} />

        <StoreLedger commerce={s.data?.commerce ?? null} ar={ar} />

        {/*
         * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — kept, and kept BELOW everything that draws.
         *
         * It used to sit directly beneath the strip, on the argument that a diagnosis belongs next to
         * the figures that raise the question. The owner's correction retires that argument for this
         * surface: «النظام مبني على الصورة الرئيسية للبيانات — البطاقات، وتحته يأتي الشارت والجانب
         * الابداعي»، وهذه الكتل «اجعلها اخر نظرة عامة بالاسفل». So the order of the Overview is the
         * figures, then the curve, then the rates, then the platform split — and only after all of
         * that, the reading of why.
         *
         * Nothing about WHAT it reads changed: the same `useSummary` entry the strip read, the same
         * totals and the same `reported` map, so the two still cannot disagree about the account they
         * are both describing. The request state is forwarded rather than re-derived for the same
         * reason.
         */}
        <DiagnosticPanel
          objective={objective}
          totals={s.data?.current as Record<string, number | null | undefined> | undefined}
          reported={s.data?.reported}
          rowsInScope={s.data?.rows_in_scope}
          loading={s.isPending}
          error={s.isError ? s.error : undefined}
          onRetry={() => void s.refetch()}
          ar={ar}
        />
        {/*
          DASHBOARD-HIERARCHY — the diagnosis sits BELOW the KPI row, and the note here used to argue
          the opposite.

          It reasoned that a reader who opens Analytics has already seen «what is happening», so
          leading with «what changed and who moved it» was the point of the page. The owner's
          correction overrides that: «never insert diagnostic cards, change-driver cards,
          recommendation cards, alerts or explanatory cards ABOVE the primary KPI row — the first thing
          the user sees must remain the campaign performance indicators.»

          Analytics stays materially different from the Dashboard, but through what the requirement
          actually asks for — a mixed analytical grid, chart types chosen per question, decomposition,
          distribution and evidence — rather than by pushing the figures down the page. The strip is
          the same totals in the same window as the diagnosis beneath it, so the two still cannot
          disagree about the account they describe.

          Then a second correction moved it further down still, to the LAST block on the Overview:
          «قوم بازالة البيانات هذه من لوحة التحكم نظرة عامة او اجعلها اخر نظرة عامة بالاسفل لان
          بالاساس بالنظام الشارت والرسوم التفاعلية». Below the KPI row was not enough while the curve,
          the rate trends and the platform split all sat underneath it — the reader met a paragraph of
          reasoning before the drawings the product is built on. Nothing was deleted: the diagnosis is
          the same block with the same evidence, read after the picture rather than instead of it.
        */}
        <ChangeDiagnosis
          data={drivers.data}
          currency={reportingCurrency}
          loading={drivers.isPending}
          error={drivers.isError}
          // The same series the graph below this block draws, so a marked day sits on the curve the
          // reader is already looking at rather than on a second one fetched for the purpose.
          series={points}
        />
    </div>
  )
}

/**
 * ANALYTICS — «لماذا حدث؟ أين المشكلة؟ أين تقع الفرصة؟ ما الدليل؟»
 *
 * Deliberately NOT the Dashboard's order. «Do NOT force Analytics into the Dashboard order …
 * Analytics should open with analytical context and decision modules, not reproduce KPI row →
 * dashboard charts → diagnosis at the bottom.»
 *
 * So it opens on the decision modules: where along the journey the weakness is, and where the money
 * is actually concentrated — two questions the Dashboard never asks first. The rate trends follow as
 * decision visuals rather than as a headline, and the figures come after, framed as the evidence the
 * reading above was drawn from rather than as the answer.
 *
 * ## Why «ما الذي تغيّر» is still LAST here
 *
 * Because the owner placed it: «هذي تكون اعلى كل تصنيف في لوحة التحكم والتحليلات باستثناء على نظرة
 * عامة تكون بمكانها الحالي بالاسفل» — the change decomposition leads every OTHER tab, and on
 * «نظرة عامة» it keeps the position it has now, at the bottom. That instruction is about ONE block
 * and it applies to both surfaces; it is not a statement that the two overviews should be the same
 * page, and they are not.
 *
 * The strip is not deleted here and must not be: a diagnosis a reader cannot check against the
 * totals it was computed from is an assertion. It is the same strip, the same totals and the same
 * window as the Dashboard's — placed where evidence belongs on a page that argues, rather than where
 * a headline belongs on a page that reports.
 *
 * The deep tabs — objectives, platforms, accounts, campaigns, ad sets, ads, content, budget, funnel,
 * store, attribution and data quality — are the rest of this system and are unchanged; this is only
 * the door into it.
 */
export function AnalyticsOverview(d: OverviewData) {
  const { ar, s, ts, series, chartCurrency, comparable, drivers, strip, vm, points } = d

  return (
    <div className="space-y-4" data-testid="analytics-overview" data-composition="analytics">
      {/*
        WHERE the weakness is — read on the stages of the journey, never asserted without the
        measurement behind it. First, because that is the question this surface exists to answer.
        Same `useSummary` entry the strip below reads, so the diagnosis and its evidence cannot
        disagree about the account they describe.
      */}
      <DiagnosticPanel
        objective={d.objective}
        totals={s.data?.current as Record<string, number | null | undefined> | undefined}
        reported={s.data?.reported}
        rowsInScope={s.data?.rows_in_scope}
        loading={s.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
        ar={ar}
      />

      {/*
        Then WHERE THE MONEY SITS. «What moved» and «what carries the weight» are different
        questions, and a stable account can still hold most of its budget behind one platform.
        Declines rather than inventing whenever a row's spend is withheld, and needs two rows to
        have a distribution at all.
      */}
      <DistributionBars
        testid="platform-distribution"
        title={ar ? 'أين يقع الإنفاق' : 'Where the spend sits'}
        currency={d.reportingCurrency}
        ar={ar}
        rows={(d.platformRows.data ?? []).map((r) => ({ key: r.provider, label: r.provider, totals: r }))}
      />

      {/*
        The rate trends as DECISION visuals: three metrics, three units, three scales. ROAS, CPA and
        CTR are what an operator acts on, so on this surface they sit with the reasoning rather than
        beneath a headline.
      */}
      <div className="grid gap-3 lg:grid-cols-3">
        <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} />
        <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} />
        <RateTrend title="CTR" data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} />
      </div>

      {/*
        The evidence: the totals the reading above was computed from, and the curve those days were
        measured on. Labelled as such — a reader who wants the headline figures has a Dashboard, and
        a reader who is here is checking an argument.
      */}
      <section data-testid="analytics-evidence" className="space-y-4">
        <h2 className="text-sm font-bold text-text-primary">
          {ar ? 'الأرقام التي بُني عليها ما سبق' : 'The figures the reading above was drawn from'}
        </h2>

        {!comparable && s.data && (
          <p
            data-testid="no-comparison-period"
            className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
          >
            <span className="font-semibold text-text-primary">{ar ? 'لا توجد مقارنة: ' : 'No comparison: '}</span>
            {ar
              ? `الفترة السابقة (${s.data.previous_range.from} → ${s.data.previous_range.to}) لا تحتوي أي بيانات، فلا يوجد شيء تُقاس عليه هذه الفترة.`
              : `The previous period (${s.data.previous_range.from} → ${s.data.previous_range.to}) holds no data, so there is nothing for this one to be measured against.`}
          </p>
        )}

        <MetricStrip
          id="analytics"
          ar={ar}
          primary={strip.primary}
          secondary={strip.secondary}
          hasRows={s.data === undefined ? undefined : s.data.rows_in_scope}
          loading={s.isPending}
          error={s.isError ? s.error : undefined}
          onRetry={() => void s.refetch()}
        />

        {s.data?.conversions_basis?.may_double_count && (
          <p
            data-testid="conversions-basis"
            className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
          >
            <span className="font-semibold text-text-primary">{ar ? '«النتائج»: ' : 'Results: '}</span>
            {ar ? s.data.conversions_basis?.note_ar : s.data.conversions_basis?.note_en}
          </p>
        )}

        <Panel
          title={ar ? 'الإنفاق والنتائج والإيرادات' : 'Spend, results and revenue'}
          description={
            series.basis === 'original'
              ? ar
                ? `المال معروض بعملة المنصة (${series.currency}) — ${series.note ?? ''}`
                : `Money shown in the platform's own currency (${series.currency}) — ${series.note ?? ''}`
              : ar
                ? 'الاتجاه اليومي للإنفاق والإيرادات والنتائج'
                : 'Spend, revenue and results, day by day'
          }
          loading={ts.isLoading}
          error={ts.isError}
          empty={!ts.isLoading && series.rows.length === 0}
        >
          <div className="h-80">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={series.rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
                <YAxis yAxisId="money" tick={axis} tickFormatter={(v) => compact(Number(v))} width={52} />
                <YAxis yAxisId="count" orientation={ar ? 'left' : 'right'} tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                <Tooltip
                  {...tooltipProps}
                  formatter={(v: number, name: string) =>
                    name === (ar ? 'النتائج' : 'Results') ? num(v) : money(v, chartCurrency)
                  }
                />
                <Legend wrapperStyle={{ fontSize: 13 }} />
                {series.hasMoney && series.basis !== 'mixed' && (
                  <Line yAxisId="money" name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} dot={false} connectNulls />
                )}
                {series.hasMoney && series.basis !== 'mixed' && (
                  <Line yAxisId="money" name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} dot={false} connectNulls />
                )}
                <Line yAxisId="count" name={ar ? 'النتائج' : 'Results'} type="monotone" dataKey="conversions" stroke={SERIES.conversions} strokeWidth={2} dot={false} connectNulls />
              </LineChart>
            </ResponsiveContainer>
          </div>
          {series.basis === 'mixed' && (
            <p data-testid="series-currency-mixed" className="mt-2 text-xs text-text-secondary">{series.note}</p>
          )}
        </Panel>

        {/* The comparisons, the details and the alerts — «أ», shared with the marketing preview. */}
        <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} />
      </section>

      {/*
        «باستثناء على نظرة عامة تكون بمكانها الحالي بالاسفل» — the change decomposition leads every
        other tab and keeps this position here, on both surfaces.
      */}
      <ChangeDiagnosis
        data={drivers.data}
        currency={d.reportingCurrency ?? 'SAR'}
        loading={drivers.isPending}
        error={drivers.isError}
        series={points}
      />
    </div>
  )
}
