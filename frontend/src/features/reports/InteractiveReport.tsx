import { useMemo, useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChevronLeft, ChevronRight, Image as ImageIcon, LayoutGrid, Rows } from 'lucide-react'
import { platformColor, tooltipProps } from '@/features/analytics/components'
import { compact, money, num, percent, ratio } from '@/features/analytics/format'

interface Slide {
  id: string
  type: string
  platform?: string
  order: number
  visible: boolean
}
interface ReportData {
  period: { from: string; to: string }
  currency: string
  objective?: string
  kpis: Record<string, number | null>
  delta?: Record<string, number | null>
  platforms: Array<Record<string, unknown>>
  top_creatives?: Array<Record<string, unknown>>
  platform_notes?: Record<string, { strengths: string[]; weaknesses: string[] }>
  summary?: string[]
  slides?: Slide[]
}
interface Meta {
  reportName: string
  clientName?: string
  agencyName?: string
  platforms: string[]
  isDemo?: boolean
}

const OBJECTIVE_LABEL: Record<string, string> = {
  sales: 'المبيعات', awareness: 'الوعي', traffic: 'الزيارات', leads: 'العملاء المحتملون', app_installs: 'تثبيت التطبيق', video: 'الفيديو', custom: 'مخصص',
}

/** Renders a report's snapshot as interactive slides (presentation) or a long scroll. Data-driven. */
export function InteractiveReport({ data, meta }: { data: ReportData; meta: Meta }) {
  const [mode, setMode] = useState<'deck' | 'scroll'>('deck')
  const slides = useMemo(
    () => (data.slides ?? []).filter((s) => s.visible).sort((a, b) => a.order - b.order),
    [data.slides],
  )
  const [i, setI] = useState(0)
  const current = slides[i]

  const render = (s: Slide) => {
    switch (s.type) {
      case 'cover':
        return <CoverSlide data={data} meta={meta} />
      case 'recommendations':
        return <RecommendationsSlide data={data} />
      case 'platform_performance':
        return <PlatformPerformanceSlide data={data} platform={s.platform!} />
      case 'platform_screenshot':
        return <ScreenshotSlide platform={s.platform!} />
      case 'top_creatives':
        return <TopCreativesSlide data={data} platform={s.platform!} />
      case 'platform_notes':
        return <PlatformNotesSlide data={data} platform={s.platform!} />
      default:
        return null
    }
  }

  if (slides.length === 0) return <p className="py-8 text-center text-sm text-text-secondary">لا شرائح مرئية.</p>

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <div className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
          <button onClick={() => setMode('deck')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold ${mode === 'deck' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}>
            <LayoutGrid size={15} /> شرائح
          </button>
          <button onClick={() => setMode('scroll')} className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold ${mode === 'scroll' ? 'bg-surface shadow-[var(--shadow-small)]' : 'text-text-secondary'}`}>
            <Rows size={15} /> صفحة
          </button>
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
        <div className="min-h-[420px] rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">{current && render(current)}</div>
      ) : (
        <div className="space-y-4">
          {slides.map((s) => (
            <div key={s.id} className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">{render(s)}</div>
          ))}
        </div>
      )}
    </div>
  )
}

function SlideTitle({ platform, children }: { platform?: string; children: React.ReactNode }) {
  return (
    <div className="mb-4 flex items-center gap-2">
      {platform && <span className="h-3 w-3 rounded-full" style={{ background: platformColor(platform) }} />}
      <h2 className="text-xl font-extrabold tracking-tight text-text-primary">{children}</h2>
    </div>
  )
}

function CoverSlide({ data, meta }: { data: ReportData; meta: Meta }) {
  return (
    <div className="flex min-h-[360px] flex-col justify-between rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-8 text-white">
      <div className="flex items-center justify-between">
        <span className="rounded-lg bg-white/15 px-3 py-1 text-sm font-bold">{meta.agencyName ?? 'CampaignsHub'}</span>
        {meta.isDemo && <span className="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">بيانات تجريبية · Demo</span>}
      </div>
      <div>
        <div className="text-sm opacity-80">{meta.clientName ?? 'تقرير الأداء'}</div>
        <h1 className="mt-1 text-4xl font-extrabold">{meta.reportName}</h1>
        <div className="mt-3 flex flex-wrap gap-2">
          {meta.platforms.map((p) => (
            <span key={p} className="rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold">{p}</span>
          ))}
        </div>
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
      <SlideTitle>الملاحظات والتوصيات</SlideTitle>
      {(data.summary?.length ?? 0) > 0 ? (
        <div className="space-y-4">
          <div>
            <h3 className="mb-2 text-sm font-bold text-text-secondary">أبرز النتائج</h3>
            <ul className="space-y-2">
              {data.summary!.map((line, idx) => (
                <li key={idx} className="flex gap-2 rounded-xl border border-border bg-surface-secondary p-3 text-sm">
                  <span className="mt-0.5 h-5 w-5 shrink-0 rounded-full bg-brand-100 text-center text-xs font-bold leading-5 text-brand-700">{idx + 1}</span>
                  {line}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : (
        <p className="text-sm text-text-muted">لا ملاحظات مضافة بعد.</p>
      )}
    </div>
  )
}

function platformRow(data: ReportData, platform: string) {
  return data.platforms.find((p) => p.provider === platform) as Record<string, number> | undefined
}

function PlatformPerformanceSlide({ data, platform }: { data: ReportData; platform: string }) {
  const p = platformRow(data, platform)
  if (!p) return <SlideTitle platform={platform}>{platform}</SlideTitle>
  const kpis: Array<[string, string]> = [
    ['الإنفاق', money(p.spend, data.currency)],
    ['الإيرادات', money(p.revenue, data.currency)],
    ['ROAS', ratio(p.roas)],
    ['النتائج', num(p.conversions)],
    ['CPA', money(p.cpa, data.currency)],
    ['CTR', percent(p.ctr, 2)],
    ['CPC', money(p.cpc, data.currency)],
    ['CPM', money(p.cpm, data.currency)],
  ]
  return (
    <div>
      <SlideTitle platform={platform}>أداء {platform}</SlideTitle>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {kpis.map(([l, v]) => (
          <div key={l} className="rounded-xl border border-border bg-surface-secondary p-3">
            <div className="text-xs text-text-muted">{l}</div>
            <div className="tnum mt-0.5 text-lg font-bold text-text-primary">{v}</div>
          </div>
        ))}
      </div>
      <p className="mt-3 text-xs text-text-muted">Reach يُعرض لكل منصة على حدة ولا يُجمع كوصول فريد بين المنصات.</p>
    </div>
  )
}

function ScreenshotSlide({ platform }: { platform: string }) {
  return (
    <div>
      <SlideTitle platform={platform}>لقطات {platform}</SlideTitle>
      <div className="flex h-56 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border text-text-muted">
        <ImageIcon size={26} />
        <p className="text-sm">لم تُرفع لقطات بعد — تُضاف يدويًا من محرّر التقرير.</p>
        <p className="text-xs">اللقطة مرجعية بصرية؛ أرقام الأداء مصدرها API.</p>
      </div>
    </div>
  )
}

function TopCreativesSlide({ data, platform }: { data: ReportData; platform: string }) {
  const items = (data.top_creatives ?? []).filter((c) => c.provider === platform)
  return (
    <div>
      <SlideTitle platform={platform}>أفضل المحتويات — {platform}</SlideTitle>
      {items.length === 0 ? (
        <p className="text-sm text-text-muted">لا محتويات مصنّفة لهذه المنصة.</p>
      ) : (
        <div className="space-y-2">
          {items.map((c, idx) => (
            <div key={idx} className="flex items-center gap-3 rounded-xl border border-border bg-surface-secondary p-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-100 text-sm font-bold text-brand-700">{idx + 1}</span>
              <div className="min-w-0 flex-1">
                <div className="truncate font-semibold text-text-primary">{String(c.campaign_name ?? '—')}</div>
                <div className="text-xs text-text-secondary">{String(c.reason ?? '')}</div>
              </div>
              <div className="tnum text-end text-sm">
                <div className="font-bold">{ratio(c.roas as number)}</div>
                <div className="text-xs text-text-muted">{money(c.spend as number, data.currency)}</div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function PlatformNotesSlide({ data, platform }: { data: ReportData; platform: string }) {
  const n = data.platform_notes?.[platform]
  return (
    <div>
      <SlideTitle platform={platform}>ملاحظات {platform}</SlideTitle>
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-xl border border-border bg-[var(--positive-background)] p-3">
          <h4 className="mb-1 text-sm font-bold text-success">نقاط القوة</h4>
          {(n?.strengths.length ?? 0) > 0 ? (
            <ul className="list-disc space-y-1 ps-5 text-sm">{n!.strengths.map((s, i) => <li key={i}>{s}</li>)}</ul>
          ) : <p className="text-xs text-text-muted">—</p>}
        </div>
        <div className="rounded-xl border border-border bg-[var(--warning-background)] p-3">
          <h4 className="mb-1 text-sm font-bold text-warning">تحتاج تحسينًا</h4>
          {(n?.weaknesses.length ?? 0) > 0 ? (
            <ul className="list-disc space-y-1 ps-5 text-sm">{n!.weaknesses.map((s, i) => <li key={i}>{s}</li>)}</ul>
          ) : <p className="text-xs text-text-muted">—</p>}
        </div>
      </div>
      <p className="mt-2 text-xs text-text-muted">هذه اقتراحات آلية — يعتمدها المستخدم قبل ظهورها للعميل.</p>
    </div>
  )
}

// unused chart helper kept for platform comparison usage
export function MiniPlatformBars({ data }: { data: ReportData }) {
  return (
    <ResponsiveContainer width="100%" height={200}>
      <BarChart data={data.platforms}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
        <XAxis dataKey="provider" tick={{ fontSize: 12, fill: 'var(--text-muted)' }} />
        <YAxis tick={{ fontSize: 12, fill: 'var(--text-muted)' }} tickFormatter={(v) => compact(Number(v))} />
        <Tooltip {...tooltipProps} />
        <Bar dataKey="spend" radius={[6, 6, 0, 0]}>
          {data.platforms.map((p) => <Cell key={String(p.provider)} fill={platformColor(String(p.provider))} />)}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  )
}
