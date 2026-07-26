import { useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Image as ImageIcon, LayoutGrid, Rows, Trophy } from 'lucide-react'
import {
  ChartCard,
  ConversionFunnelChart,
  KpiSparkline,
  MetricLineChart,
  PlatformDonutChart,
  ProgressRing,
  RankingBarChart,
  SpendRevenueAreaChart,
} from '@/features/analytics/charts'
import { platformColor } from '@/features/analytics/components'
import { compact, money, num, percent, ratio } from '@/features/analytics/format'
import { TrendPill } from '@/features/analytics/components'
import { PerformanceNotice } from '@/features/disclaimers/PerformanceNotice'
import type { ResolvedDisclaimer } from '@/features/disclaimers/api'

interface Slide { id: string; type: string; platform?: string; order: number; visible: boolean }
type Row = Record<string, number | string | null>
interface ReportData {
  period: { from: string; to: string }
  currency: string
  objective?: string
  kpis: Record<string, number | null>
  delta?: Record<string, number | null>
  timeseries: Row[]
  platform_series?: Record<string, Row[]>
  platforms: Row[]
  campaigns: Row[]
  top_creatives?: Row[]
  platform_notes?: Record<string, { strengths: string[]; weaknesses: string[] }>
  best?: { platform_by_roas?: string; platform_by_cpa?: string; platform_by_results?: string; campaign?: string }
  funnel?: Array<{ label: string; count: number; step_rate: number | null; cost_per: number | null }>
  budget?: Row[]
  summary?: string[]
  slides?: Slide[]
  disclaimer?: ResolvedDisclaimer | null
}
interface Meta { reportName: string; clientName?: string; agencyName?: string; platforms: string[]; isDemo?: boolean }

const OBJECTIVE_LABEL: Record<string, string> = {
  sales: 'المبيعات', awareness: 'الوعي', traffic: 'الزيارات', leads: 'العملاء المحتملون', app_installs: 'تثبيت التطبيق', video: 'الفيديو', custom: 'مخصص',
}

export function InteractiveReport({ data, meta }: { data: ReportData; meta: Meta }) {
  const [mode, setMode] = useState<'deck' | 'scroll'>('deck')
  const [i, setI] = useState(0)
  const slides = useMemo(() => {
    const visible = (data.slides ?? []).filter((s) => s.visible).sort((a, b) => a.order - b.order)
    // Always close the deck with a methodology & data-notes section when a disclaimer is present.
    if (data.disclaimer) {
      visible.push({ id: '__methodology', type: '__methodology', order: 9999, visible: true })
    }
    return visible
  }, [data.slides, data.disclaimer])
  const cur = slides[i]

  const render = (s: Slide) => {
    switch (s.type) {
      case 'cover': return <CoverSlide data={data} meta={meta} />
      case 'recommendations': return <RecommendationsSlide data={data} />
      case 'executive_summary': return <ExecutiveSlide data={data} />
      case 'platform_performance': return <PlatformSlide data={data} platform={s.platform!} />
      case 'platform_screenshot': return <ScreenshotSlide platform={s.platform!} />
      case 'top_creatives': return <CreativesSlide data={data} platform={s.platform!} />
      case 'platform_notes': return <NotesSlide data={data} platform={s.platform!} />
      case 'platform_comparison': return <ComparisonSlide data={data} />
      case 'funnel': return <FunnelSlide data={data} />
      case 'budget': return <BudgetSlide data={data} />
      case '__methodology': return <PerformanceNotice data={data.disclaimer} variant="methodology" objective={data.objective} />
      default: return null
    }
  }
  // Short performance note repeated quietly under each slide (footer), except the cover.
  const footer = (s: Slide) => (s.type !== 'cover' && data.disclaimer ? <div className="mt-3 border-t border-border pt-2"><PerformanceNotice data={data.disclaimer} variant="footer" /></div> : null)

  if (slides.length === 0) return <p className="py-8 text-center text-sm text-text-secondary">لا شرائح مرئية.</p>

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <div className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
          <button onClick={() => setMode('deck')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold ${mode === 'deck' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}><LayoutGrid size={15} /> شرائح</button>
          <button onClick={() => setMode('scroll')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold ${mode === 'scroll' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}><Rows size={15} /> صفحة</button>
        </div>
        {mode === 'deck' && (
          <div className="flex items-center gap-2 text-sm text-text-secondary">
            <button onClick={() => setI((v) => Math.max(0, v - 1))} disabled={i === 0} className="flex h-8 w-8 items-center justify-center rounded-lg border border-border disabled:opacity-40 hover:bg-surface-hover"><ChevronRight size={16} /></button>
            <span className="tnum">{i + 1} / {slides.length}</span>
            <button onClick={() => setI((v) => Math.min(slides.length - 1, v + 1))} disabled={i === slides.length - 1} className="flex h-8 w-8 items-center justify-center rounded-lg border border-border disabled:opacity-40 hover:bg-surface-hover"><ChevronLeft size={16} /></button>
          </div>
        )}
      </div>
      {mode === 'deck' ? (
        <div className="min-h-[440px] rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">{cur && render(cur)}{cur && footer(cur)}</div>
      ) : (
        <div className="space-y-4">{slides.map((s) => <div key={s.id} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">{render(s)}{footer(s)}</div>)}</div>
      )}
    </div>
  )
}

function Title({ platform, children, sub }: { platform?: string; children: React.ReactNode; sub?: string }) {
  return (
    <div className="mb-5">
      <div className="flex items-center gap-2">
        {platform && <span className="h-3.5 w-3.5 rounded-full" style={{ background: platformColor(platform) }} />}
        <h2 className="text-2xl font-extrabold tracking-tight text-text-primary">{children}</h2>
      </div>
      {sub && <p className="mt-0.5 text-sm text-text-secondary">{sub}</p>}
    </div>
  )
}

function Kpi({ label, value, delta, invert, spark, accent }: { label: string; value: string; delta?: number | null; invert?: boolean; spark?: number[]; accent?: string }) {
  return (
    <div className="rounded-2xl border border-border bg-surface-secondary p-3.5">
      <div className="flex items-center justify-between">
        <span className="text-sm text-text-secondary">{label}</span>
        {delta !== undefined && <TrendPill delta={delta} invertGood={invert} />}
      </div>
      <div className="tnum mt-1 text-[26px] font-extrabold leading-none tracking-tight text-text-primary">{value}</div>
      {spark && spark.length > 1 && <div className="mt-2"><KpiSparkline points={spark} color={accent} /></div>}
    </div>
  )
}

const seriesOf = (rows: Row[], k: string) => rows.map((r) => Number(r[k] ?? 0))
const pRow = (data: ReportData, p: string) => data.platforms.find((r) => r.provider === p) as Record<string, number> | undefined

function CoverSlide({ data, meta }: { data: ReportData; meta: Meta }) {
  return (
    <div className="flex min-h-[380px] flex-col justify-between overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 via-brand-600 to-brand-700 p-8 text-white">
      <div className="flex items-center justify-between">
        <span className="rounded-lg bg-white/15 px-3 py-1 text-sm font-bold">{meta.agencyName ?? 'CampaignsHub'}</span>
        {meta.isDemo && <span className="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">بيانات تجريبية · Demo</span>}
      </div>
      <div>
        <div className="text-sm opacity-80">{meta.clientName ?? 'تقرير الأداء'}</div>
        <h1 className="mt-1 text-4xl font-extrabold sm:text-5xl">{meta.reportName}</h1>
        <div className="mt-3 flex flex-wrap gap-2">{meta.platforms.map((p) => <span key={p} className="rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold">{p}</span>)}</div>
      </div>
      <div className="flex flex-wrap gap-4 text-sm opacity-90">
        <span>الفترة: <span className="tnum">{data.period.from} → {data.period.to}</span></span>
        {data.objective && <span>الهدف: {OBJECTIVE_LABEL[data.objective] ?? data.objective}</span>}
        <span>العملة: {data.currency}</span>
      </div>
    </div>
  )
}

function RecommendationsSlide({ data }: { data: ReportData }) {
  return (
    <div>
      <Title>الملاحظات والتوصيات</Title>
      {(data.summary?.length ?? 0) > 0 ? (
        <ul className="space-y-2">
          {data.summary!.map((line, idx) => (
            <li key={idx} className="flex gap-3 rounded-xl border border-border bg-surface-secondary p-3.5 text-sm">
              <span className="mt-0.5 h-6 w-6 shrink-0 rounded-full bg-brand-100 text-center text-xs font-bold leading-6 text-brand-700">{idx + 1}</span>
              <span className="leading-relaxed">{line}</span>
            </li>
          ))}
        </ul>
      ) : <p className="text-sm text-text-muted">لا ملاحظات مضافة بعد.</p>}
      {data.disclaimer && <div className="mt-5"><PerformanceNotice data={data.disclaimer} variant="full" objective={data.objective} /></div>}
    </div>
  )
}

function ExecutiveSlide({ data }: { data: ReportData }) {
  const k = data.kpis
  const d = data.delta ?? {}
  const donut = data.platforms.map((p) => ({ name: String(p.provider), value: Number(p.spend ?? 0) }))
  const totalSpend = donut.reduce((a, b) => a + b.value, 0)
  return (
    <div>
      <Title sub="نظرة سريعة على أداء الحملة خلال الفترة">الملخص التنفيذي</Title>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <Kpi label="الإنفاق" value={money(k.spend, data.currency)} delta={d.spend} invert spark={seriesOf(data.timeseries, 'spend')} accent="var(--brand-600)" />
        <Kpi label="الإيرادات" value={money(k.revenue, data.currency)} delta={d.revenue} spark={seriesOf(data.timeseries, 'revenue')} accent="var(--info)" />
        <Kpi label="ROAS" value={ratio(k.roas ?? null)} delta={d.roas} spark={seriesOf(data.timeseries, 'roas')} accent="var(--info)" />
        <Kpi label="النتائج" value={num(k.conversions)} delta={d.conversions} spark={seriesOf(data.timeseries, 'conversions')} accent="var(--purple)" />
        <Kpi label="CPA" value={money(k.cpa, data.currency)} delta={d.cpa} invert spark={seriesOf(data.timeseries, 'cpa')} accent="var(--purple)" />
        <Kpi label="CTR" value={percent(k.ctr, 2)} delta={d.ctr} spark={seriesOf(data.timeseries, 'ctr')} accent="var(--teal)" />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <ChartCard title="الإنفاق مقابل الإيرادات" subtitle="الاتجاه اليومي" className="lg:col-span-2"><SpendRevenueAreaChart data={data.timeseries} currency={data.currency} /></ChartCard>
        <ChartCard title="توزيع الإنفاق" subtitle="حسب المنصة"><PlatformDonutChart data={donut} centerLabel="إجمالي الإنفاق" centerValue={compact(totalSpend)} currency={data.currency} /></ChartCard>
      </div>
      <div className="mt-3 grid gap-2 sm:grid-cols-3">
        <Highlight label="أفضل منصة (ROAS)" value={data.best?.platform_by_roas ?? '—'} />
        <Highlight label="أقل CPA" value={data.best?.platform_by_cpa ?? '—'} />
        <Highlight label="أفضل حملة" value={data.best?.campaign ?? '—'} />
      </div>
    </div>
  )
}

function Highlight({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary p-3">
      <Trophy size={16} className="text-brand-600" />
      <div><div className="text-xs text-text-muted">{label}</div><div className="font-bold text-text-primary">{value}</div></div>
    </div>
  )
}

function PlatformSlide({ data, platform }: { data: ReportData; platform: string }) {
  const p = pRow(data, platform)
  const series = data.platform_series?.[platform] ?? []
  const campaigns = data.campaigns.filter((c) => c.provider === platform).slice(0, 6).map((c) => ({ label: String(c.campaign_name ?? '—'), spend: Number(c.spend ?? 0), platform }))
  if (!p) return <Title platform={platform}>{platform}</Title>
  const kpis: Array<[string, string, string]> = [
    ['الإنفاق', money(p.spend, data.currency), 'var(--brand-600)'], ['الإيرادات', money(p.revenue, data.currency), 'var(--info)'],
    ['ROAS', ratio(p.roas), 'var(--info)'], ['النتائج', num(p.conversions), 'var(--purple)'],
    ['CPA', money(p.cpa, data.currency), 'var(--purple)'], ['CTR', percent(p.ctr, 2), 'var(--teal)'],
  ]
  return (
    <div>
      <Title platform={platform} sub={`أداء ${platform} خلال الفترة`}>أداء {platform}</Title>
      <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-6">
        {kpis.map(([l, v, c]) => <Kpi key={l} label={l} value={v} spark={seriesOf(series, l === 'ROAS' ? 'roas' : l === 'الإنفاق' ? 'spend' : l === 'الإيرادات' ? 'revenue' : l === 'النتائج' ? 'conversions' : l === 'CPA' ? 'cpa' : 'ctr')} accent={c} />)}
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <ChartCard title="الأداء بمرور الوقت" subtitle="الإنفاق والإيرادات والنتائج">
          <MetricLineChart data={series} currency={data.currency} series={[{ key: 'spend', name: 'الإنفاق', color: 'var(--brand-600)', kind: 'money' }, { key: 'revenue', name: 'الإيرادات', color: 'var(--info)', kind: 'money' }, { key: 'conversions', name: 'النتائج', color: 'var(--purple)', kind: 'num' }]} height={240} />
        </ChartCard>
        <ChartCard title="أفضل الحملات" subtitle="حسب الإنفاق">
          {campaigns.length > 0 ? <RankingBarChart data={campaigns} bars={[{ key: 'spend', name: 'الإنفاق', kind: 'money' }]} horizontal height={240} colorByPlatform /> : <p className="py-10 text-center text-sm text-text-muted">لا حملات.</p>}
        </ChartCard>
      </div>
      <p className="mt-3 text-xs text-text-muted">Reach يُعرض لكل منصة على حدة ولا يُجمع كوصول فريد بين المنصات.</p>
    </div>
  )
}

function ScreenshotSlide({ platform }: { platform: string }) {
  return (
    <div>
      <Title platform={platform}>لقطات {platform}</Title>
      <div className="flex h-64 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border text-text-muted">
        <ImageIcon size={28} />
        <p className="text-sm">لم تُرفع لقطات بعد — تُضاف يدويًا من محرّر التقرير.</p>
        <p className="text-xs">اللقطة مرجعية بصرية؛ أرقام الأداء مصدرها API.</p>
      </div>
    </div>
  )
}

function CreativesSlide({ data, platform }: { data: ReportData; platform: string }) {
  const items = (data.top_creatives ?? []).filter((c) => c.provider === platform).slice(0, 3)
  const medal = ['from-amber-400 to-amber-600', 'from-slate-300 to-slate-500', 'from-orange-400 to-orange-600']
  return (
    <div>
      <Title platform={platform} sub="مُرتّبة حسب هدف الحملة مع سبب التصنيف">أفضل المحتويات — {platform}</Title>
      {items.length === 0 ? <p className="text-sm text-text-muted">لا محتويات مصنّفة لهذه المنصة.</p> : (
        <div className="grid gap-3 sm:grid-cols-3">
          {items.map((c, idx) => (
            <div key={idx} className="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface-secondary">
              <div className={`flex h-28 items-center justify-center bg-gradient-to-br ${medal[idx] ?? medal[2]} text-white`}>
                <span className="text-3xl font-extrabold">#{idx + 1}</span>
              </div>
              <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="truncate font-bold text-text-primary" title={String(c.campaign_name ?? '')}>{String(c.campaign_name ?? '—')}</div>
                <div className="grid grid-cols-2 gap-1.5 text-xs">
                  <span className="rounded-lg bg-surface px-2 py-1">ROAS <b className="tnum">{ratio(c.roas as number)}</b></span>
                  <span className="rounded-lg bg-surface px-2 py-1">CPA <b className="tnum">{money(c.cpa as number, data.currency)}</b></span>
                  <span className="rounded-lg bg-surface px-2 py-1">إنفاق <b className="tnum">{compact(Number(c.spend ?? 0))}</b></span>
                  <span className="rounded-lg bg-surface px-2 py-1">نتائج <b className="tnum">{num(Number(c.conversions ?? 0))}</b></span>
                </div>
                <div className="mt-auto rounded-lg bg-[var(--brand-background)] px-2 py-1.5 text-xs text-brand-700">{String(c.reason ?? '')}</div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function NotesSlide({ data, platform }: { data: ReportData; platform: string }) {
  const n = data.platform_notes?.[platform]
  return (
    <div>
      <Title platform={platform}>ملاحظات {platform}</Title>
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-2xl border border-border bg-[var(--positive-background)] p-4">
          <h4 className="mb-2 text-sm font-bold text-success">نقاط القوة</h4>
          {(n?.strengths.length ?? 0) > 0 ? <ul className="list-disc space-y-1 ps-5 text-sm">{n!.strengths.map((s, idx) => <li key={idx}>{s}</li>)}</ul> : <p className="text-xs text-text-muted">—</p>}
        </div>
        <div className="rounded-2xl border border-border bg-[var(--warning-background)] p-4">
          <h4 className="mb-2 text-sm font-bold text-warning">تحتاج تحسينًا</h4>
          {(n?.weaknesses.length ?? 0) > 0 ? <ul className="list-disc space-y-1 ps-5 text-sm">{n!.weaknesses.map((s, idx) => <li key={idx}>{s}</li>)}</ul> : <p className="text-xs text-text-muted">—</p>}
        </div>
      </div>
      <p className="mt-2 text-xs text-text-muted">اقتراحات آلية — يعتمدها المستخدم قبل ظهورها للعميل.</p>
    </div>
  )
}

function ComparisonSlide({ data }: { data: ReportData }) {
  const bars = data.platforms.map((p) => ({ label: String(p.provider), platform: String(p.provider), spend: Number(p.spend ?? 0) }))
  const donut = data.platforms.map((p) => ({ name: String(p.provider), value: Number(p.spend ?? 0) }))
  return (
    <div>
      <Title sub="الإنفاق والعائد والمساهمة عبر المنصات">مقارنة المنصات</Title>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title="الإنفاق حسب المنصة"><RankingBarChart data={bars} bars={[{ key: 'spend', name: 'الإنفاق', kind: 'money' }]} colorByPlatform height={240} currency={data.currency} /></ChartCard>
        <ChartCard title="مساهمة الإنفاق"><PlatformDonutChart data={donut} centerLabel="الإجمالي" centerValue={compact(donut.reduce((a, b) => a + b.value, 0))} currency={data.currency} /></ChartCard>
      </div>
      <ChartCard title="ترتيب المنصات" className="mt-4">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead><tr className="border-b border-border text-text-muted"><th className="py-2 text-start">المنصة</th><th className="py-2 text-end">الإنفاق</th><th className="py-2 text-end">النتائج</th><th className="py-2 text-end">CPA</th><th className="py-2 text-end">ROAS</th><th className="py-2 text-end">المساهمة</th></tr></thead>
            <tbody>
              {data.platforms.map((p, idx) => (
                <tr key={idx} className="border-b border-border last:border-0">
                  <td className="py-2"><span className="inline-flex items-center gap-1.5 font-semibold"><span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(String(p.provider)) }} />{String(p.provider)}</span></td>
                  <td className="tnum py-2 text-end">{money(p.spend as number, data.currency)}</td>
                  <td className="tnum py-2 text-end">{num(p.conversions as number)}</td>
                  <td className="tnum py-2 text-end">{money(p.cpa as number, data.currency)}</td>
                  <td className="tnum py-2 text-end font-semibold">{ratio(p.roas as number)}</td>
                  <td className="tnum py-2 text-end">{percent(p.spend_share as number, 1)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ChartCard>
    </div>
  )
}

function FunnelSlide({ data }: { data: ReportData }) {
  return (
    <div>
      <Title sub="من الظهور إلى الشراء — معدل الانتقال وتكلفة كل مرحلة">قمع التحويل</Title>
      {(data.funnel?.length ?? 0) > 0 ? <ConversionFunnelChart stages={data.funnel!} currency={data.currency} /> : <p className="text-sm text-text-muted">لا بيانات قمع.</p>}
    </div>
  )
}

function BudgetSlide({ data }: { data: ReportData }) {
  const rows = (data.budget ?? []).slice(0, 8)
  const totalBudget = rows.reduce((a, b) => a + Number(b.budget ?? 0), 0)
  const totalSpent = rows.reduce((a, b) => a + Number(b.spent ?? 0), 0)
  const consumed = totalBudget > 0 ? totalSpent / totalBudget : 0
  const bars = rows.map((r) => ({ label: String(r.campaign_name ?? '—'), budget: Number(r.budget ?? 0), spent: Number(r.spent ?? 0) }))
  return (
    <div>
      <Title sub="المخطط مقابل المصروف وسرعة الصرف">تحليل الميزانية</Title>
      <div className="grid gap-4 lg:grid-cols-3">
        <ChartCard title="استهلاك الميزانية" className="flex items-center justify-center">
          <ProgressRing value={consumed} sublabel={`${compact(totalSpent)} / ${compact(totalBudget)}`} size={150} tone={consumed > 0.95 ? 'danger' : consumed > 0.8 ? 'warning' : 'brand'} />
        </ChartCard>
        <ChartCard title="المخطط مقابل المصروف" className="lg:col-span-2">
          <RankingBarChart data={bars} bars={[{ key: 'budget', name: 'الميزانية', color: 'var(--border-strong)', kind: 'money' }, { key: 'spent', name: 'المصروف', color: 'var(--brand-600)', kind: 'money' }]} horizontal height={220} currency={data.currency} />
        </ChartCard>
      </div>
    </div>
  )
}
