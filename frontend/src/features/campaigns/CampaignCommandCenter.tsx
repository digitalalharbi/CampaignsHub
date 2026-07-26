import { useMemo } from 'react'
import { TrendingDown, TrendingUp } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import { useCampaignPerformance, useCampaignPlatforms, useCampaignSummary } from './metrics'
import type { MetricTotals, Range, TimePoint } from '@/features/analytics/api'
import { ChartCard, KpiSparkline, MetricLineChart, PlatformDonutChart, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { compact, money, num, percent, ratio, trend } from '@/features/analytics/format'
import { Skeleton } from '@/components/ui/States'
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
