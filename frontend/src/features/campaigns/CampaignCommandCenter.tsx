import { useMemo } from 'react'
import { TrendingDown, TrendingUp } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import {
  useCampaignActivity,
  useCampaignFunnel,
  useCampaignPerformance,
  useCampaignPlatforms,
  useCampaignSummary,
} from './metrics'
import type { MetricTotals, Range, TimePoint } from '@/features/analytics/api'
import { ChartCard, ConversionFunnelChart, KpiSparkline, MetricLineChart, PlatformDonutChart, ProgressRing, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { compact, money, num, percent, ratio, trend } from '@/features/analytics/format'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { providerLabel } from './labels'
import type { Locale } from '@/stores/ui'

type Sparkable = keyof MetricTotals

/** Objective → the primary cost metric the client cares about (CPA vs CPL). */
function costLabel(objective: string): string {
  return objective === 'leads' ? 'CPL' : 'CPA'
}

function deltaTone(key: Sparkable, delta: number | null | undefined): 'up' | 'down' | 'flat' {
  const t = trend(delta)
  // For cost metrics lower is better, so invert the visual sentiment.
  const invert = key === 'cpa' || key === 'cpc' || key === 'cpm'
  if (t === 'flat') return 'flat'
  return invert ? (t === 'up' ? 'down' : 'up') : t
}

function KpiCard({
  label, value, sub, delta, deltaKey, spark,
}: {
  label: string; value: string; sub?: string; delta?: number | null; deltaKey?: Sparkable; spark?: number[]
}) {
  const tone = deltaKey ? deltaTone(deltaKey, delta) : trend(delta)
  const toneClass = tone === 'up' ? 'text-success' : tone === 'down' ? 'text-danger' : 'text-text-muted'
  return (
    <div className="flex flex-col gap-1 rounded-xl border border-border bg-surface p-3">
      <span className="text-[11px] font-medium uppercase tracking-wide text-text-muted">{label}</span>
      <span className="tnum text-lg font-extrabold text-text-primary">{value}</span>
      <div className="flex items-center justify-between gap-2">
        <span className="text-[11px] text-text-muted">{sub ?? ''}</span>
        {delta != null && (
          <span className={`inline-flex items-center gap-0.5 text-[11px] font-semibold ${toneClass}`}>
            {tone === 'up' ? <TrendingUp size={11} /> : tone === 'down' ? <TrendingDown size={11} /> : null}
            {percent(Math.abs(delta) * 100, 0)}
          </span>
        )}
      </div>
      {spark && spark.length > 1 && <KpiSparkline points={spark} height={26} />}
    </div>
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
  const spend = k?.spend ?? 0
  const remaining = budget != null ? budget - spend : null
  const utilization = budget && budget > 0 ? spend / budget : null
  const convRate = k && k.clicks > 0 ? k.conversions / k.clicks : null
  const cur = campaign.budget_currency || 'SAR'

  if (summary.isLoading) return <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">{Array.from({ length: 12 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>

  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
      <KpiCard label="الميزانية" value={budget != null ? money(budget, cur) : '—'} />
      <KpiCard label="المصروف" value={money(spend, cur)} delta={d.spend} deltaKey="spend" spark={sparks(perf.data, 'spend')} />
      <KpiCard label="المتبقي" value={remaining != null ? money(remaining, cur) : '—'} sub={utilization != null ? `استهلاك ${percent(utilization * 100, 0)}` : undefined} />
      <KpiCard label="النتائج" value={num(k?.conversions)} delta={d.conversions} deltaKey="conversions" spark={sparks(perf.data, 'conversions')} />
      <KpiCard label={costLabel(campaign.objective)} value={money(k?.cpa ?? null, cur)} delta={d.cpa} deltaKey="cpa" spark={sparks(perf.data, 'cpa')} />
      <KpiCard label="الإيرادات" value={money(k?.revenue ?? null, cur)} delta={d.revenue} deltaKey="revenue" spark={sparks(perf.data, 'revenue')} />
      <KpiCard label="ROAS" value={ratio(k?.roas ?? null)} delta={d.roas} deltaKey="roas" spark={sparks(perf.data, 'roas')} />
      <KpiCard label="CTR" value={percent((k?.ctr ?? 0) * 100)} delta={d.ctr} deltaKey="ctr" spark={sparks(perf.data, 'ctr')} />
      <KpiCard label="CPC" value={money(k?.cpc ?? null, cur)} delta={d.cpc} deltaKey="cpc" />
      <KpiCard label="CPM" value={money(k?.cpm ?? null, cur)} delta={d.cpm} deltaKey="cpm" />
      <KpiCard label="معدل التحويل" value={convRate != null ? percent(convRate * 100) : '—'} />
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
    const util = budget && budget > 0 ? (k?.spend ?? 0) / budget : null
    return {
      topResult: k ? `${num(k.conversions)} نتيجة · ${ratio(k.roas)} ROAS` : '—',
      bestPlatform: byRoas[0] ? `${providerLabel(byRoas[0].provider, locale)} (${ratio(byRoas[0].roas)})` : '—',
      opportunity: byRoas[0] ? `توسيع ${providerLabel(byRoas[0].provider, locale)} — أعلى عائد` : '—',
      risk: util != null && util > 0.95 ? 'الميزانية شارفت على النفاد' : (k && k.conversions === 0 ? 'لا نتائج في الفترة' : 'ضمن الحدود'),
      nextStep: byRoas[0] ? `إعادة توزيع الميزانية نحو ${providerLabel(byRoas[0].provider, locale)}` : 'مراجعة الاستهداف',
    }
  }, [summary.data, platforms.data, campaign.total_budget, locale])

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

  const bestWorst = useMemo(() => {
    const withRev = series.filter((p) => p.revenue != null)
    if (withRev.length === 0) return null
    const sorted = [...withRev].sort((a, b) => (b.revenue ?? 0) - (a.revenue ?? 0))
    return { best: sorted[0], worst: sorted[sorted.length - 1] }
  }, [series])

  const platformDonut = (platforms.data ?? []).map((p) => ({ name: providerLabel(p.provider, locale), value: Number(p.spend ?? 0) }))

  if (perf.isLoading) return <div className="space-y-4"><Skeleton className="h-[240px]" /><div className="grid gap-4 lg:grid-cols-2"><Skeleton className="h-[200px]" /><Skeleton className="h-[200px]" /></div></div>

  return (
    <div className="space-y-4">
      <ChartCard title="الإنفاق مقابل الإيرادات" subtitle="الاتجاه اليومي لهذه الحملة">
        <SpendRevenueAreaChart data={series as unknown as Array<Record<string, unknown>>} height={240} currency={cur} />
      </ChartCard>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title="النتائج" subtitle="الاتجاه اليومي">
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'conversions', name: 'النتائج' }]} height={190} />
        </ChartCard>
        <ChartCard title="ROAS" subtitle="العائد على الإنفاق">
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'roas', name: 'ROAS' }]} height={190} />
        </ChartCard>
        <ChartCard title="CPA / CPC" subtitle="التكلفة">
          <MetricLineChart data={series as unknown as Array<Record<string, unknown>>} series={[{ key: 'cpa', name: 'CPA' }, { key: 'cpc', name: 'CPC' }]} height={190} />
        </ChartCard>
        <ChartCard title="مساهمة المنصات" subtitle="حسب الإنفاق">
          {platformDonut.length ? <PlatformDonutChart data={platformDonut} centerLabel="الإنفاق" centerValue={compact(platformDonut.reduce((a, b) => a + b.value, 0))} height={190} /> : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">لا بيانات منصات</div>}
        </ChartCard>
      </div>
      {bestWorst && (
        <div className="grid grid-cols-2 gap-3">
          <div className="rounded-xl border border-border bg-surface p-3"><span className="text-[11px] uppercase text-text-muted">أفضل يوم</span><div className="tnum text-sm font-bold">{bestWorst.best.date} · {money(bestWorst.best.revenue, cur)}</div></div>
          <div className="rounded-xl border border-border bg-surface p-3"><span className="text-[11px] uppercase text-text-muted">أضعف يوم</span><div className="tnum text-sm font-bold">{bestWorst.worst.date} · {money(bestWorst.worst.revenue, cur)}</div></div>
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
  const spend = summary.data?.current.spend ?? 0
  const remaining = budget != null ? budget - spend : null
  const util = budget && budget > 0 ? spend / budget : null

  const pacing = useMemo(() => {
    if (!campaign.starts_on || !campaign.ends_on || budget == null) return null
    const start = new Date(campaign.starts_on).getTime()
    const end = new Date(campaign.ends_on).getTime()
    const now = Date.now()
    const totalDays = Math.max(1, Math.round((end - start) / 86400000))
    const elapsed = Math.min(totalDays, Math.max(0, Math.round((now - start) / 86400000)))
    const remainingDays = Math.max(0, totalDays - elapsed)
    const requiredPace = budget / totalDays
    const currentPace = elapsed > 0 ? spend / elapsed : 0
    const forecast = currentPace * totalDays
    return { totalDays, elapsed, remainingDays, requiredPace, currentPace, forecast }
  }, [campaign.starts_on, campaign.ends_on, budget, spend])

  const budgetChanges = useMemo(
    () => (activity.data ?? []).filter((e) => e.action === 'campaign.updated' && (e.before?.total_budget ?? null) !== (e.after?.total_budget ?? null) && e.after && 'total_budget' in e.after),
    [activity.data],
  )
  const platformAlloc = (platforms.data ?? []).map((p) => ({ name: providerLabel(p.provider, locale), value: Number(p.spend ?? 0) }))

  if (summary.isLoading) return <Skeleton className="h-64" />
  if (summary.isError) return <ErrorState title="تعذّر تحميل الميزانية" onRetry={() => summary.refetch()} />

  return (
    <div className="space-y-4">
      <div className="grid gap-4 lg:grid-cols-3">
        <ChartCard title="استهلاك الميزانية" subtitle={budget != null ? `${money(spend, cur)} من ${money(budget, cur)}` : 'لا ميزانية محددة'}>
          <div className="flex h-[190px] items-center justify-center">
            <ProgressRing value={util ?? 0} sublabel={util != null ? percent(util * 100, 0) : '—'} size={150} tone={util != null && util > 0.95 ? 'danger' : util != null && util > 0.8 ? 'warning' : 'brand'} />
          </div>
        </ChartCard>
        <ChartCard title="المخطط مقابل الفعلي" subtitle="الاتجاه التراكمي" className="lg:col-span-2">
          {perf.data && perf.data.length ? <SpendRevenueAreaChart data={perf.data as unknown as Array<Record<string, unknown>>} height={190} currency={cur} /> : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">لا بيانات</div>}
        </ChartCard>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
        <Fact label="الميزانية" value={budget != null ? money(budget, cur) : '—'} />
        <Fact label="المصروف" value={money(spend, cur)} />
        <Fact label="المتبقي" value={remaining != null ? money(remaining, cur) : '—'} />
        <Fact label="أيام منقضية" value={pacing ? String(pacing.elapsed) : '—'} />
        <Fact label="أيام متبقية" value={pacing ? String(pacing.remainingDays) : '—'} />
        <Fact label="السرعة الحالية/المطلوبة" value={pacing ? `${money(pacing.currentPace, cur)} / ${money(pacing.requiredPace, cur)}` : '—'} />
        <Fact label="توقع نهاية الحملة" value={pacing ? money(pacing.forecast, cur) : '—'} tone={pacing && budget != null && pacing.forecast > budget * 1.05 ? 'danger' : undefined} />
        <Fact label="خطر الميزانية" value={util != null && util > 0.95 ? 'مرتفع' : pacing && budget != null && pacing.forecast > budget * 1.05 ? 'تجاوز متوقع' : 'ضمن الحدود'} tone={util != null && util > 0.95 ? 'danger' : undefined} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title="توزيع الميزانية" subtitle="حسب المنصة">
          {platformAlloc.length ? <PlatformDonutChart data={platformAlloc} centerLabel="الإنفاق" centerValue={compact(platformAlloc.reduce((a, b) => a + b.value, 0))} height={190} /> : <div className="flex h-[190px] items-center justify-center text-sm text-text-muted">لا بيانات منصات</div>}
        </ChartCard>
        <ChartCard title="سجل تعديلات الميزانية" subtitle="من سجل التدقيق">
          {budgetChanges.length ? (
            <ul className="space-y-2 text-sm">
              {budgetChanges.map((e) => (
                <li key={e.id} className="flex items-center justify-between gap-2 border-b border-border pb-1.5 last:border-0">
                  <span className="tnum">{money(Number(e.before?.total_budget ?? 0), cur)} → {money(Number(e.after?.total_budget ?? 0), cur)}</span>
                  <span className="text-xs text-text-muted">{e.actor} · {e.at ? new Date(e.at).toLocaleDateString('en-GB') : ''}</span>
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
  if (funnel.isError) return <ErrorState title="تعذّر تحميل القمع" onRetry={() => funnel.refetch()} />
  if (!stages.length) return <EmptyState title="لا قمع" description="لا توجد بيانات قمع لهذه الحملة في الفترة المحددة." />

  return (
    <div className="space-y-4">
      <ChartCard title={`قمع التحويل — ${campaign.objective}`} subtitle="حسب هدف الحملة">
        <ConversionFunnelChart stages={stages} currency={cur} />
      </ChartCard>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {stages.map((s) => (
          <div key={s.stage} className="rounded-xl border border-border bg-surface p-3">
            <div className="text-[11px] uppercase text-text-muted">{s.label}</div>
            <div className="tnum text-lg font-extrabold">{num(s.count)}</div>
            <div className="flex justify-between text-[11px] text-text-muted">
              <span>تحويل {s.step_rate != null ? percent(s.step_rate * 100, 0) : '—'}</span>
              <span>تكلفة {s.cost_per != null ? money(s.cost_per, cur) : '—'}</span>
            </div>
          </div>
        ))}
      </div>
      {bottleneck && (
        <div className="rounded-xl border border-warning/40 bg-warning/5 p-3 text-sm">
          <span className="font-semibold text-warning">أكبر تسرّب:</span> {bottleneck.label} — {percent((bottleneck.drop_off ?? 0) * 100, 0)}؛ الإجراء المقترح: مراجعة هذه المرحلة (استهداف/محتوى/صفحة الهبوط).
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
  if (activity.isError) return <ErrorState title="تعذّر تحميل النشاط" onRetry={() => activity.refetch()} />
  if (!events.length) return <EmptyState title="لا نشاط" description="لم تُسجَّل أحداث لهذه الحملة بعد." />

  return (
    <ol className="relative space-y-3 border-s border-border ps-4">
      {events.map((e) => (
        <li key={e.id} className="relative">
          <span className="absolute -start-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-500 ring-2 ring-surface" aria-hidden />
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="text-sm font-semibold text-text-primary">{e.label}</span>
            <span className="text-[11px] text-text-muted">{e.actor} · {e.at ? new Date(e.at).toLocaleString('en-GB') : ''}</span>
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
