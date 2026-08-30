import { useMemo, useState } from 'react'
import { TrendingDown, TrendingUp } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import {
  useCampaignActivity,
  useCampaignCreatives,
  useCampaignAlerts,
  useCampaignAnnotations,
  useCampaignFunnel,
  useCampaignPerformance,
  useCampaignPlatforms,
  useCampaignReports,
  useCampaignSummary,
  useCreateAnnotation,
  useUpdateAnnotation,
} from './metrics'
import type { CampaignCreative } from './metrics'
import type { MetricTotals, PlatformRow, Range, TimePoint } from '@/features/analytics/api'
import { ChartCard, ConversionFunnelChart, KpiSparkline, MetricLineChart, PlatformDonutChart, ProgressRing, SpendRevenueAreaChart } from '@/features/analytics/charts'
/*
 * PERCENT-100X-001 — `percent()` multiplies by 100. This file used to as well.
 *
 * Every rate in the command centre was written `percent(x * 100)`, and the helper's own body is
 * `${(n * 100).toFixed(d)}%` — so every ratio was multiplied twice and printed a hundredfold. Live,
 * on a real campaign: CTR **210.0%**, معدل التحويل **479.6%**, استهلاك الميزانية **3028%**, and a
 * funnel step of **210%**. The true figures are 2.1%, 4.8%, 30% and 2.1%.
 *
 * These are not rounding artefacts, they are impossible statements — more clicks than impressions,
 * a budget spent thirty times over — on the page an agency reads before it talks to its client. It
 * survived because nothing asserted a rendered percentage and because the analytics page, which is
 * correct (`percent(cur?.ctr, 2)`), is where anyone would have looked to check.
 *
 * The ratio goes in raw. If a figure ever arrives already scaled to 0–100, convert it at the source
 * rather than reintroducing a second multiplication here.
 */
import { compact, money, moneyFromTotals, num, percent, ratio, rowCostPer, rowMoney, rowRoas, trend } from '@/features/analytics/format'
import { rankableMoney, readRoas, resolveMoneySeries, spendComparableAmount, type MoneyTotals } from '@/lib/money/contract'
import { fmtDate, fmtDateTime } from '@/lib/datetime'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { providerLabel } from './labels'
import { AdPoster } from '@/features/content/AdPoster'
import type { Locale } from '@/stores/ui'
import { StatCard } from '@/components/ui/StatCard'

type Sparkable = keyof MetricTotals

/** Objective → the primary cost metric the client cares about (CPA vs CPL). */
function costLabel(objective: string): string {
  return objective === 'leads' ? 'CPL' : 'CPA'
}

/**
 * PARTIAL-WITHHELD-001 — spend as ONE figure in the budget's currency, or null.
 *
 * Budget-vs-spend derivations (remaining, utilization, pacing, forecast, budget-risk) need a single
 * spend total in the same currency as the budget. This delegates to the money contract rather than
 * re-deciding comparability here: a converted total is in the project's REPORTING currency, which is
 * not necessarily the campaign's budget currency, so the reporting currency has to be supplied and
 * checked. Assuming the two match is how «المتبقي» came to be a riyal budget minus a dollar spend.
 */
function spendInBudgetCurrency(
  totals: MoneyTotals | undefined,
  budgetCurrency: string,
  reportingCurrency: string | null,
): number | null {
  return spendComparableAmount(totals, 'spend', reportingCurrency, budgetCurrency)
}

function deltaTone(key: Sparkable, delta: number | null | undefined): 'up' | 'down' | 'flat' {
  const t = trend(delta)
  // For cost metrics lower is better, so invert the visual sentiment.
  const invert = key === 'cpa' || key === 'cpc' || key === 'cpm'
  if (t === 'flat') return 'flat'
  return invert ? (t === 'up' ? 'down' : 'up') : t
}

/**
 * UX-KPI-PRESENTATION-001 — the shared card, with this surface's delta and spark passed in.
 *
 * The campaign centre drew its own: an 11px uppercase label, a `text-lg` figure, `p-3`. Beside the
 * dashboard's cards — 13px label, 24px figure, `p-4` — the same programme's numbers looked like two
 * products, and the only thing this card actually needed that the shared one lacked was somewhere to
 * put a sparkline. That is now a slot on `StatCard`, so the copy is gone rather than reconciled.
 */
function KpiCard({
  label, value, sub, delta, deltaKey, spark,
}: {
  label: string; value: string; sub?: string; delta?: number | null; deltaKey?: Sparkable; spark?: number[]
}) {
  const tone = deltaKey ? deltaTone(deltaKey, delta) : trend(delta)
  const toneClass = tone === 'up' ? 'text-success' : tone === 'down' ? 'text-danger' : 'text-text-muted'

  return (
    <StatCard
      label={label}
      value={value}
      hint={sub}
      trailing={
        delta != null ? (
          <span dir="ltr" className={`inline-flex items-center gap-0.5 text-[11px] font-semibold ${toneClass}`}>
            {tone === 'up' ? <TrendingUp size={11} /> : tone === 'down' ? <TrendingDown size={11} /> : null}
            {percent(Math.abs(delta), 0)}
          </span>
        ) : undefined
      }
      spark={spark && spark.length > 1 ? <KpiSparkline points={spark} height={26} /> : undefined}
    />
  )
}

function sparks(series: TimePoint[] | undefined, key: Sparkable): number[] {
  return (series ?? []).map((p) => Number(p[key] ?? 0))
}

/** CMC-2 — KPI grid for the campaign, objective-aware, with previous-period deltas + sparklines. */
export function CampaignKpis({ campaign, projectId, range }: { campaign: UnifiedCampaign; projectId: string; range: Range }) {
  const summary = useCampaignSummary(projectId, campaign.id, range)
  const perf = useCampaignPerformance(projectId, campaign.id, range)

  const k = summary.data?.current
  const d = summary.data?.delta ?? {}
  const budget = campaign.total_budget ?? null
  const convRate = k && k.clicks > 0 ? k.conversions / k.clicks : null
  const cur = campaign.budget_currency || 'SAR'

  // PARTIAL-WITHHELD-001 — المتبقي/الاستهلاك يقارنان المصروف بالميزانية، فيلزمهما رقم مصروف
  // واحد بعملة الميزانية. سياق جزئي/مختلط، أو مصروف محتجَز بعملة أخرى، لا يوفّره ⇒ كلاهما غير
  // متاح (لا «الميزانية − الجزء المحوَّل» ولا «الميزانية − صفر»).
  const spendVsBudget = spendInBudgetCurrency(k, cur, summary.data?.currency ?? null)
  const remaining = budget != null && spendVsBudget != null ? budget - spendVsBudget : null
  const utilization = budget && budget > 0 && spendVsBudget != null ? spendVsBudget / budget : null

  /*
   * MONEY-TRUTH-003 — the same contract the dashboard and Analytics use.
   *
   * `k` is `MetricsAggregator::totals()`, which coalesces a withheld sum to 0. Read raw, this card
   * showed «المصروف 0» over money the platform really spent, and CPA/CPC/CPM divided that same zero.
   *
   * BUDGET and المتبقي are deliberately NOT read through this: a campaign budget is set by the
   * advertiser in the campaign's own currency and is never withheld. Routing it through a
   * provider-money contract would invent a provenance question it does not have.
   */
  const spendRead = moneyFromTotals(k, 'spend', true, cur)
  const revenueRead = moneyFromTotals(k, 'revenue', true, cur)
  const roasRead = readRoas(k, true)

  if (summary.isLoading) return <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">{Array.from({ length: 12 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>

  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
      <KpiCard label="الميزانية" value={budget != null ? money(budget, cur) : '—'} />
      <KpiCard label="المصروف" value={spendRead.text} sub={spendRead.note ?? undefined} delta={spendRead.withheld ? null : d.spend} deltaKey="spend" spark={spendRead.withheld ? undefined : sparks(perf.data, 'spend')} />
      <KpiCard label="المتبقي" value={remaining != null ? money(remaining, cur) : '—'} sub={utilization != null ? `استهلاك ${percent(utilization, 0)}` : undefined} />
      <KpiCard label="النتائج" value={num(k?.conversions)} delta={d.conversions} deltaKey="conversions" spark={sparks(perf.data, 'conversions')} />
      <KpiCard label={costLabel(campaign.objective)} value={rowCostPer(k, 'cpa', 'conversions', cur)} delta={spendRead.withheld ? null : d.cpa} deltaKey="cpa" spark={spendRead.withheld ? undefined : sparks(perf.data, 'cpa')} />
      <KpiCard label="الإيرادات" value={revenueRead.text} sub={revenueRead.note ?? undefined} delta={revenueRead.withheld ? null : d.revenue} deltaKey="revenue" spark={revenueRead.withheld ? undefined : sparks(perf.data, 'revenue')} />
      <KpiCard label="ROAS" value={roasRead.value === null ? '—' : ratio(roasRead.value)} sub={roasRead.note ?? undefined} delta={roasRead.kind === 'converted' || roasRead.kind === 'zero' ? d.roas : null} deltaKey="roas" spark={roasRead.kind === 'converted' ? sparks(perf.data, 'roas') : undefined} />
      <KpiCard label="CTR" value={percent(k?.ctr ?? 0)} delta={d.ctr} deltaKey="ctr" spark={sparks(perf.data, 'ctr')} />
      <KpiCard label="CPC" value={rowCostPer(k, 'cpc', 'clicks', cur)} delta={spendRead.withheld ? null : d.cpc} deltaKey="cpc" />
      {/* CPM divides by impressions per THOUSAND. The factor lives here, visible, rather than in a
          generic reader — and rather than as a field name no payload carries. */}
      <KpiCard label="CPM" value={rowCostPer(k, 'cpm', (k?.impressions ?? 0) / 1000, cur)} delta={spendRead.withheld ? null : d.cpm} deltaKey="cpm" />
      <KpiCard label="معدل التحويل" value={convRate != null ? percent(convRate) : '—'} />
      <KpiCard label="مرات الظهور" value={compact(k?.impressions ?? 0)} delta={d.impressions} deltaKey="impressions" />
    </div>
  )
}

/** CMC-2 — Executive summary derived from the campaign's own snapshot (best platform, risk, next step). */
export function CampaignExecutiveSummary({ campaign, projectId, range, locale }: { campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale }) {
  const summary = useCampaignSummary(projectId, campaign.id, range)
  const platforms = useCampaignPlatforms(projectId, campaign.id, range)

  const insight = useMemo(() => {
    const k = summary.data?.current
    const plats = platforms.data ?? []
    const byRoas = [...plats].filter((p) => p.roas != null).sort((a, b) => (b.roas ?? 0) - (a.roas ?? 0))
    const budget = campaign.total_budget ?? null
    // PARTIAL-WITHHELD-001 — budget risk must not read a partial/withheld spend as a low utilization.
    // No single spend figure in the budget currency ⇒ util is null and the «almost exhausted» claim
    // simply cannot fire, rather than firing wrong or staying silent because a subset looked small.
    const spendVsBudget = spendInBudgetCurrency(k as MoneyTotals | undefined, campaign.budget_currency || 'SAR', summary.data?.currency ?? null)
    const util = budget && budget > 0 && spendVsBudget != null ? spendVsBudget / budget : null
    return {
      topResult: k ? `${num(k.conversions)} نتيجة · ${ratio(k.roas)} ROAS` : '—',
      bestPlatform: byRoas[0] ? `${providerLabel(byRoas[0].provider, locale)} (${ratio(byRoas[0].roas)})` : '—',
      opportunity: byRoas[0] ? `توسيع ${providerLabel(byRoas[0].provider, locale)} — أعلى عائد` : '—',
      risk: util != null && util > 0.95 ? 'الميزانية شارفت على النفاد' : (k && k.conversions === 0 ? 'لا نتائج في الفترة' : 'ضمن الحدود'),
      nextStep: byRoas[0] ? `إعادة توزيع الميزانية نحو ${providerLabel(byRoas[0].provider, locale)}` : 'مراجعة الاستهداف',
    }
  }, [summary.data, platforms.data, campaign.total_budget, campaign.budget_currency, locale])

  const Item = ({ label, value }: { label: string; value: string }) => (
    <div className="flex flex-col gap-0.5 rounded-lg border border-border bg-surface-secondary p-2.5">
      <span className="text-[11px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className="text-sm font-semibold text-text-primary">{value}</span>
    </div>
  )

  return (
    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
      <Item label="أهم نتيجة" value={insight.topResult} />
      <Item label="أفضل منصة" value={insight.bestPlatform} />
      <Item label="أكبر فرصة" value={insight.opportunity} />
      <Item label="أكبر خطر" value={insight.risk} />
      <Item label="الخطوة القادمة" value={insight.nextStep} />
    </div>
  )
}

/** CMC-6 — Performance tab (Analytics-grade trends + platform contribution) for the campaign. */
export function CampaignPerformanceTab({ campaign, projectId, range, locale }: { campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale }) {
  const perf = useCampaignPerformance(projectId, campaign.id, range)
  const platforms = useCampaignPlatforms(projectId, campaign.id, range)
  const cur = campaign.budget_currency || 'SAR'
  const series = perf.data ?? []
  // PARTIAL-WITHHELD-001 (d/f) — the spend/revenue trend plots EFFECTIVE money in one currency, or
  // «—»: a trend cannot drop a withheld/partial day the way the donut drops a platform.
  const moneySeries = resolveMoneySeries(series as unknown as Array<Record<string, unknown>>, ['spend', 'revenue'], cur)

  const bestWorst = useMemo(() => {
    const withRev = series.filter((p) => p.revenue != null)
    if (withRev.length === 0) return null
    const sorted = [...withRev].sort((a, b) => (b.revenue ?? 0) - (a.revenue ?? 0))
    return { best: sorted[0], worst: sorted[sorted.length - 1] }
  }, [series])

  // PARTIAL-WITHHELD-001 — spend share per platform, only when comparable in one currency (else null).
  const platformDonutRank = rankableMoney((platforms.data ?? []) as MoneyTotals[], 'spend', cur)
  /*
   * A donut drops and discloses; only a TOTAL fails closed (PARTIAL-WITHHELD-001). A platform with no
   * comparable magnitude is left off and counted, so the platforms that ARE known still draw.
   */
  const platformDonut = platformDonutRank === null
    ? null
    : {
        data: (platforms.data ?? []).flatMap((p, i) => {
          const value = platformDonutRank.values[i]
          return value === null ? [] : [{ name: providerLabel(p.provider, locale), value }]
        }),
        dropped: platformDonutRank.dropped,
      }

  if (perf.isLoading) return <div className="space-y-4"><Skeleton className="h-[240px]" /><div className="grid gap-4 lg:grid-cols-2"><Skeleton className="h-[200px]" /><Skeleton className="h-[200px]" /></div></div>

  /*
   * Every heading on this tab was hard-coded Arabic.
   *
   * `CampaignsPage` renders the SAME chart two files away and localises it properly, which is how
   * this survived: the project-level view reads correctly in English, so nobody following a link
   * from it would notice that the campaign-level one does not. An English-speaking media buyer
   * opening a campaign's Performance tab met «الإنفاق مقابل الإيرادات» over a chart of their money.
   *
   * Found while reading this file for an unrelated reason — the E2E spec asserts the Arabic string
   * AFTER switching the interface to English, and it passed for four gates precisely because the
   * component never translated.
   */
  const ar = locale === 'ar'

  return (
    <div className="space-y-4">
      <ChartCard title={ar ? 'الإنفاق مقابل الإيرادات' : 'Spend vs revenue'} subtitle={ar ? 'الاتجاه اليومي لهذه الحملة' : 'This campaign, day by day'}>
        {moneySeries === null
          ? <div className="flex h-[240px] items-center justify-center text-center text-sm text-text-muted">{ar ? 'الإنفاق/الإيراد عبر الزمن غير متاح — مبالغ بانتظار سعر صرف أو بعملات متعددة' : 'Spend/revenue over time unavailable — amounts await a rate or span currencies'}</div>
          : <SpendRevenueAreaChart data={moneySeries.rows} height={240} currency={moneySeries.currency ?? cur} />}
      </ChartCard>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title={ar ? 'النتائج' : 'Results'} subtitle={ar ? 'الاتجاه اليومي' : 'Day by day'}>
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'conversions', name: ar ? 'النتائج' : 'Results' }]} height={190} />
        </ChartCard>
        <ChartCard title="ROAS" subtitle={ar ? 'العائد على الإنفاق' : 'Return on ad spend'}>
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'roas', name: 'ROAS' }]} height={190} />
        </ChartCard>
        <ChartCard title="CPA / CPC" subtitle={ar ? 'التكلفة' : 'Cost'}>
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'cpa', name: 'CPA' }, { key: 'cpc', name: 'CPC' }]} height={190} />
        </ChartCard>
        <ChartCard title={ar ? 'مساهمة المنصات' : 'Platform contribution'} subtitle={ar ? 'حسب الإنفاق' : 'By spend'}>
          {platformDonut === null
            ? <div className="flex h-[190px] items-center justify-center text-center text-sm text-text-muted">{ar ? 'توزيع الإنفاق غير متاح — مبالغ جزئية أو بعملات متعددة' : 'Spend share unavailable — partial or multi-currency'}</div>
            : platformDonut.data.length
              ? <>
                  <PlatformDonutChart data={platformDonut.data} centerLabel={ar ? 'الإنفاق' : 'Spend'} centerValue={compact(platformDonut.data.reduce((a, b) => a + b.value, 0))} height={190} />
                  {platformDonut.dropped > 0 && <p className="mt-1 text-center text-[11px] text-text-muted">{ar ? `${platformDonut.dropped} منصة غير مُدرجة — مبالغ جزئية أو بعملات متعددة` : `${platformDonut.dropped} platform(s) not included — partial or multi-currency`}</p>}
                </>
              : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">{ar ? 'لا بيانات منصات' : 'No platform data'}</div>}
        </ChartCard>
      </div>
      {bestWorst && (
        <div className="grid grid-cols-2 gap-3">
          {/* Daily revenue carries the same withholding as the total it sums into. */}
          <div className="rounded-xl border border-border bg-surface p-3"><span className="text-[11px] uppercase text-text-muted">أفضل يوم</span><div className="tnum text-sm font-bold">{bestWorst.best.date} · {rowMoney(bestWorst.best as never, 'revenue', cur)}</div></div>
          <div className="rounded-xl border border-border bg-surface p-3"><span className="text-[11px] uppercase text-text-muted">أضعف يوم</span><div className="tnum text-sm font-bold">{bestWorst.worst.date} · {rowMoney(bestWorst.worst as never, 'revenue', cur)}</div></div>
        </div>
      )}
    </div>
  )
}

/** CMC-9 — Budget pacing for the campaign: ring, planned-vs-actual, forecast, platform allocation. */
export function CampaignBudgetTab({ campaign, projectId, range, locale }: { campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale }) {
  const summary = useCampaignSummary(projectId, campaign.id, range)
  const platforms = useCampaignPlatforms(projectId, campaign.id, range)
  const perf = useCampaignPerformance(projectId, campaign.id, range)
  const activity = useCampaignActivity(projectId, campaign.id)
  const cur = campaign.budget_currency || 'SAR'

  const budget = campaign.total_budget ?? null
  // PARTIAL-WITHHELD-001 — DISPLAY the spend through the contract (partial ⇒ «—», withheld ⇒ its own
  // currency), and do the budget MATH only from a single spend figure in the budget currency.
  const spendRead = moneyFromTotals(summary.data?.current, 'spend', true, cur)
  const spendVsBudget = spendInBudgetCurrency(summary.data?.current, cur, summary.data?.currency ?? null)
  const remaining = budget != null && spendVsBudget != null ? budget - spendVsBudget : null
  const util = budget && budget > 0 && spendVsBudget != null ? spendVsBudget / budget : null
  // PARTIAL-WITHHELD-001 (d/f) — planned-vs-actual trend plots effective money in one currency, or «—».
  const moneySeries = resolveMoneySeries((perf.data ?? []) as unknown as Array<Record<string, unknown>>, ['spend', 'revenue'], cur)

  const pacing = useMemo(() => {
    if (!campaign.starts_on || !campaign.ends_on || budget == null) return null
    const start = new Date(campaign.starts_on).getTime()
    const end = new Date(campaign.ends_on).getTime()
    const now = Date.now()
    const totalDays = Math.max(1, Math.round((end - start) / 86400000))
    const elapsed = Math.min(totalDays, Math.max(0, Math.round((now - start) / 86400000)))
    const remainingDays = Math.max(0, totalDays - elapsed)
    const requiredPace = budget / totalDays
    // currentPace/forecast need the spend as a single budget-currency figure — null when it is not.
    const currentPace = spendVsBudget != null && elapsed > 0 ? spendVsBudget / elapsed : null
    const forecast = spendVsBudget != null ? (currentPace ?? 0) * totalDays : null
    return { totalDays, elapsed, remainingDays, requiredPace, currentPace, forecast }
  }, [campaign.starts_on, campaign.ends_on, budget, spendVsBudget])

  const budgetChanges = useMemo(
    () => (activity.data ?? []).filter((e) => e.action === 'campaign.updated' && (e.before?.total_budget ?? null) !== (e.after?.total_budget ?? null) && e.after && 'total_budget' in e.after),
    [activity.data],
  )
  // PARTIAL-WITHHELD-001 — a budget-allocation donut is a spend share; real only when the platforms
  // are comparable in one currency. Null (chart shows «unavailable») otherwise, never fake shares.
  const platformAllocRank = rankableMoney((platforms.data ?? []) as MoneyTotals[], 'spend', cur)
  /*
   * A donut drops and discloses; only a TOTAL fails closed (PARTIAL-WITHHELD-001). A platform with no
   * comparable magnitude is left off and counted, so the platforms that ARE known still draw.
   */
  const platformAlloc = platformAllocRank === null
    ? null
    : {
        data: (platforms.data ?? []).flatMap((p, i) => {
          const value = platformAllocRank.values[i]
          return value === null ? [] : [{ name: providerLabel(p.provider, locale), value }]
        }),
        dropped: platformAllocRank.dropped,
      }

  if (summary.isLoading) return <Skeleton className="h-64" />
  if (summary.isError) return <ErrorState error={summary.error} title="تعذّر تحميل الميزانية" onRetry={() => summary.refetch()} />

  return (
    <div className="space-y-4">
      <div className="grid gap-4 lg:grid-cols-3">
        <ChartCard title="استهلاك الميزانية" subtitle={budget != null ? `${spendRead.text} من ${money(budget, cur)}` : 'لا ميزانية محددة'}>
          <div className="flex h-[190px] items-center justify-center">
            <ProgressRing value={util ?? 0} sublabel={util != null ? percent(util, 0) : '—'} size={150} tone={util != null && util > 0.95 ? 'danger' : util != null && util > 0.8 ? 'warning' : 'brand'} />
          </div>
        </ChartCard>
        <ChartCard title="المخطط مقابل الفعلي" subtitle="الاتجاه التراكمي" className="lg:col-span-2">
          {moneySeries === null
            ? <div className="flex h-[190px] items-center justify-center text-center text-sm text-text-muted">{locale === 'ar' ? 'الإنفاق/الإيراد عبر الزمن غير متاح — مبالغ بانتظار سعر صرف أو بعملات متعددة' : 'Spend/revenue over time unavailable — amounts await a rate or span currencies'}</div>
            : moneySeries.rows.length ? <SpendRevenueAreaChart data={moneySeries.rows} height={190} currency={moneySeries.currency ?? cur} /> : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">لا بيانات</div>}
        </ChartCard>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
        <Fact label="الميزانية" value={budget != null ? money(budget, cur) : '—'} />
        <Fact label="المصروف" value={spendRead.text} />
        <Fact label="المتبقي" value={remaining != null ? money(remaining, cur) : '—'} />
        <Fact label="أيام منقضية" value={pacing ? String(pacing.elapsed) : '—'} />
        <Fact label="أيام متبقية" value={pacing ? String(pacing.remainingDays) : '—'} />
        <Fact label="السرعة الحالية/المطلوبة" value={pacing ? `${pacing.currentPace != null ? money(pacing.currentPace, cur) : '—'} / ${money(pacing.requiredPace, cur)}` : '—'} />
        <Fact label="توقع نهاية الحملة" value={pacing && pacing.forecast != null ? money(pacing.forecast, cur) : '—'} tone={pacing && pacing.forecast != null && budget != null && pacing.forecast > budget * 1.05 ? 'danger' : undefined} />
        {/* PARTIAL-WITHHELD-001 — budget risk cannot be judged without a real spend figure. */}
        <Fact label="خطر الميزانية" value={util != null && util > 0.95 ? 'مرتفع' : pacing && pacing.forecast != null && budget != null && pacing.forecast > budget * 1.05 ? 'تجاوز متوقع' : util != null || (pacing && pacing.forecast != null) ? 'ضمن الحدود' : 'غير متاح'} tone={util != null && util > 0.95 ? 'danger' : undefined} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title="توزيع الميزانية" subtitle="حسب المنصة">
          {platformAlloc === null
            ? <div className="flex h-[190px] items-center justify-center text-center text-sm text-text-muted">توزيع الإنفاق غير متاح — مبالغ جزئية أو بعملات متعددة</div>
            : platformAlloc.data.length
              ? <>
                  <PlatformDonutChart data={platformAlloc.data} centerLabel="الإنفاق" centerValue={compact(platformAlloc.data.reduce((a, b) => a + b.value, 0))} height={190} />
                  {platformAlloc.dropped > 0 && <p className="mt-1 text-center text-[11px] text-text-muted">{`${platformAlloc.dropped} منصة غير مُدرجة — مبالغ جزئية أو بعملات متعددة`}</p>}
                </>
              : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">لا بيانات منصات</div>}
        </ChartCard>
        <ChartCard title="سجل تعديلات الميزانية" subtitle="من سجل التدقيق">
          {budgetChanges.length ? (
            <ul className="space-y-2 text-sm">
              {budgetChanges.map((e) => (
                <li key={e.id} className="flex items-center justify-between gap-2 border-b border-border pb-1.5 last:border-0">
                  <span className="tnum">{money(Number(e.before?.total_budget ?? 0), cur)} → {money(Number(e.after?.total_budget ?? 0), cur)}</span>
                  <span className="text-xs text-text-muted">{e.actor} · {e.at ? fmtDate(e.at) : ''}</span>
                </li>
              ))}
            </ul>
          ) : <div className="flex h-[150px] items-center justify-center text-sm text-text-muted">لا تعديلات ميزانية مسجّلة</div>}
        </ChartCard>
      </div>
    </div>
  )
}

/** CMC-10 — Conversion funnel (objective-aware) for the campaign. */
export function CampaignFunnelTab({ campaign, projectId, range }: { campaign: UnifiedCampaign; projectId: string; range: Range }) {
  const funnel = useCampaignFunnel(projectId, campaign.id, range)
  const cur = campaign.budget_currency || 'SAR'
  const stages = funnel.data ?? []

  const bottleneck = useMemo(() => {
    const withDrop = stages.filter((s) => s.drop_off != null)
    if (!withDrop.length) return null
    return [...withDrop].sort((a, b) => (b.drop_off ?? 0) - (a.drop_off ?? 0))[0]
  }, [stages])

  if (funnel.isLoading) return <Skeleton className="h-72" />
  if (funnel.isError) return <ErrorState error={funnel.error} title="تعذّر تحميل القمع" onRetry={() => funnel.refetch()} />
  if (!stages.length) return <EmptyState title="لا قمع" description="لا توجد بيانات قمع لهذه الحملة في الفترة المحددة." />

  return (
    <div className="space-y-4">
      <ChartCard title={`قمع التحويل — ${campaign.objective}`} subtitle="حسب هدف الحملة">
        <ConversionFunnelChart stages={stages} currency={cur} ar />
      </ChartCard>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {stages.map((s) => (
          <div key={s.stage} className="rounded-xl border border-border bg-surface p-3">
            <div className="text-[11px] uppercase text-text-muted">{s.label}</div>
            {/* FUNNEL-NULL-001 — «لم تُرسل» is a different sentence from «صفر», and the card must say
                which one it means. `num(null)` already prints «—», but a dash alone reads as a
                rendering failure; the label underneath names the reason. */}
            <div className="tnum text-lg font-extrabold">{s.count !== null ? num(s.count) : '—'}</div>
            {s.reported ? (
              <div className="flex justify-between text-[11px] text-text-muted">
                <span>تحويل {s.step_rate != null ? percent(s.step_rate, 0) : '—'}</span>
                <span>تكلفة {s.cost_per != null ? money(s.cost_per, cur) : '—'}</span>
              </div>
            ) : (
              <div className="text-[11px] text-text-muted">لم ترسل المنصة هذه المرحلة</div>
            )}
          </div>
        ))}
      </div>
      {bottleneck && (
        <div className="rounded-xl border border-warning/40 bg-warning/5 p-3 text-sm">
          <span className="font-semibold text-warning">أكبر تسرّب:</span> {bottleneck.label} — {percent(bottleneck.drop_off ?? 0, 0)}؛ الإجراء المقترح: مراجعة هذه المرحلة (استهداف/محتوى/صفحة الهبوط).
        </div>
      )}
    </div>
  )
}

/** CMC-5 (recent) + CMC-14 (full) — activity timeline from the audit log. */
export function CampaignActivityTab({ campaign, projectId, limit }: { campaign: UnifiedCampaign; projectId: string; limit?: number }) {
  const activity = useCampaignActivity(projectId, campaign.id)
  const events = (activity.data ?? []).slice(0, limit)

  if (activity.isLoading) return <div className="space-y-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12" />)}</div>
  if (activity.isError) return <ErrorState error={activity.error} title="تعذّر تحميل النشاط" onRetry={() => activity.refetch()} />
  if (!events.length) return <EmptyState title="لا نشاط" description="لم تُسجَّل أحداث لهذه الحملة بعد." />

  return (
    <ol className="relative space-y-3 border-s border-border ps-4">
      {events.map((e) => (
        <li key={e.id} className="relative">
          <span className="absolute -start-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-500 ring-2 ring-surface" aria-hidden />
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="text-sm font-semibold text-text-primary">{e.label}</span>
            <span className="text-[11px] text-text-muted">{e.actor} · {e.at ? fmtDateTime(e.at) : ''}</span>
          </div>
          {e.action === 'campaign.updated' && e.before && e.after && (
            <div className="mt-0.5 flex flex-wrap gap-x-3 text-[11px] text-text-muted">
              {Object.keys(e.after).filter((k) => (e.before as Record<string, unknown>)[k] !== (e.after as Record<string, unknown>)[k]).map((k) => (
                <span key={k} className="tnum">{k}: {String((e.before as Record<string, unknown>)[k] ?? '—')} → {String((e.after as Record<string, unknown>)[k] ?? '—')}</span>
              ))}
            </div>
          )}
        </li>
      ))}
    </ol>
  )
}

function Fact({ label, value, tone }: { label: string; value: string; tone?: 'danger' }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-xl border border-border bg-surface p-3">
      <span className="text-[11px] uppercase tracking-wide text-text-muted">{label}</span>
      <span className={`text-sm font-bold ${tone === 'danger' ? 'text-danger' : 'text-text-primary'}`}>{value}</span>
    </div>
  )
}

/** CMC-7 — Platforms tab: one rich card per LINKED platform (metrics + externals + last sync). */
export function CampaignPlatformsTab({
  campaign, projectId, range, locale, linked, onLink, onUnlink, unlinkingId, canUpdate,
}: {
  campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale
  linked: import('./types').ExternalCampaign[]; onLink: () => void
  onUnlink: (externalId: string) => void; unlinkingId?: string; canUpdate: boolean
}) {
  const platforms = useCampaignPlatforms(projectId, campaign.id, range)
  // The platform figures below state their own currency now, so the plan's unit is not needed here.

  // Only providers that have a linked external campaign appear (never an unlinked platform).
  const providers = useMemo(() => [...new Set(linked.map((e) => e.provider))], [linked])
  const metricByProvider = useMemo(() => {
    const map: Record<string, PlatformRow> = {}
    for (const p of platforms.data ?? []) map[p.provider] = p
    return map
  }, [platforms.data])

  if (platforms.isLoading) return <div className="grid gap-3 lg:grid-cols-2">{Array.from({ length: 2 }).map((_, i) => <Skeleton key={i} className="h-48" />)}</div>
  if (providers.length === 0) {
    return (
      <div className="space-y-3">
        {canUpdate && <div className="flex justify-end"><button onClick={onLink} className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white">ربط حملة خارجية</button></div>}
        <EmptyState title="لا منصات مرتبطة" description="اربط حملة خارجية من حساباتك المتزامنة لتظهر هنا." />
      </div>
    )
  }

  return (
    <div className="space-y-3">
      {canUpdate && <div className="flex justify-end"><button onClick={onLink} className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white">ربط حملة خارجية</button></div>}
      <div className="grid gap-3 lg:grid-cols-2">
        {providers.map((prov) => {
          const m = metricByProvider[prov]
          const externals = linked.filter((e) => e.provider === prov)
          const lastSync = externals.map((e) => e.last_synced_at).filter(Boolean).sort().at(-1)
          const isDemo = externals.some((e) => e.provider.includes('sandbox') || e.provider === 'sandbox')
          return (
            <div key={prov} className="rounded-2xl border border-border bg-surface p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span className="text-base font-bold text-text-primary">{providerLabel(prov, locale)}</span>
                  {isDemo && <span className="rounded bg-warning/15 px-1.5 py-0.5 text-[10px] font-semibold text-warning">Demo</span>}
                </div>
                <span className="text-[11px] text-text-muted">آخر مزامنة: {lastSync ? fmtDate(lastSync) : '—'}</span>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                {/*
                  MONEY-TRUTH-002 — the per-platform panel read the raw fields, and named them with
                  the BUDGET's currency.

                  Two mistakes in one cell: `m.spend` is the aggregator's coalesced 0 on a withheld
                  row, and `cur` is `campaign.budget_currency` — the unit of the plan, not of the
                  spend. A campaign budgeted in SAR whose platform reports USD showed the wrong
                  figure under the wrong unit. `byProvider()` is the same query the analytics
                  Platforms tab reads, and it carries the provenance; these are its readers.
                */}
                <MiniStat label="الإنفاق" value={rowMoney(m, 'spend')} />
                <MiniStat label="النتائج" value={num(m?.conversions ?? 0)} />
                <MiniStat label="CPA" value={rowCostPer(m, 'cpa', 'conversions')} />
                <MiniStat label="ROAS" value={rowRoas(m)} />
                <MiniStat label="المساهمة" value={m?.spend_share != null ? percent(m.spend_share, 0) : '—'} />
                <MiniStat label="CTR" value={percent(m?.ctr ?? 0)} />
                <MiniStat label="حملات خارجية" value={String(externals.length)} />
                <MiniStat label="الحساب" value={externals[0]?.external_account_id?.slice(0, 8) ?? '—'} />
              </div>
              <div className="mt-3">
                <div className="text-xs font-semibold text-text-secondary">الحملات الخارجية ({externals.length})</div>
                <ul className="mt-2 space-y-1.5">
                  {externals.map((e) => (
                    <li key={e.id} className="flex items-center justify-between gap-2 rounded-lg border border-border p-2 text-xs">
                      <span className="truncate font-medium">{e.name}</span>
                      <span className="flex items-center gap-2">
                        <span className="tnum text-text-muted">{e.status} · {e.external_id}</span>
                        {canUpdate && (
                          <button onClick={() => onUnlink(e.id)} disabled={unlinkingId === e.id} className="rounded border border-border px-1.5 py-0.5 text-[10px] font-semibold text-text-muted hover:text-danger disabled:opacity-50">
                            {unlinkingId === e.id ? '…' : 'فك الربط'}
                          </button>
                        )}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/**
 * A figure inside a row of its own, deliberately smaller than a KPI card.
 *
 * Kept as its own element rather than folded into `StatCard`: these sit INSIDE a card, four to a
 * line, as the detail under a headline figure. A KPI card nested in a KPI card is not a smaller
 * card, it is a second reading of the same importance — which is the confusion the shared card
 * exists to remove. It takes the shared label size so it reads as part of the same system.
 */
function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-secondary p-2">
      <div className="text-[11px] font-semibold leading-tight text-text-muted">{label}</div>
      <div className="tnum text-sm font-bold text-text-primary" dir="ltr">{value}</div>
    </div>
  )
}

/** CMC-12 — Campaign alerts (from the shared notification store, filtered to this campaign). */
export function CampaignAlertsTab({ campaign, projectId }: { campaign: UnifiedCampaign; projectId: string }) {
  const [status, setStatus] = useState('')
  const alerts = useCampaignAlerts(projectId, campaign.id, status)
  const rows = alerts.data ?? []

  const sevTone: Record<string, string> = {
    critical: 'border-danger/40 bg-danger/5 text-danger', warning: 'border-warning/40 bg-warning/5 text-warning',
    success: 'border-success/40 bg-success/5 text-success', info: 'border-border bg-surface text-text-secondary',
  }
  const FILTERS = [['', 'الكل'], ['unread', 'نشطة'], ['resolved', 'محلولة'], ['snoozed', 'مؤجّلة']] as const

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-1.5">
        {FILTERS.map(([v, label]) => (
          <button key={v} onClick={() => setStatus(v)} className={`rounded-lg px-3 py-1 text-xs font-semibold ${status === v ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary hover:bg-surface-hover'}`}>{label}</button>
        ))}
      </div>
      {alerts.isLoading ? <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-16" />)}</div>
        : alerts.isError ? <ErrorState error={alerts.error} title="تعذّر تحميل التنبيهات" onRetry={() => alerts.refetch()} />
        : rows.length === 0 ? <EmptyState title="لا تنبيهات" description="لا توجد تنبيهات لهذه الحملة ضمن هذا الفلتر." />
        : (
          <ul className="space-y-2">
            {rows.map((a) => (
              <li key={a.id} className={`rounded-xl border p-3 ${sevTone[a.severity] ?? sevTone.info}`}>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-bold">{a.title}</span>
                  <span className="text-[11px] opacity-70">{a.created_at ? fmtDateTime(a.created_at) : ''}</span>
                </div>
                {a.message && <p className="mt-0.5 text-xs opacity-90">{a.message}</p>}
                <div className="mt-1 flex items-center gap-2 text-[11px] opacity-70"><span>{a.severity}</span>{a.source && <><span>·</span><span>{a.source}</span></>}<span>·</span><span>{a.status}</span></div>
              </li>
            ))}
          </ul>
        )}
    </div>
  )
}

/** CMC-13 — Reports linked to this campaign (reuses the real report pipeline). */
export function CampaignReportsTab({ campaign, projectId }: { campaign: UnifiedCampaign; projectId: string }) {
  const reports = useCampaignReports(projectId, campaign.id)
  const rows = reports.data ?? []

  if (reports.isLoading) return <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-16" />)}</div>
  if (reports.isError) return <ErrorState error={reports.error} title="تعذّر تحميل التقارير" onRetry={() => reports.refetch()} />
  if (rows.length === 0) return <EmptyState title="لا تقارير" description="لا تقارير مرتبطة بهذه الحملة بعد. أنشئ تقريرًا من صفحة التقارير واربطه بالحملة." />

  const statusTone: Record<string, string> = { completed: 'text-success', processing: 'text-warning', failed: 'text-danger', draft: 'text-text-muted' }
  return (
    <ul className="space-y-2">
      {rows.map((r) => (
        <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-surface p-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="truncate text-sm font-bold text-text-primary">{r.name}</span>
              <span className="rounded bg-surface-secondary px-1.5 py-0.5 text-[10px] font-semibold text-text-muted">{r.type}</span>
              {r.audience && <span className="rounded bg-surface-secondary px-1.5 py-0.5 text-[10px] text-text-muted">{r.audience}</span>}
              {r.is_demo && <span className="rounded bg-warning/15 px-1.5 py-0.5 text-[10px] font-semibold text-warning">Demo</span>}
            </div>
            <div className="mt-0.5 flex items-center gap-2 text-[11px] text-text-muted">
              <span className={statusTone[r.status] ?? ''}>{r.status}</span>
              <span>· {r.mode === 'live' ? 'Live' : 'Snapshot'}</span>
              {r.last_sent_at && <span>· أُرسل {fmtDate(r.last_sent_at)}</span>}
            </div>
          </div>
          <div className="flex items-center gap-1.5">
            {r.exports.filter((e) => e.status === 'completed' && e.token).map((e) => (
              <a key={e.format} href={`/api/v1/reports/download/${e.token}`} className="rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover">{e.format.toUpperCase()}</a>
            ))}
          </div>
        </li>
      ))}
    </ul>
  )
}

/** CMC-11 — Notes & Recommendations: two columns, Draft→Approved workflow, evidence-backed. */
export function CampaignNotesTab({ campaign, projectId, canUpdate, canApprove }: { campaign: UnifiedCampaign; projectId: string; canUpdate: boolean; canApprove: boolean }) {
  const annotations = useCampaignAnnotations(projectId, campaign.id)
  const create = useCreateAnnotation(projectId, campaign.id)
  const update = useUpdateAnnotation(projectId, campaign.id)
  const [adding, setAdding] = useState<'note' | 'recommendation' | null>(null)
  const [form, setForm] = useState({ title: '', body: '', kpi: '', evidence: '', platform: '', priority: 'medium', proposed_action: '' })

  const rows = annotations.data ?? []
  const notes = rows.filter((a) => a.kind === 'note')
  const recs = rows.filter((a) => a.kind === 'recommendation')

  const submit = (kind: 'note' | 'recommendation') => {
    if (!form.title.trim()) return
    create.mutate({ kind, ...form } as never, { onSuccess: () => { setAdding(null); setForm({ title: '', body: '', kpi: '', evidence: '', platform: '', priority: 'medium', proposed_action: '' }) } })
  }

  const statusTone: Record<string, string> = { approved: 'bg-success/15 text-success', reviewed: 'bg-info/15 text-info', draft: 'bg-surface-secondary text-text-muted', hidden: 'bg-surface-secondary text-text-muted', rejected: 'bg-danger/15 text-danger' }
  const prioTone: Record<string, string> = { critical: 'text-danger', high: 'text-warning', medium: 'text-info', low: 'text-text-muted' }

  const Card2 = ({ a }: { a: import('./metrics').CampaignAnnotation }) => (
    <div className="rounded-xl border border-border bg-surface p-3">
      <div className="flex items-start justify-between gap-2">
        <span className="text-sm font-bold text-text-primary">{a.title}</span>
        <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${statusTone[a.status] ?? statusTone.draft}`}>{a.status}</span>
      </div>
      {a.body && <p className="mt-1 text-xs text-text-secondary">{a.body}</p>}
      {a.evidence && <p className="mt-1 text-[11px] text-text-muted">الدليل: {a.evidence}</p>}
      <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-muted">
        {a.platform && <span>{a.platform}</span>}
        {a.kpi && <span>· {a.kpi}</span>}
        <span className={prioTone[a.priority] ?? ''}>· {a.priority}</span>
        {a.due_date && <span>· موعد {a.due_date}</span>}
      </div>
      {a.proposed_action && <p className="mt-1 text-[11px] text-brand-700">الإجراء: {a.proposed_action}</p>}
      {canApprove && a.status !== 'approved' && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          <button onClick={() => update.mutate({ id: a.id, status: 'approved' } as never)} className="rounded border border-success/40 px-1.5 py-0.5 text-[10px] font-semibold text-success">اعتماد</button>
          <button onClick={() => update.mutate({ id: a.id, status: 'reviewed' } as never)} className="rounded border border-border px-1.5 py-0.5 text-[10px] font-semibold text-text-secondary">مراجعة</button>
          <button onClick={() => update.mutate({ id: a.id, status: 'rejected' } as never)} className="rounded border border-danger/40 px-1.5 py-0.5 text-[10px] font-semibold text-danger">رفض</button>
          <button onClick={() => update.mutate({ id: a.id, status: 'hidden' } as never)} className="rounded border border-border px-1.5 py-0.5 text-[10px] font-semibold text-text-muted">إخفاء</button>
        </div>
      )}
    </div>
  )

  const AddForm = ({ kind }: { kind: 'note' | 'recommendation' }) => (
    <div className="space-y-2 rounded-xl border border-dashed border-border p-3">
      <input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="العنوان" className="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm" />
      <textarea value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} placeholder="النص" className="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm" rows={2} />
      <div className="grid grid-cols-2 gap-2">
        <input value={form.kpi} onChange={(e) => setForm({ ...form, kpi: e.target.value })} placeholder="KPI" className="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs" />
        <input value={form.platform} onChange={(e) => setForm({ ...form, platform: e.target.value })} placeholder="المنصة" className="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs" />
      </div>
      <input value={form.evidence} onChange={(e) => setForm({ ...form, evidence: e.target.value })} placeholder="الدليل الرقمي" className="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-xs" />
      {kind === 'recommendation' && <input value={form.proposed_action} onChange={(e) => setForm({ ...form, proposed_action: e.target.value })} placeholder="الإجراء المقترح" className="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-xs" />}
      <div className="flex items-center gap-2">
        <select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} className="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs">
          {['critical', 'high', 'medium', 'low'].map((p) => <option key={p} value={p}>{p}</option>)}
        </select>
        <button onClick={() => submit(kind)} disabled={create.isPending} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">حفظ</button>
        <button onClick={() => setAdding(null)} className="text-xs text-text-muted">إلغاء</button>
      </div>
    </div>
  )

  if (annotations.isLoading) return <div className="grid gap-4 lg:grid-cols-2"><Skeleton className="h-40" /><Skeleton className="h-40" /></div>
  if (annotations.isError) return <ErrorState error={annotations.error} title="تعذّر تحميل الملاحظات" onRetry={() => annotations.refetch()} />

  return (
    <div className="grid gap-4 lg:grid-cols-2" dir="rtl">
      {/* Right column: notes / findings */}
      <div className="space-y-2">
        <div className="flex items-center justify-between"><h3 className="text-sm font-bold">أبرز النتائج والملاحظات</h3>{canUpdate && <button onClick={() => setAdding('note')} className="text-xs font-semibold text-brand-700">+ ملاحظة</button>}</div>
        {adding === 'note' && <AddForm kind="note" />}
        {notes.length === 0 && adding !== 'note' ? <EmptyState title="لا ملاحظات" /> : notes.map((a) => <Card2 key={a.id} a={a} />)}
      </div>
      {/* Left column: recommendations */}
      <div className="space-y-2">
        <div className="flex items-center justify-between"><h3 className="text-sm font-bold">التوصيات والخطوات القادمة</h3>{canUpdate && <button onClick={() => setAdding('recommendation')} className="text-xs font-semibold text-brand-700">+ توصية</button>}</div>
        {adding === 'recommendation' && <AddForm kind="recommendation" />}
        {recs.length === 0 && adding !== 'recommendation' ? <EmptyState title="لا توصيات" /> : recs.map((a) => <Card2 key={a.id} a={a} />)}
      </div>
    </div>
  )
}

const CREATIVE_CLASS: Record<string, { label: string; tone: string }> = {
  top_performing: { label: 'أداء متميز', tone: 'bg-success/15 text-success' },
  promising: { label: 'واعد', tone: 'bg-info/15 text-info' },
  needs_improvement: { label: 'يحتاج تحسينًا', tone: 'bg-warning/15 text-warning' },
  fatigued: { label: 'إجهاد إعلاني', tone: 'bg-warning/15 text-warning' },
  paused: { label: 'متوقف', tone: 'bg-surface-secondary text-text-muted' },
  insufficient_data: { label: 'بيانات غير كافية', tone: 'bg-surface-secondary text-text-muted' },
}

/** CMC-8 — Creatives ranked by campaign objective. Real metrics; previews only when the source has them. */
export function CampaignCreativesTab({ campaign, projectId, range, locale }: { campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale }) {
  const creatives = useCampaignCreatives(projectId, campaign.id, range)
  const [view, setView] = useState<'grid' | 'table'>('grid')
  /*
   * AD-PREVIEW-001 — the ad opens HERE.
   *
   * The name was plain text in the table and unclickable in the grid, so «which ad is this?» — the
   * first question anybody asks of a ranked list of ads — could only be answered by leaving the
   * campaign, finding the library and filtering back down to it. A panel keeps the ranking on
   * screen behind it, which is the comparison the reader was in the middle of.
   */
  const [open, setOpen] = useState<CampaignCreative | null>(null)
  const cur = campaign.budget_currency || 'SAR'
  const rows = creatives.data ?? []
  /*
   * This tab was written in Arabic only — the toggle, the header, both empty states and every table
   * heading — while the portal around it switches language. An English reader met eight Arabic
   * labels on one tab and nothing else on the page behaved that way.
   */
  const ar = locale === 'ar'

  if (creatives.isLoading) return <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-56" />)}</div>
  if (creatives.isError) return <ErrorState error={creatives.error} title={ar ? 'تعذّر تحميل الإعلانات' : 'The ads could not be loaded'} onRetry={() => creatives.refetch()} />
  if (rows.length === 0) {
    return (
      <EmptyState
        title={ar ? 'لا إعلانات' : 'No ads'}
        description={ar
          ? 'لا توجد إعلانات مزامنة لهذه الحملة بعد. تظهر تلقائيًا بعد مزامنة الإعلانات من المنصة.'
          : 'No ads have synced for this campaign yet. They appear here once the platform’s ads are pulled.'}
      />
    )
  }

  const cls = (k: string) => CREATIVE_CLASS[k] ?? CREATIVE_CLASS.insufficient_data

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <span className="text-xs text-text-muted">
          {ar ? 'مرتّبة حسب هدف الحملة' : 'Ranked by the campaign’s objective'} ({campaign.objective})
        </span>
        <div className="flex gap-1">
          {(['grid', 'table'] as const).map((v) => (
            <button key={v} onClick={() => setView(v)} className={`rounded-lg px-2.5 py-1 text-xs font-semibold ${view === v ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`}>{v === 'grid' ? (ar ? 'شبكة' : 'grid') : (ar ? 'جدول' : 'table')}</button>
          ))}
        </div>
      </div>

      {view === 'grid' ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((c) => (
            <div key={c.id} className="space-y-2 rounded-2xl border border-border bg-surface p-3">
              <AdPoster preview={c.preview} name={c.client_display_name || c.name} testid={`ad-poster-${c.id}`} />
              <div className="flex items-start justify-between gap-2">
                <button
                  type="button"
                  onClick={() => setOpen(c)}
                  className="truncate text-start text-sm font-bold text-text-primary underline-offset-2 hover:underline"
                >
                  {c.client_display_name || c.name}
                </button>
                <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls(c.classification).tone}`}>{cls(c.classification).label}</span>
              </div>
              <div className="flex items-center gap-2 text-[11px] text-text-muted">{providerLabel(c.provider, locale)} · {c.format}{c.is_demo && <span className="rounded bg-warning/15 px-1 text-warning">Demo</span>}</div>
              <div className="grid grid-cols-3 gap-1.5 text-center">
                <MiniStat label="الإنفاق" value={money(c.metrics.spend, cur)} />
                <MiniStat label="النتائج" value={num(c.metrics.conversions)} />
                <MiniStat label="ROAS" value={ratio(c.metrics.roas)} />
                <MiniStat label="CPA" value={money(c.metrics.cpa, cur)} />
                <MiniStat label="CTR" value={percent(c.metrics.ctr ?? 0)} />
                <MiniStat label="مشاهدة" value={c.metrics.view_rate != null ? percent(c.metrics.view_rate, 0) : '—'} />
              </div>
              <p className="text-[11px] text-text-muted">{c.ranking_reason}</p>
            </div>
          ))}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface-secondary text-xs text-text-muted"><tr>{(ar
                ? ['الإعلان', 'المنصة', 'الإنفاق', 'النتائج', 'CPA', 'ROAS', 'CTR', 'التصنيف']
                : ['Ad', 'Platform', 'Spend', 'Results', 'CPA', 'ROAS', 'CTR', 'Classification']).map((h) => <th key={h} className="p-2 text-start font-semibold">{h}</th>)}</tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id} className="border-t border-border">
                  <td className="p-2 font-semibold">
                    <button type="button" onClick={() => setOpen(c)} className="text-start underline-offset-2 hover:underline">
                      {c.client_display_name || c.name}
                    </button>
                  </td>
                  <td className="p-2 text-text-muted">{providerLabel(c.provider, locale)}</td>
                  <td className="tnum p-2">{money(c.metrics.spend, cur)}</td>
                  <td className="tnum p-2">{num(c.metrics.conversions)}</td>
                  <td className="tnum p-2">{money(c.metrics.cpa, cur)}</td>
                  <td className="tnum p-2">{ratio(c.metrics.roas)}</td>
                  <td className="tnum p-2">{percent(c.metrics.ctr ?? 0)}</td>
                  <td className="p-2"><span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls(c.classification).tone}`}>{cls(c.classification).label}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {open && <AdPreviewPanel creative={open} currency={cur} locale={locale} onClose={() => setOpen(null)} />}
    </div>
  )
}

/**
 * One ad, in place — AD-PREVIEW-001.
 *
 * A panel rather than a route, because the reader is in the middle of comparing a ranked list and
 * navigating away costs them the comparison. It carries what answers «which ad is this?» — platform,
 * format, status, the metric it was ranked on and the figures — beside the still.
 *
 * The still is `AdPoster`, the same reader every other surface uses, so an ad that shows here shows
 * everywhere, and an ad that cannot gives the same reason everywhere.
 */
function AdPreviewPanel({
  creative,
  currency,
  locale,
  onClose,
}: {
  creative: CampaignCreative
  currency: string
  locale: 'ar' | 'en'
  onClose: () => void
}) {
  const ar = locale === 'ar'

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={creative.client_display_name || creative.name}
      data-testid="ad-preview-panel"
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center sm:p-6"
      onClick={onClose}
    >
      <div
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl border border-border bg-surface p-4 sm:rounded-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-start justify-between gap-3">
          <h3 className="text-sm font-bold text-text-primary">{creative.client_display_name || creative.name}</h3>
          <button type="button" onClick={onClose} className="text-xs font-semibold text-text-muted hover:text-text-primary">
            {ar ? 'إغلاق' : 'Close'}
          </button>
        </div>

        <AdPoster
          preview={creative.preview}
          name={creative.client_display_name || creative.name}
          className="h-64 w-full"
          testid="ad-preview-panel-poster"
        />

        <dl className="mt-3 grid grid-cols-2 gap-2 text-[11px]">
          <AdFact label={ar ? 'المنصة' : 'Platform'} value={providerLabel(creative.provider, locale)} />
          <AdFact label={ar ? 'النوع' : 'Format'} value={creative.format} />
          <AdFact label={ar ? 'الحالة' : 'Status'} value={creative.status} />
          <AdFact label={ar ? 'مقياس الترتيب' : 'Ranked on'} value={creative.rank_metric} />
        </dl>

        <div className="mt-3 grid grid-cols-3 gap-1.5 text-center">
          <MiniStat label={ar ? 'الإنفاق' : 'Spend'} value={money(creative.metrics.spend, currency)} />
          <MiniStat label={ar ? 'النتائج' : 'Results'} value={num(creative.metrics.conversions)} />
          <MiniStat label="CPA" value={money(creative.metrics.cpa, currency)} />
        </div>

        <p className="mt-3 text-[11px] text-text-muted">{creative.ranking_reason}</p>
      </div>
    </div>
  )
}

function AdFact({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-secondary px-2 py-1.5">
      <dt className="text-text-muted">{label}</dt>
      <dd className="font-semibold text-text-primary">{value}</dd>
    </div>
  )
}
