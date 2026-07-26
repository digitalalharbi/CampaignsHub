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
import type { MetricTotals, PlatformRow, Range, TimePoint } from '@/features/analytics/api'
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

/** CMC-7 — Platforms tab: one rich card per LINKED platform (metrics + externals + last sync). */
export function CampaignPlatformsTab({
  campaign, projectId, range, locale, linked, onLink, onUnlink, unlinkingId, canUpdate,
}: {
  campaign: UnifiedCampaign; projectId: string; range: Range; locale: Locale
  linked: import('./types').ExternalCampaign[]; onLink: () => void
  onUnlink: (externalId: string) => void; unlinkingId?: string; canUpdate: boolean
}) {
  const platforms = useCampaignPlatforms(projectId, campaign.id, range)
  const cur = campaign.budget_currency || 'SAR'

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
                <span className="text-[11px] text-text-muted">آخر مزامنة: {lastSync ? new Date(lastSync).toLocaleDateString('en-GB') : '—'}</span>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <MiniStat label="الإنفاق" value={money(Number(m?.spend ?? 0), cur)} />
                <MiniStat label="النتائج" value={num(m?.conversions ?? 0)} />
                <MiniStat label="CPA" value={money(m?.cpa ?? null, cur)} />
                <MiniStat label="ROAS" value={ratio(m?.roas ?? null)} />
                <MiniStat label="المساهمة" value={m?.spend_share != null ? percent(m.spend_share * 100, 0) : '—'} />
                <MiniStat label="CTR" value={percent((m?.ctr ?? 0) * 100)} />
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

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-secondary p-2">
      <div className="text-[10px] uppercase text-text-muted">{label}</div>
      <div className="tnum text-sm font-bold text-text-primary">{value}</div>
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
        : alerts.isError ? <ErrorState title="تعذّر تحميل التنبيهات" onRetry={() => alerts.refetch()} />
        : rows.length === 0 ? <EmptyState title="لا تنبيهات" description="لا توجد تنبيهات لهذه الحملة ضمن هذا الفلتر." />
        : (
          <ul className="space-y-2">
            {rows.map((a) => (
              <li key={a.id} className={`rounded-xl border p-3 ${sevTone[a.severity] ?? sevTone.info}`}>
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-bold">{a.title}</span>
                  <span className="text-[11px] opacity-70">{a.created_at ? new Date(a.created_at).toLocaleString('en-GB') : ''}</span>
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
  if (reports.isError) return <ErrorState title="تعذّر تحميل التقارير" onRetry={() => reports.refetch()} />
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
              {r.last_sent_at && <span>· أُرسل {new Date(r.last_sent_at).toLocaleDateString('en-GB')}</span>}
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
  if (annotations.isError) return <ErrorState title="تعذّر تحميل الملاحظات" onRetry={() => annotations.refetch()} />

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
  const cur = campaign.budget_currency || 'SAR'
  const rows = creatives.data ?? []

  if (creatives.isLoading) return <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-56" />)}</div>
  if (creatives.isError) return <ErrorState title="تعذّر تحميل المحتويات" onRetry={() => creatives.refetch()} />
  if (rows.length === 0) return <EmptyState title="لا محتويات" description="لا توجد محتويات مزامنة لهذه الحملة بعد. تظهر تلقائيًا بعد مزامنة الإعلانات من المنصة." />

  const cls = (k: string) => CREATIVE_CLASS[k] ?? CREATIVE_CLASS.insufficient_data
  const Preview = ({ c }: { c: import('./metrics').CampaignCreative }) => (
    c.has_preview && c.thumbnail_url
      ? <img src={c.thumbnail_url} alt="" className="h-32 w-full rounded-lg object-cover" loading="lazy" />
      : <div className="flex h-32 w-full items-center justify-center rounded-lg bg-surface-secondary text-center text-[11px] text-text-muted">معاينة المحتوى غير متاحة من مصدر المنصة</div>
  )

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <span className="text-xs text-text-muted">مرتّبة حسب هدف الحملة ({campaign.objective})</span>
        <div className="flex gap-1">
          {(['grid', 'table'] as const).map((v) => (
            <button key={v} onClick={() => setView(v)} className={`rounded-lg px-2.5 py-1 text-xs font-semibold ${view === v ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`}>{v === 'grid' ? 'شبكة' : 'جدول'}</button>
          ))}
        </div>
      </div>

      {view === 'grid' ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((c) => (
            <div key={c.id} className="space-y-2 rounded-2xl border border-border bg-surface p-3">
              <Preview c={c} />
              <div className="flex items-start justify-between gap-2">
                <span className="truncate text-sm font-bold text-text-primary">{c.client_display_name || c.name}</span>
                <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls(c.classification).tone}`}>{cls(c.classification).label}</span>
              </div>
              <div className="flex items-center gap-2 text-[11px] text-text-muted">{providerLabel(c.provider, locale)} · {c.format}{c.is_demo && <span className="rounded bg-warning/15 px-1 text-warning">Demo</span>}</div>
              <div className="grid grid-cols-3 gap-1.5 text-center">
                <MiniStat label="الإنفاق" value={money(c.metrics.spend, cur)} />
                <MiniStat label="النتائج" value={num(c.metrics.conversions)} />
                <MiniStat label="ROAS" value={ratio(c.metrics.roas)} />
                <MiniStat label="CPA" value={money(c.metrics.cpa, cur)} />
                <MiniStat label="CTR" value={percent((c.metrics.ctr ?? 0) * 100)} />
                <MiniStat label="مشاهدة" value={c.metrics.view_rate != null ? percent(c.metrics.view_rate * 100, 0) : '—'} />
              </div>
              <p className="text-[11px] text-text-muted">{c.ranking_reason}</p>
            </div>
          ))}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface-secondary text-xs text-text-muted"><tr>{['المحتوى', 'المنصة', 'الإنفاق', 'النتائج', 'CPA', 'ROAS', 'CTR', 'التصنيف'].map((h) => <th key={h} className="p-2 text-start font-semibold">{h}</th>)}</tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id} className="border-t border-border">
                  <td className="p-2 font-semibold">{c.client_display_name || c.name}</td>
                  <td className="p-2 text-text-muted">{providerLabel(c.provider, locale)}</td>
                  <td className="tnum p-2">{money(c.metrics.spend, cur)}</td>
                  <td className="tnum p-2">{num(c.metrics.conversions)}</td>
                  <td className="tnum p-2">{money(c.metrics.cpa, cur)}</td>
                  <td className="tnum p-2">{ratio(c.metrics.roas)}</td>
                  <td className="tnum p-2">{percent((c.metrics.ctr ?? 0) * 100)}</td>
                  <td className="p-2"><span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls(c.classification).tone}`}>{cls(c.classification).label}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
