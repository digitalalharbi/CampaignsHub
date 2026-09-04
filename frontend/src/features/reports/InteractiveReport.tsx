import { DataMetricTable } from '@/components/ui/MetricTable'
import { useMemo, useState } from 'react'
import { attributionWindow } from './attributionWindow'
import { ReportAdDetail } from './ReportAdDetail'
import { ReportAdsSection, type AdGroup, type AdsReading, type ReportAd } from './ReportAdsSection'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { fmtDateTime } from '@/lib/datetime'
import { ArrowRight, ChevronLeft, ChevronRight, CircleCheck, Image as ImageIcon, Info, LayoutGrid, OctagonAlert, Rows, TriangleAlert, Trophy } from 'lucide-react'
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
import { compact, money, moneyExact, num, percent, ratio } from '@/features/analytics/format'
import { TrendPill } from '@/features/analytics/components'
import { PerformanceNotice } from '@/features/disclaimers/PerformanceNotice'
import type { ResolvedDisclaimer } from '@/features/disclaimers/api'
import type { MetricReading } from '@/components/ui/MetricStrip'
import { SPECS } from '@/features/analytics/metricCatalog'
import { type ReportMetric, creativeReadings, previousReading, reportMetrics, trendSeries } from './reportMetrics'
import { useUi } from '@/stores/ui'
import { campaigns as countedCampaigns } from '@/lib/counted'
import { ReportOutline } from './ReportOutline'

export interface Slide { id: string; type: string; platform?: string; order: number; visible: boolean }
type Row = Record<string, number | string | null>
export interface ReportSection {
  key: string
  title_ar: string
  title_en: string
  present: boolean
  /** The figures this section presents — absent when the section is. */
  figures?: string[]
  /** Why this section shows a figure an earlier one already showed. */
  repeat_reason?: string
  absent_reason?: string
  absent_reason_ar?: string
  absent_reason_en?: string
}

export interface ReportData {
  period: { from: string; to: string }
  currency: string
  objective?: string
  kpis: Record<string, number | null>
  delta?: Record<string, number | null>
  /**
   * §14.6 — the metrics this report leads with, chosen by the objective at generation time.
   *
   * Read from the snapshot rather than recomputed, so a link opened a month later shows the report
   * that was sent. Absent on snapshots written before this existed; `reportMetrics` falls back to
   * the catalogue's layout for the objective.
   */
  metric_set?: string[]
  /**
   * Which base metrics any connected platform actually SENT over the window.
   *
   * The pivot coalesces to 0, so without this a metric no platform publishes is indistinguishable
   * from a measured zero — «الوصول 0» on a brand report whose platforms simply do not report reach.
   */
  reported?: Record<string, boolean>
  /** The same answer per platform — Meta publishes reach and X does not. */
  reported_by_platform?: Record<string, Record<string, boolean>>
  timeseries: Row[]
  platform_series?: Record<string, Row[]>
  platforms: Row[]
  campaigns: Row[]
  top_creatives?: Row[]
  /**
   * REPORT-AD-PREVIEW-001 — the ADS, with the media that ran with them.
   *
   * `top_creatives` above ranks CAMPAIGNS and has said so since it was written. These are ad-level
   * rows carrying the canonical preview block, so an ad whose file the platform withheld says the
   * same sentence here as in the library.
   */
  ads?: ReportAd[]
  ads_level?: string | null
  ads_absent_reason?: string | null
  /** REPORT-AD-PREVIEW-001 §A — ranked inside each objective, with the metric that ordered it. */
  ads_groups?: AdGroup[]
  /** The five-step reading of the ranked grid — absent where no range could be read. */
  ads_reading?: AdsReading
  /** REPORT-WORST-CREATIVES-001 — measured underperformers, never merely unmeasured ones. */
  worst_creatives?: Row[]
  platform_notes?: Record<string, { strengths: string[]; weaknesses: string[] }>
  /**
   * The leader board, ranked on the metric this report's money was buying (§14.6).
   *
   * `basis` names that metric. The three `platform_by_*` keys are populated only where they mean
   * something — null on a brand report, which used to crown a «best platform (ROAS)» chosen by
   * whichever row a sort over a column of nulls happened to return first.
   */
  best?: {
    basis?: { key: string; label_ar: string }
    platform?: string | null
    platform_value?: string | null
    platform_by_roas?: string | null
    platform_by_cpa?: string | null
    platform_by_results?: string | null
    campaign?: string
  }
  /** FUNNEL-NULL-001 — `count` is null for a stage no platform reported; `reported` says which. */
  funnel?: Array<{ stage?: string; label: string; reported?: boolean; count: number | null; step_rate: number | null; cost_per: number | null }>
  budget?: Row[]
  /** The previous window of the same length — what «‎+28%» is a change FROM. */
  previous?: Record<string, number | null>
  /**
   * §14.7 — what this report's own figures say, each one derived and none of it generic copy.
   *
   * Empty is a real answer: a period with nothing alarming in it produces no notes, and the slide
   * says so rather than filling the space.
   */
  observations?: Observation[]
  /** §14.10 — how current these figures are, and whether any source failed while the period ran. */
  freshness?: {
    state?: string
    last_sync_at?: string | null
    missing_days?: number | null
    sync_failed?: boolean
    sources?: Array<{ name?: string | null; provider?: string | null; state?: string; last_sync_at?: string | null }>
    failing?: Array<{ name?: string | null; provider?: string | null }>
  }
  summary?: string[]
  /**
   * REPORT-ANALYTICAL-DEPTH-001 — what this report contains, and why anything is missing.
   *
   * Derived server-side from the assembled snapshot AFTER every figure is in place, so the contents
   * cannot promise a section the report does not have. A section that is not supported by the
   * evidence is absent rather than present-and-empty, and carries the reason: «Findings» over an
   * empty state tells a client the analysis failed, which is a different statement from «nothing in
   * this period was worth reporting».
   *
   * Called `outline` and not `sections` because a SHARE already has `sections` — the toggles an
   * operator sets on a link. Two different things under one word on the same screen is how a reader
   * ends up believing the share settings decide the analysis.
   *
   * Optional because a snapshot written before this existed has no outline; a reader that finds none
   * simply renders what it always did.
   */
  outline?: ReportSection[]
  findings?: NoteCardData[]
  recommendations?: NoteCardData[]
  next_steps?: NextStep[]
  audience?: string
  /**
   * REPORT-OBJECTIVE-003 — spend and results split by marketing path, Direct apart from Blended.
   *
   * `kpis` above is the whole scope rolled together, so its `cpa` divides EVERY campaign's spend by
   * the orders the sales campaigns produced. That is the right answer to «what did this programme
   * cost me» and the wrong one to «what does an order cost», and the two differ by the entire brand
   * budget. This block is what lets the report say which it is showing.
   */
  objective_performance?: ObjectivePerformance
  /**
   * The same split for the PREVIOUS window — §14.7's comparison, done honestly.
   *
   * Without it the comparison table set this period's Direct cost per order beside last period's
   * BLENDED one, because `previous` only ever held the rolled-up totals. Two different scopes under
   * one heading, and the gap between them is not a change in performance.
   */
  objective_performance_previous?: ObjectivePerformance
  slides?: Slide[]
  disclaimer?: ResolvedDisclaimer | null
  mode?: string
  data_version?: number
  generated_at?: string
  data_source?: string
  attribution_window?: string | null
  timezone?: string
  checksum?: string
}
export interface ObjectivePath {
  path: 'awareness' | 'traffic' | 'conversion'
  label_ar: string
  label_en: string
  headline_metrics: string[]
  spend: number
  impressions: number
  clicks: number
  orders: number
  revenue: number
  cpm: number | null
  cpc: number | null
  ctr: number | null
  cpa: number | null
  roas: number | null
  /** False on the paths that were never meant to sell — their CPA and ROAS are null, not zero. */
  result_metrics_apply: boolean
  campaigns: Array<{ id: string; name: string; objective: string; objective_label_ar: string; spend: number }>
}
export interface ObjectivePerformance {
  paths: ObjectivePath[]
  direct: {
    label_ar: string; label_en: string
    spend: number; orders: number; revenue: number
    cpa: number | null; roas: number | null; aov: number | null
    formula: { cpa: string; roas: string }
    included_campaigns: Array<{ id: string; name: string; objective: string }>
    excluded_campaigns: Array<{ id: string; name: string; objective: string; spend: number; reason: string }>
  }
  blended: {
    label_ar: string; label_en: string
    spend: number; orders: number; revenue: number
    blended_cpa: number | null; blended_roas: number | null
    formula: { blended_cpa: string; blended_roas: string }
    includes_non_sales_spend: number
  }
}
export interface Observation {
  id: string
  kind: string
  severity: 'critical' | 'warning' | 'positive' | 'info'
  title: string
  detail: string
  metric?: string | null
  value?: string | null
  change?: number | null
  scope?: { type: string; name?: string | null }
}
export interface NoteCardData {
  severity?: 'positive' | 'warning' | 'critical' | 'info'
  title: string
  detail?: string
  platform?: string | null
  kpi?: string
  value?: string
  action?: string
  status?: string
}
export interface NextStep {
  action: string
  reason?: string
  platform?: string | null
  kpi?: string | null
  priority?: string
  owner?: string
  due?: string | null
}
export interface Meta { reportName: string; clientName?: string; agencyName?: string; platforms: string[]; isDemo?: boolean }

/**
 * Whether this document is addressed to a CLIENT — CLIENT-DIAGNOSTIC-SEPARATION-001.
 *
 * `executive` is a client audience for this purpose: it is the shorter document a client's own
 * management reads, and nobody in that room can act on a connector state either. `internal` and an
 * absent audience are the operator's, because an unlabelled report is one somebody generated for
 * themselves.
 */
export function isClientAudience(audience: string | undefined): boolean {
  return audience === 'client' || audience === 'executive'
}

/** Single slide renderer shared by the interactive deck AND the print/PDF route — identical output. */
export function SlideBody({ slide, data, meta }: { slide: Slide; data: ReportData; meta: Meta }) {
  switch (slide.type) {
    case 'cover': return <CoverSlide data={data} meta={meta} />
    case 'recommendations': return <RecommendationsSlide data={data} />
    case 'executive_summary': return <ExecutiveSlide data={data} />
    case 'platform_performance': return <PlatformSlide data={data} platform={slide.platform!} />
    case 'platform_screenshot': return <ScreenshotSlide platform={slide.platform!} />
    case 'top_creatives': return <CreativesSlide data={data} platform={slide.platform!} />
    case 'ads': return <AdsSlide data={data} />
    case 'platform_notes': return <NotesSlide data={data} platform={slide.platform!} />
    case 'platform_comparison': return <ComparisonSlide data={data} />
    case 'objective_performance': return <ObjectiveSplitSlide data={data} />
    case 'funnel': return <FunnelSlide data={data} />
    case 'comparison': return <PeriodComparisonSlide data={data} />
    case 'observations': return <ObservationsSlide data={data} />
    /*
     * CLIENT-DIAGNOSTIC-SEPARATION-001 — the data-quality slide is the OPERATOR's.
     *
     * It lists every source, its state, and when we last read it — «تعذّرت المزامنة»، «آخر مزامنة» —
     * and closes with «مؤشرات لا ترسلها المنصات المرتبطة». That is the sentence the owner found on
     * their own client link. Every line of it is a fact about our plumbing, and a client can act on
     * none of it.
     *
     * A client audience gets the one thing that IS theirs, in their own words: which metrics are
     * absent from these figures. `PrintReport` already applied the same rule to the methodology
     * slide (`payload.audience !== 'client'`), so the concept was here — the deck simply never
     * consulted it.
     */
    case 'data_quality':
      return isClientAudience(data.audience)
        ? <MissingMetricsNotice data={data} />
        : <DataQualitySlide data={data} />
    case 'budget': return <BudgetSlide data={data} />
    case 'next_steps': return <NextStepsSlide data={data} />
    case '__methodology': return <PerformanceNotice data={data.disclaimer} variant="methodology" objective={data.objective} />
    default: return null
  }
}

const OBJECTIVE_LABEL: Record<string, string> = {
  sales: 'المبيعات', awareness: 'الوعي', traffic: 'الزيارات', leads: 'العملاء المحتملون', app_installs: 'تثبيت التطبيق', video: 'الفيديو', custom: 'مخصص',
}

export function InteractiveReport({ data, meta }: { data: ReportData; meta: Meta }) {
  /*
   * The rest of this file is Arabic-only by design — it is the deck a Saudi agency sends a client.
   * The outline is new copy, and new copy follows the reader: a client who has set the product to
   * English should not meet an Arabic contents page above an Arabic deck they can at least skim.
   */
  const ar = useUi((s) => s.locale) === 'ar'
  const [mode, setMode] = useState<'deck' | 'scroll'>('deck')
  const [i, setI] = useState(0)
  const slides = useMemo(() => {
    const visible = (data.slides ?? [])
      .filter((s) => s.visible)
      // Drop the Next Steps slide entirely when there are no approved steps (never show it empty).
      .filter((s) => s.type !== 'next_steps' || (data.next_steps?.length ?? 0) > 0)
      .sort((a, b) => a.order - b.order)
    if (data.disclaimer) {
      visible.push({ id: '__methodology', type: '__methodology', order: 9999, visible: true })
    }
    return visible
  }, [data.slides, data.disclaimer, data.next_steps])
  const cur = slides[i]

  const render = (s: Slide) => <SlideBody slide={s} data={data} meta={meta} />
  // Short performance note repeated quietly under each slide (footer), except the cover.
  const footer = (s: Slide) => (s.type !== 'cover' && data.disclaimer ? <div className="mt-3 border-t border-border pt-2"><PerformanceNotice data={data.disclaimer} variant="footer" /></div> : null)

  if (slides.length === 0) return <p className="py-8 text-center text-sm text-text-secondary">لا شرائح مرئية.</p>

  return (
    <div>
      {/*
        The contents, including what is not here — REPORT-ANALYTICAL-DEPTH-001. Above the mode
        switcher because it describes the document rather than the way it is being displayed.
      */}
      <ReportOutline outline={data.outline} ar={ar} />

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
      <SnapshotAge data={data} />
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
    <div className="mb-3">
      <div className="flex items-center gap-2">
        {platform && <span className="h-3.5 w-3.5 rounded-full" style={{ background: platformColor(platform) }} />}
        <h2 className="text-2xl font-extrabold tracking-tight text-text-primary">{children}</h2>
      </div>
      {sub && <p className="mt-0.5 text-sm text-text-secondary">{sub}</p>}
    </div>
  )
}

/**
 * One metric, whole: its name, its figure, the exact figure behind it, and its own line.
 *
 * ## Why every part is in the card and nothing is beside it
 *
 * The exact figures used to sit in a strip UNDER the grid — six labels and six numbers in a row of
 * small grey text, repeating what the cards had just said. A reader who wanted the precise spend had
 * to find «الإنفاق» in that line and trust that the number after it belonged to the card three
 * columns away. Two places for one figure is one place too many, and the second place is the one
 * nobody designed.
 *
 * It is under its own headline now. Nothing is lost that the strip provided: it is the same
 * selectable text carrying the same `data-exact` mark, so a PDF still extracts it — it simply sits
 * with the number it makes exact.
 *
 * ## Why the parts are in fixed slots
 *
 * Every card holds the same four rows whether or not it has content for them, so a row of six reads
 * as a row: the labels line up, the figures line up, and the sparklines sit on one baseline. Without
 * that, a card with a note is taller than its neighbour and the grid steps up and down across the
 * page — which is what «توازي» asks for and what a variable-height stack cannot give.
 */
function Kpi({ label, value, exact, note, delta, invert, spark, accent }: { label: string; value: string; exact?: string; note?: string | null; delta?: number | null; invert?: boolean; spark?: number[]; accent?: string }) {
  return (
    <div className="flex h-full flex-col rounded-2xl border border-border bg-surface-secondary p-3">
      <div className="flex min-h-[20px] items-center justify-between gap-2">
        <span className="truncate text-sm text-text-secondary" title={label}>{label}</span>
        {delta !== undefined && <TrendPill delta={delta} invertGood={invert} />}
      </div>

      {/*
        The figure, on one line and never wrapped.
        A KPI that breaks across two lines drops the card below its neighbours and takes the row's
        alignment with it — and a number split over two lines is harder to read than a smaller one.
      */}
      <div className="tnum mt-1 truncate text-[24px] font-extrabold leading-none tracking-tight text-text-primary" title={value}>
        {value}
      </div>

      {/*
        The exact figure, under the headline it makes exact — see the note above for why it is not in
        a strip. `min-h` rather than a conditional row, so a card without one is the same height as a
        card with one.
      */}
      <div className="tnum mt-1 min-h-[14px] text-[11px] leading-tight text-text-muted" data-exact>
        {/*
          Shown only when there IS something more exact to say.
          A dash here would be the card's second «nothing was reported» under a headline that already
          says it, and a repeat of the headline would be the same figure twice in two sizes — both
          add a row that carries no information and cost the reader a glance.
        */}
        {exact && exact !== value && exact !== '—' ? exact : ''}
      </div>

      {/* Why a figure is not in the project's currency — the reader of a report has no other screen. */}
      {note && <div className="mt-0.5 text-[11px] leading-tight text-text-muted">{note}</div>}

      {/*
        The line sits on the bottom edge of every card — `mt-auto` — so six sparklines share one
        baseline however much text is above them.
      */}
      <div className="mt-auto pt-2">
        {spark && spark.length > 1
          ? <KpiSparkline points={spark} color={accent} height={22} />
          : <div className="h-[22px]" aria-hidden />}
      </div>
    </div>
  )
}

/**
 * A sparkline for a column, or nothing when the column is not in the series.
 *
 * `?? 0` inside the map is safe for a day a metric happened to miss; returning a whole series of
 * zeros for a metric that is not in the timeseries at all is not — it draws a flat line along the
 * bottom of a card, which reads as «this was zero every day» rather than «this was never plotted».
 */
const seriesOf = (rows: Row[], k: string | null): number[] | undefined => {
  if (!k || !rows.some((r) => r[k] !== undefined && r[k] !== null)) return undefined

  return rows.map((r) => Number(r[k] ?? 0))
}
const pRow = (data: ReportData, p: string) => data.platforms.find((r) => r.provider === p) as Record<string, number> | undefined

/** How an absent reading is written on a card — the same two states the dashboard uses. */
/**
 * MONEY-TRUTH-004 — a report must never say «لا توجد بيانات» over money that exists.
 *
 * This predates the `withheld` variant, so it fell through the final `:` and a client report printed
 * «لا توجد بيانات» for spend the platform really reported — the same figure Analytics shows in full.
 * A shared report contradicting the dashboard is the worst place for this to surface, because the
 * reader has no other screen to check it against.
 */
const readingText = (r: MetricReading): string =>
  r.kind === 'value' ? r.text
    : r.kind === 'withheld' ? r.original
      : r.kind === 'not_provided' ? 'لم ترسله المنصة'
        : 'لا توجد بيانات'

/** The reason a withheld figure is not in the project's currency, for the reader of a report. */
const readingNote = (r: MetricReading): string | null =>
  r.kind === 'withheld' ? 'التحويل إلى عملة المشروع غير متاح حاليًا' : null

/** The un-abbreviated figure for the selectable strip under the cards. */
const exactOf = (m: ReportMetric, data: ReportData): string => {
  const direct = data.objective_performance?.direct
  const value = direct && (m.key === 'cpa' || m.key === 'roas') ? direct[m.key] : data.kpis[m.key]

  /*
   * The READING decides first, before the raw value is consulted.
   *
   * A withheld figure is already exact and already carries its own currency, so `moneyExact` would
   * relabel it with the project's — and this strip is what a PDF extracts, which would put the wrong
   * unit into a document a client keeps. Checking it before the null guard also means a payload that
   * sends null rather than a coalesced 0 still renders the real amount instead of «—».
   */
  if (m.reading.kind === 'withheld') return m.reading.original

  if (value === null || value === undefined) return '—'

  return SPECS[m.key]?.format === money ? moneyExact(value, data.currency) : (m.reading.kind === 'value' ? m.reading.text : '—')
}

/** Card accents, so an objective-driven row still reads as a designed row rather than six greys. */
const ACCENTS: Record<string, string> = {
  spend: 'var(--brand-600)', revenue: 'var(--info)', roas: 'var(--info)',
  conversions: 'var(--purple)', purchases: 'var(--purple)', cpa: 'var(--purple)',
  ctr: 'var(--teal)', cpc: 'var(--teal)', clicks: 'var(--teal)',
  impressions: 'var(--brand-600)', reach: 'var(--info)', frequency: 'var(--purple)', cpm: 'var(--teal)',
  video_views: 'var(--info)', video_completions: 'var(--purple)', video_completion_rate: 'var(--teal)',
  leads: 'var(--purple)', cpl: 'var(--teal)', landing_page_views: 'var(--info)',
}

function CoverSlide({ data, meta }: { data: ReportData; meta: Meta }) {
  return (
    <div className="report-cover flex h-full min-h-[380px] flex-col justify-between overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 via-brand-600 to-brand-700 p-8 text-white">
      <div className="flex items-center justify-between">
        <span className="rounded-lg bg-white/15 px-3 py-1 text-sm font-bold">{meta.agencyName ?? 'CampaignsHub'}</span>
        {meta.isDemo && <span className="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">بيانات تجريبية · Demo</span>}
      </div>
      <div>
        <div className="text-sm opacity-80">{meta.clientName ?? 'تقرير الأداء'}</div>
        <h1 className="mt-1 text-4xl font-extrabold sm:text-5xl">{meta.reportName}</h1>
        <div className="mt-3 flex flex-wrap gap-2">{meta.platforms.map((p) => <span key={p} className="rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold">{providerLabel(canonicalPlatform(p), 'ar')}</span>)}</div>
      </div>
      <div className="flex flex-wrap gap-4 text-sm opacity-90">
        <span>الفترة: <span className="tnum">{data.period.from} → {data.period.to}</span></span>
        {data.objective && <span>الهدف: {OBJECTIVE_LABEL[data.objective] ?? data.objective}</span>}
        <span>العملة: {data.currency}</span>
      </div>
    </div>
  )
}

const SEV_STYLE: Record<string, { dot: string; icon: typeof CircleCheck }> = {
  positive: { dot: 'text-success', icon: CircleCheck },
  warning: { dot: 'text-warning', icon: TriangleAlert },
  critical: { dot: 'text-danger', icon: OctagonAlert },
  info: { dot: 'text-info', icon: Info },
}

function NoteCard({ note }: { note: NoteCardData }) {
  const s = SEV_STYLE[note.severity ?? 'info'] ?? SEV_STYLE.info
  const Icon = s.icon
  return (
    <div className="flex gap-2.5 rounded-xl border border-border bg-surface-secondary p-3">
      <Icon size={16} className={`mt-0.5 shrink-0 ${s.dot}`} />
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <span className="text-sm font-bold leading-snug text-text-primary">{note.title}</span>
          {note.value && <span className="tnum shrink-0 rounded-md bg-surface px-1.5 py-0.5 text-xs font-semibold text-text-secondary">{note.value}</span>}
        </div>
        {note.detail && <p className="mt-0.5 text-xs leading-relaxed text-text-secondary">{note.detail}</p>}
        <div className="mt-1 flex flex-wrap gap-1.5">
          {note.platform && <span className="inline-flex items-center gap-1 text-[11px] text-text-muted"><span className="h-2 w-2 rounded-full" style={{ background: platformColor(note.platform) }} />{providerLabel(canonicalPlatform(note.platform), 'ar')}</span>}
          {note.kpi && <span className="text-[11px] text-text-muted">· {note.kpi}</span>}
        </div>
      </div>
    </div>
  )
}

function RecommendationsSlide({ data }: { data: ReportData }) {
  // Two balanced columns: findings (right in RTL) + recommendations (left in RTL).
  const findings = data.findings ?? []
  const recs = data.recommendations ?? []
  const legacy = findings.length === 0 && recs.length === 0 ? (data.summary ?? []) : []
  return (
    <div>
      <Title sub="أبرز ما حدث، وما يُقترح فعله تاليًا">الملاحظات والتوصيات</Title>
      {legacy.length > 0 ? (
        <ul className="space-y-2">
          {legacy.map((line, idx) => (
            <li key={idx} className="flex gap-3 rounded-xl border border-border bg-surface-secondary p-3.5 text-sm">
              <span className="mt-0.5 h-6 w-6 shrink-0 rounded-full bg-brand-100 text-center text-xs font-bold leading-6 text-brand-700">{idx + 1}</span>
              <span className="leading-relaxed">{line}</span>
            </li>
          ))}
        </ul>
      ) : (
        <div className="notes-recommendations-grid grid grid-cols-1 gap-4 md:grid-cols-2">
          <section>
            <h3 className="mb-2 flex items-center gap-2 text-sm font-extrabold text-text-primary"><CircleCheck size={15} className="text-brand-600" /> أبرز النتائج والملاحظات</h3>
            <div className="space-y-2">{findings.length ? findings.map((n, i) => <NoteCard key={i} note={n} />) : <p className="text-sm text-text-muted">لا ملاحظات.</p>}</div>
          </section>
          <section>
            <h3 className="mb-2 flex items-center gap-2 text-sm font-extrabold text-text-primary"><ArrowRight size={15} className="text-brand-600" /> التوصيات والخطوات القادمة</h3>
            <div className="space-y-2">{recs.length ? recs.map((n, i) => <NoteCard key={i} note={n} />) : <p className="text-sm text-text-muted">لا توصيات.</p>}</div>
          </section>
        </div>
      )}
      {data.disclaimer && <div className="mt-3"><PerformanceNotice data={data.disclaimer} variant="footer" /></div>}
    </div>
  )
}

function ExecutiveSlide({ data }: { data: ReportData }) {
  const donut = data.platforms.map((p) => ({ name: String(p.provider), value: Number(p.spend ?? 0) }))
  const totalSpend = donut.reduce((a, b) => a + b.value, 0)
  /*
    §14.6 — the cards follow the objective, and the DIRECT pair still leads where it exists.

    These six used to be hard-coded: spend, revenue, ROAS, results, CPA, CTR on every report. A brand
    report therefore opened with «ROAS —» and «CPA —» in its two largest cards, which reads as a
    return that could not be measured rather than one that was never bought.
  */
  const cards = reportMetrics(data)
  const trend = trendSeries(data.objective)
  return (
    <div>
      <Title sub="نظرة سريعة على أداء الحملة خلال الفترة">الملخص التنفيذي</Title>
      {/*
        `items-stretch` is what makes the row a row: every card fills the tallest cell, and `Kpi`
        pins its sparkline to the bottom, so the six lines share one baseline instead of floating at
        six different heights.
      */}
      <div className="grid grid-cols-2 items-stretch gap-3 md:grid-cols-3 xl:grid-cols-6">
        {cards.map((m) => (
          <Kpi
            key={m.key}
            label={m.label}
            value={readingText(m.reading)}
            exact={exactOf(m, data)}
            note={readingNote(m.reading)}
            delta={m.reading.kind === 'withheld' ? undefined : (m.delta ?? undefined)}
            invert={m.invertGood}
            spark={m.reading.kind === 'value' ? seriesOf(data.timeseries, m.series) : undefined}
            accent={ACCENTS[m.key] ?? 'var(--brand-600)'}
          />
        ))}
      </div>
      {/*
        The trend follows the objective too.

        «الإنفاق مقابل الإيرادات» was drawn on every report, so a brand month plotted a revenue
        series that was zero on every one of its days — a flat line along the axis, which states
        that the campaign earned nothing rather than that it was not selling anything. The chart
        now plots spend against whatever this money was buying.
      */}
      <div className="mt-3 grid gap-3 lg:grid-cols-3">
        <ChartCard title={trend.title} subtitle="الاتجاه اليومي" className="lg:col-span-2">
          {trend.key === 'revenue'
            ? <SpendRevenueAreaChart data={data.timeseries} currency={data.currency} height={200} />
            : <MetricLineChart
                data={data.timeseries}
                currency={data.currency}
                series={[
                  { key: 'spend', name: 'الإنفاق', color: 'var(--brand-600)', kind: 'money' },
                  { key: trend.key, name: trend.name, color: 'var(--info)', kind: trend.kind },
                ]}
                height={200}
                rightAxisFor={trend.key}
              />}
        </ChartCard>
        <ChartCard title="توزيع الإنفاق" subtitle="حسب المنصة"><PlatformDonutChart data={donut} centerLabel="إجمالي الإنفاق" centerValue={compact(totalSpend)} currency={data.currency} height={200} /></ChartCard>
      </div>
      {/*
        The leader board names the metric it ranked on (§14.6).

        «أفضل منصة (ROAS)» was printed on every report. Where no platform sells anything, every ROAS
        is null and the sort returned whichever row came first — a winner of a competition nobody
        entered, under a trophy.
      */}
      <div className={`mt-3 grid gap-2 ${data.best?.platform_by_cpa ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
        <Highlight
          label={`أفضل منصة (${data.best?.basis?.label_ar ?? 'الأداء'})`}
          value={data.best?.platform ?? '—'}
          note={data.best?.platform_value ?? undefined}
        />
        {/*
          No filler card where there is no second ranking. A count of platforms under a trophy is
          not a highlight, and putting one there to keep three columns invites the reader to treat
          it as one.
        */}
        {data.best?.platform_by_cpa && <Highlight label="أقل تكلفة نتيجة" value={data.best.platform_by_cpa} />}
        <Highlight label="أعلى حملة إنفاقًا" value={data.best?.campaign ?? '—'} />
      </div>
    </div>
  )
}

function Highlight({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <div className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary p-3">
      <Trophy size={16} className="text-brand-600" />
      <div>
        <div className="text-xs text-text-muted">{label}</div>
        <div className="font-bold text-text-primary">{value}</div>
        {note && <div className="tnum text-[11px] text-text-muted">{note}</div>}
      </div>
    </div>
  )
}

function PlatformSlide({ data, platform }: { data: ReportData; platform: string }) {
  const p = pRow(data, platform)
  const series = data.platform_series?.[platform] ?? []
  const campaigns = data.campaigns.filter((c) => c.provider === platform).slice(0, 6).map((c) => ({ label: String(c.campaign_name ?? '—'), spend: Number(c.spend ?? 0), platform }))
  if (!p) return <Title platform={platform}>{platform}</Title>
  /*
    The platform page follows the report's objective too (§14.6).

    It carried its own hard-coded six, which is how a brand report's per-platform pages each showed
    «الإيرادات 0» and «ROAS —» under the name of a platform that had done exactly what it was paid
    to do. No `objective_performance` is passed: that split is a property of the whole scope, and a
    Direct CPA for one platform is not a figure this snapshot holds. `reported` narrows to THIS
    platform's own connector, because the scope-wide map would let a platform that publishes no
    reach print a reach of zero.
  */
  const cards = reportMetrics({
    ...data,
    kpis: p as Record<string, number | null>,
    delta: undefined,
    objective_performance: undefined,
    reported: data.reported_by_platform?.[platform] ?? data.reported,
  })
  return (
    <div>
      <Title platform={platform} sub={`أداء ${platform} خلال الفترة`}>أداء {platform}</Title>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {cards.map((m) => (
          <Kpi
            key={m.key}
            label={m.label}
            value={readingText(m.reading)}
            note={readingNote(m.reading)}
            spark={m.reading.kind === 'value' ? seriesOf(series, m.series) : undefined}
            accent={ACCENTS[m.key] ?? 'var(--brand-600)'}
          />
        ))}
      </div>
      <div className="mt-3 grid gap-3 lg:grid-cols-2">
        <ChartCard title="الأداء بمرور الوقت" subtitle="الإنفاق والإيرادات والنتائج">
          <MetricLineChart data={series} currency={data.currency} series={[{ key: 'spend', name: 'الإنفاق', color: 'var(--brand-600)', kind: 'money' }, { key: 'revenue', name: 'الإيرادات', color: 'var(--info)', kind: 'money' }, { key: 'conversions', name: 'النتائج', color: 'var(--purple)', kind: 'num' }]} height={155} />
        </ChartCard>
        <ChartCard title="أفضل الحملات" subtitle={campaigns.length >= 3 ? 'حسب الإنفاق' : 'أبرز الحملات'}>
          {/* Adaptive: a bar chart needs ≥3 bars to read well; otherwise ranking cards, not lonely bars. */}
          {campaigns.length >= 3
            ? <RankingBarChart data={campaigns} bars={[{ key: 'spend', name: 'الإنفاق', kind: 'money' }]} horizontal height={155} colorByPlatform />
            : campaigns.length >= 1
              ? <CampaignRankingFallback data={data} platform={platform} campaigns={campaigns} />
              : <p className="py-10 text-center text-sm text-text-muted">لا حملات مرتبطة بهذه المنصة.</p>}
        </ChartCard>
      </div>
      <PlatformInsights data={data} platform={platform} />
      <p className="mt-3 text-xs text-text-muted">Reach يُعرض لكل منصة على حدة ولا يُجمع كوصول فريد بين المنصات.</p>
    </div>
  )
}

/** Bottom row of a platform slide: top creative + strengths/weaknesses + recommendations — fills the
 * slide so each platform is one rich page instead of several sparse ones. */
function PlatformInsights({ data, platform }: { data: ReportData; platform: string }) {
  const creative = (data.top_creatives ?? []).find((c) => c.provider === platform)
  const notes = data.platform_notes?.[platform]
  const recs = (data.recommendations ?? []).filter((r) => r.platform === platform).slice(0, 1)
  return (
    <div className="mt-2.5 grid gap-2.5 lg:grid-cols-3">
      <div className="rounded-2xl border border-border bg-surface-secondary p-3">
        <h4 className="mb-1.5 text-sm font-bold text-text-primary">أفضل محتوى</h4>
        {creative ? (
          <div>
            <div className="truncate font-semibold text-text-primary" title={String(creative.campaign_name ?? '')}>{String(creative.campaign_name ?? '—')}</div>
            <div className="mt-1.5 grid grid-cols-2 gap-1.5 text-xs">
              {creativeReadings(creative, data.objective, data.reported_by_platform?.[platform] ?? data.reported, true, data.currency ?? null).slice(0, 2).map((r) => (
                <span key={r.key} className="rounded-lg bg-surface px-2 py-1">{r.label} <b className="tnum">{readingText(r.reading)}</b></span>
              ))}
            </div>
            {creative.reason && <div className="mt-1.5 rounded-lg bg-[var(--brand-background)] px-2 py-1 text-xs text-brand-700">{String(creative.reason)}</div>}
          </div>
        ) : <p className="text-xs text-text-muted">لا محتوى مصنّف لهذه المنصة.</p>}
      </div>
      <div className="rounded-2xl border border-border bg-surface-secondary p-3.5">
        <h4 className="mb-2 text-sm font-bold text-text-primary">ملاحظات</h4>
        <ul className="space-y-1 text-xs">
          {(notes?.strengths ?? []).slice(0, 1).map((s, i) => <li key={`s${i}`} className="flex gap-1.5"><CircleCheck size={13} className="mt-0.5 shrink-0 text-success" />{s}</li>)}
          {(notes?.weaknesses ?? []).slice(0, 1).map((w, i) => <li key={`w${i}`} className="flex gap-1.5"><TriangleAlert size={13} className="mt-0.5 shrink-0 text-warning" />{w}</li>)}
          {(notes?.strengths?.length ?? 0) === 0 && (notes?.weaknesses?.length ?? 0) === 0 && <li className="text-text-muted">—</li>}
        </ul>
      </div>
      <div className="rounded-2xl border border-border bg-surface-secondary p-3.5">
        <h4 className="mb-2 text-sm font-bold text-text-primary">توصيات</h4>
        {recs.length ? <div className="space-y-2">{recs.map((r, i) => <NoteCard key={i} note={r} />)}</div> : <p className="text-xs text-text-muted">لا توصيات خاصة بهذه المنصة.</p>}
      </div>
    </div>
  )
}

/** Ranking cards used when a bar chart would otherwise show one or two lonely bars in empty space. */
function CampaignRankingFallback({ data, platform, campaigns }: { data: ReportData; platform: string; campaigns: Array<{ label: string; spend: number }> }) {
  const rows = campaigns.slice(0, 3).map((c) => ({ c, row: data.campaigns.find((x) => x.provider === platform && String(x.campaign_name ?? '—') === c.label) as Record<string, number | string> | undefined }))
  return (
    <div className="flex h-[240px] flex-col justify-center gap-2.5">
      {rows.map(({ c, row }, i) => (
        <div key={c.label} className="rounded-xl border border-border bg-surface-secondary p-3">
          <div className="flex items-center gap-2">
            <span className="grid h-7 w-7 shrink-0 place-items-center rounded-lg text-xs font-extrabold text-white" style={{ background: platformColor(platform) }}>#{i + 1}</span>
            <div className="min-w-0 flex-1 truncate font-bold text-text-primary" title={c.label}>{c.label}</div>
          </div>
          <div className="mt-2 grid grid-cols-4 gap-1.5 text-xs">
            <span className="rounded-lg bg-surface px-2 py-1">إنفاق <b className="tnum">{compact(c.spend)}</b></span>
            <span className="rounded-lg bg-surface px-2 py-1">إيراد <b className="tnum">{compact(Number(row?.revenue ?? 0))}</b></span>
            <span className="rounded-lg bg-surface px-2 py-1">ROAS <b className="tnum">{ratio(row?.roas as number)}</b></span>
            <span className="rounded-lg bg-surface px-2 py-1">نتائج <b className="tnum">{num(Number(row?.conversions ?? 0))}</b></span>
          </div>
        </div>
      ))}
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

/**
 * REPORT-AD-PREVIEW-001 — the ads that ran, in the copy the client keeps.
 *
 * The section itself is shared with the live link and the printed document, so the three cannot
 * drift into showing different ads — or the same ad with different figures — for one scope.
 */
function AdsSlide({ data }: { data: ReportData }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [open, setOpen] = useState<ReportAd | null>(null)

  return (
    <div>
      <ReportAdsSection
        ads={data.ads}
        groups={data.ads_groups}
        currency={data.currency ?? null}
        absentReason={data.ads_absent_reason}
        level={data.ads_level}
        reading={data.ads_reading}
        locale={ar ? 'ar' : 'en'}
        onOpen={setOpen}
      />

      {/*
        REPORT-AD-PREVIEW-001 §C — the card opens its own detail.

        Production rendered these as inert `<article>`s: a client pressing their best ad got
        silence. The detail is READ-ONLY and shows only what the report already carries.
      */}
      {open && (
        <ReportAdDetail
          ad={open}
          currency={data.currency ?? null}
          locale={ar ? 'ar' : 'en'}
          onClose={() => setOpen(null)}
        />
      )}
    </div>
  )
}

function CreativesSlide({ data, platform }: { data: ReportData; platform: string }) {
  const items = (data.top_creatives ?? []).filter((c) => c.provider === platform).slice(0, 3)

  /*
   * REPORT-WORST-CREATIVES-001 — what to stop, beside what to keep.
   *
   * A report that lists only winners tells a reader what to scale and never what to cut, and cutting
   * is the cheaper decision. The backend ranks these by the SAME objective-aware metric as the
   * leaders, and excludes anything the platform did not measure on it — «no ROAS reported» is not
   * «returned nothing», and a client's report is the last place to blur those.
   *
   * A creative can only appear in one list. With two or three creatives on a platform the best is
   * arithmetically also the worst, and printing the same card under both headings reads as a bug.
   */
  const bestIds = new Set(items.map((c) => String(c.campaign_name ?? '')))
  const weak = (data.worst_creatives ?? [])
    .filter((c) => c.provider === platform && !bestIds.has(String(c.campaign_name ?? '')))
    .slice(0, 3)
  const medal = ['from-amber-400 to-amber-600', 'from-slate-300 to-slate-500', 'from-orange-400 to-orange-600']
  return (
    <div>
      <Title platform={platform} sub="مُرتّبة حسب هدف الحملة مع سبب التصنيف">أفضل الإعلانات — {platform}</Title>
      {items.length === 0 ? <p className="text-sm text-text-muted">لا محتويات مصنّفة لهذه المنصة.</p> : (
        <div className="grid gap-3 sm:grid-cols-3">
          {items.map((c, idx) => (
            <div key={idx} className="flex flex-col overflow-hidden rounded-2xl border border-border bg-surface-secondary">
              <div className={`flex h-28 items-center justify-center bg-gradient-to-br ${medal[idx] ?? medal[2]} text-white`}>
                <span className="text-3xl font-extrabold">#{idx + 1}</span>
              </div>
              <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="truncate font-bold text-text-primary" title={String(c.campaign_name ?? '')}>{String(c.campaign_name ?? '—')}</div>
                {/* §14.8 — judged on what this content was made to do, not on a fixed four. */}
                <div className="grid grid-cols-2 gap-1.5 text-xs">
                  {creativeReadings(c, data.objective, data.reported_by_platform?.[platform] ?? data.reported, true, data.currency ?? null).map((r) => (
                    <span key={r.key} className="rounded-lg bg-surface px-2 py-1">{r.label} <b className="tnum">{readingText(r.reading)}</b></span>
                  ))}
                </div>
                <div className="mt-auto rounded-lg bg-[var(--brand-background)] px-2 py-1.5 text-xs text-brand-700">{String(c.reason ?? '')}</div>
              </div>
            </div>
          ))}
        </div>
      )}

      {weak.length > 0 && (
        <div className="mt-5">
          <Title platform={platform} sub="بنفس مقياس هدف الحملة — ومقصورة على ما قاسته المنصة فعلًا">أضعف الإعلانات — {platform}</Title>
          <div className="grid gap-3 sm:grid-cols-3">
            {weak.map((c, idx) => (
              <div key={idx} className="flex flex-col overflow-hidden rounded-2xl border border-danger/30 bg-surface-secondary">
                <div className="flex flex-1 flex-col gap-2 p-3">
                  <div className="truncate font-bold text-text-primary" title={String(c.campaign_name ?? '')}>{String(c.campaign_name ?? '—')}</div>
                  <div className="grid grid-cols-2 gap-1.5 text-xs">
                    {creativeReadings(c, data.objective, data.reported_by_platform?.[platform] ?? data.reported, true, data.currency ?? null).map((r) => (
                      <span key={r.key} className="rounded-lg bg-surface px-2 py-1">{r.label} <b className="tnum">{readingText(r.reading)}</b></span>
                    ))}
                  </div>
                  <div className="mt-auto rounded-lg bg-[var(--negative-background)] px-2 py-1.5 text-xs text-danger">{String(c.reason ?? '')}</div>
                </div>
              </div>
            ))}
          </div>
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
      {/*
        TABLE-NUMERIC-ALIGNMENT-001 — through the primitive, in a document a client reads.

        Every numeric header and cell here was `text-end`, which under `dir="rtl"` is the LEFT edge
        of the cell: each figure sat as far from its Arabic heading as the column is wide. Five
        columns, in the report deck, in front of the client. The primitive centres numerics so the
        heading and its digits share one alignment in both directions, and it decides the
        abbreviation, the currency and what a missing figure looks like, so this slide can no longer
        answer any of those differently from the slide beside it.
      */}
      <ChartCard title="ترتيب المنصات" className="mt-4">
        <DataMetricTable
          columns={[
            { key: 'platform', label: 'المنصة', kind: 'text' },
            { key: 'spend', label: 'الإنفاق', kind: 'money', currency: data.currency },
            { key: 'conversions', label: 'النتائج', kind: 'number' },
            { key: 'cpa', label: 'CPA', kind: 'money', currency: data.currency },
            { key: 'roas', label: 'ROAS', kind: 'ratio' },
            { key: 'spend_share', label: 'المساهمة', kind: 'percent' },
          ]}
          rows={data.platforms.map((p) => ({
            platform: (
              <span className="inline-flex items-center gap-1.5 font-semibold">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(String(p.provider)) }} />
                {String(p.provider)}
              </span>
            ),
            spend: p.spend as number,
            conversions: p.conversions as number,
            cpa: p.cpa as number,
            roas: p.roas as number,
            spend_share: p.spend_share as number,
          }))}
          initialSort={{ column: 1, dir: 'desc' }}
        />
      </ChartCard>
    </div>
  )
}

const PRIORITY_LABEL: Record<string, { label: string; cls: string }> = {
  high: { label: 'عالية', cls: 'bg-[var(--negative-background)] text-danger' },
  medium: { label: 'متوسطة', cls: 'bg-[var(--warning-background)] text-warning' },
  normal: { label: 'عادية', cls: 'bg-surface-secondary text-text-secondary' },
}

function NextStepsSlide({ data }: { data: ReportData }) {
  const steps = data.next_steps ?? []
  return (
    <div>
      <Title sub="خطة عمل مبنية على التوصيات المعتمدة">الخطوات القادمة</Title>
      {steps.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-text-muted">لم تُعتمد خطوات قادمة لهذا الإصدار من التقرير.</div>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {steps.map((s, i) => {
            const p = PRIORITY_LABEL[s.priority ?? 'normal'] ?? PRIORITY_LABEL.normal
            return (
              <div key={i} className="rounded-2xl border border-border bg-surface-secondary p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <ArrowRight size={16} className="mt-0.5 shrink-0 text-brand-600" />
                    <span className="font-bold text-text-primary">{s.action}</span>
                  </div>
                  <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${p.cls}`}>{p.label}</span>
                </div>
                {s.reason && <p className="mt-1 ps-6 text-xs leading-relaxed text-text-secondary">السبب: {s.reason}</p>}
                <div className="mt-2 flex flex-wrap gap-1.5 ps-6 text-[11px] text-text-muted">
                  {s.platform && <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{ background: platformColor(s.platform) }} />{s.platform}</span>}
                  {s.kpi && <span>· {s.kpi}</span>}
                  {s.owner && <span>· {s.owner}</span>}
                  {s.due && <span>· {s.due}</span>}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/**
 * Direct against Blended, side by side, with what each one counted (REPORT-OBJECTIVE-003/004).
 *
 * The whole section exists to make one substitution impossible. `Total spend ÷ sales orders` is a
 * real figure and a legitimate question — «what did this programme cost per order?» — but it is not
 * the cost of an order, and a month with a large brand campaign makes the two differ by the entire
 * brand budget. Printing the second under the first's name is the critical defect §14.3 names.
 *
 * So both are here, under names that cannot be confused, each with its formula printed beneath it
 * and the campaigns it counted named. A reader who disagrees with a number can see exactly which
 * spend produced it rather than having to trust it.
 */
function ObjectiveSplitSlide({ data }: { data: ReportData }) {
  const op = data.objective_performance
  if (!op) {
    return (
      <div>
        <Title sub="لم يُحتسب هذا القسم في هذه النسخة من التقرير">الأداء حسب هدف الحملة</Title>
        <p className="text-sm text-text-muted">أعد توليد التقرير لعرض الفصل بين الأداء المباشر والمدمج.</p>
      </div>
    )
  }

  const c = data.currency
  const excluded = op.direct.excluded_campaigns

  return (
    <div data-testid="objective-split">
      <Title sub="إنفاق حملات المبيعات وحدها مقابل إنفاق البرنامج كله — رقمان مختلفان لسؤالين مختلفين">
        الأداء حسب هدف الحملة
      </Title>

      <div className="grid gap-3 md:grid-cols-2">
        <div data-testid="direct-block" className="rounded-2xl border border-brand-500/40 bg-brand-500/5 p-4">
          <h4 className="text-sm font-extrabold text-text-primary">{op.direct.label_ar}</h4>
          <p className="mt-0.5 text-[11px] text-text-secondary">حملات المبيعات وحدها. هذا هو الرقم الذي يُقاس عليه القرار.</p>
          <div className="mt-3 grid grid-cols-3 gap-2">
            <Highlight label="الإنفاق" value={money(op.direct.spend, c)} />
            <Highlight label="CPA" value={money(op.direct.cpa, c)} />
            <Highlight label="ROAS" value={ratio(op.direct.roas)} />
          </div>
          <p className="tnum mt-2 text-[11px] text-text-muted" dir="ltr">{op.direct.formula.cpa}</p>
          <p className="tnum text-[11px] text-text-muted" dir="ltr">{op.direct.formula.roas}</p>
        </div>

        <div data-testid="blended-block" className="rounded-2xl border border-border bg-surface-secondary p-4">
          <h4 className="text-sm font-extrabold text-text-primary">{op.blended.label_ar}</h4>
          <p className="mt-0.5 text-[11px] text-text-secondary">
            كل المسارات مقابل نفس الطلبات. يجيب عن «كم كلّفني البرنامج كله لكل طلب» — ولا يحل محل الرقم المباشر.
          </p>
          <div className="mt-3 grid grid-cols-3 gap-2">
            <Highlight label="الإنفاق" value={money(op.blended.spend, c)} />
            <Highlight label="Blended CPA" value={money(op.blended.blended_cpa, c)} />
            <Highlight label="Blended ROAS" value={ratio(op.blended.blended_roas)} />
          </div>
          <p className="mt-2 text-[11px] text-text-muted">
            يشمل {money(op.blended.includes_non_sales_spend, c)} من إنفاق لا يستهدف المبيعات.
          </p>
        </div>
      </div>

      {/* Every path, including the ones that were never meant to sell — where CPA is absent, not zero. */}
      <div className="mt-3 grid gap-2 sm:grid-cols-3">
        {op.paths.map((p) => (
          <div key={p.path} data-testid={`path-${p.path}`} className="rounded-xl border border-border bg-surface p-3">
            <div className="text-xs font-bold text-text-primary">{p.label_ar}</div>
            <div className="tnum mt-1 text-lg font-extrabold text-text-primary">{money(p.spend, c)}</div>
            <div className="mt-1 space-y-0.5 text-[11px] text-text-muted">
              {p.path === 'awareness' && <div>CPM {p.cpm === null ? '—' : p.cpm}</div>}
              {p.path === 'traffic' && <div>CPC {money(p.cpc, c)}</div>}
              {p.result_metrics_apply
                ? <div>CPA {money(p.cpa, c)} · ROAS {ratio(p.roas)}</div>
                : <div>لا تنطبق تكلفة الطلب على هذا المسار</div>}
              <div>{countedCampaigns(p.campaigns.length, 'ar')}</div>
            </div>
          </div>
        ))}
      </div>

      {excluded.length > 0 && (
        <div data-testid="excluded-campaigns" className="mt-3 rounded-xl border border-dashed border-border p-3">
          <div className="text-xs font-bold text-text-primary">حملات خارج حساب الأداء المباشر</div>
          <p className="mt-0.5 text-[11px] text-text-secondary">إنفاقها حقيقي ومحسوب في المدمج، ولا يدخل في تكلفة الطلب.</p>
          <ul className="mt-2 space-y-1 text-[11px] text-text-muted">
            {excluded.map((e) => (
              <li key={e.id} className="flex items-center justify-between gap-3">
                <span className="truncate">{e.name}</span>
                <span className="tnum whitespace-nowrap" dir="ltr">{money(e.spend, c)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function FunnelSlide({ data }: { data: ReportData }) {
  const stages = data.funnel ?? []
  // Biggest drop-off + overall conversion, to fill the slide with real insight beside the funnel.
  const withDrop = stages.map((s, i) => ({ ...s, drop: i > 0 && s.step_rate !== null ? 1 - (s.step_rate ?? 0) : 0 }))
  const worst = withDrop.slice(1).sort((a, b) => (b.drop ?? 0) - (a.drop ?? 0))[0]
  /*
   * FUNNEL-NULL-001 — the end-to-end rate is measured between the stages that were REPORTED.
   *
   * This read `stages[0].count ?? 0` over `stages[last].count ?? 1`, so a funnel whose top or bottom
   * the platform never sent still published a percentage — computed from a zero that stood in for
   * silence, on the slide a client reads as the headline. Both ends must be real figures or the rate
   * is not stated at all, and the sentence underneath names the two steps it actually spans rather
   * than always claiming «من الظهور إلى النتيجة».
   */
  const measured = stages.filter((s) => s.count !== null)
  const first = measured[0]
  const last = measured[measured.length - 1]
  const overall = measured.length > 1 && (first?.count ?? 0) > 0 ? (last!.count as number) / (first!.count as number) : null
  const unreported = stages.filter((s) => s.count === null)
  return (
    <div>
      <Title sub="من الظهور إلى الشراء — معدل الانتقال وتكلفة كل مرحلة">قمع التحويل</Title>
      {stages.length > 0 ? (
        <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
          <ChartCard title="مراحل القمع" subtitle="العدد ومعدل الانتقال وتكلفة كل مرحلة"><ConversionFunnelChart stages={stages} currency={data.currency} ar /></ChartCard>
          <div className="space-y-3">
            <div className="rounded-2xl border border-border bg-surface-secondary p-4">
              <div className="text-xs text-text-muted">معدل التحويل الكلي</div>
              <div className="tnum text-3xl font-extrabold text-text-primary">{overall !== null ? percent(overall, 2) : '—'}</div>
              <div className="mt-1 text-xs text-text-secondary">
                {overall !== null
                  ? `من ${num(first!.count)} ${first!.label} إلى ${num(last!.count)} ${last!.label}`
                  : 'لا توجد مرحلتان مرسلتان تُقاس بينهما نسبة.'}
              </div>
              {unreported.length > 0 && (
                <div className="mt-2 text-[11px] text-text-muted">
                  لم ترسل أي منصة: {unreported.map((s) => s.label).join('، ')} — الفراغ ليس صفرًا.
                </div>
              )}
            </div>
            {worst && (
              <div className="rounded-2xl border border-border bg-[var(--warning-background)] p-4">
                <div className="mb-1 flex items-center gap-1.5 text-sm font-bold text-warning"><TriangleAlert size={15} /> أكبر تسرّب</div>
                <div className="font-semibold text-text-primary">{worst.label}</div>
                <div className="tnum mt-0.5 text-sm text-text-secondary">تسرّب {percent(worst.drop ?? 0, 0)} · تكلفة {money(worst.cost_per, data.currency)}</div>
              </div>
            )}
            <div className="rounded-2xl border border-border bg-surface-secondary p-4">
              <div className="mb-1 text-sm font-bold text-text-primary">تكلفة كل مرحلة</div>
              <div className="space-y-1 text-xs">
                {stages.filter((s) => s.cost_per !== null).slice(-4).map((s) => (
                  <div key={s.label} className="flex justify-between"><span className="text-text-secondary">{s.label}</span><span className="tnum font-semibold">{money(s.cost_per, data.currency)}</span></div>
                ))}
              </div>
            </div>
          </div>
        </div>
      ) : <p className="text-sm text-text-muted">لا بيانات قمع.</p>}
    </div>
  )
}

function BudgetSlide({ data }: { data: ReportData }) {
  const rows = (data.budget ?? []).slice(0, 5)
  const totalBudget = rows.reduce((a, b) => a + Number(b.budget ?? 0), 0)
  const totalSpent = rows.reduce((a, b) => a + Number(b.spent ?? 0), 0)
  const consumed = totalBudget > 0 ? totalSpent / totalBudget : 0
  const bars = rows.map((r) => ({ label: String(r.campaign_name ?? '—'), budget: Number(r.budget ?? 0), spent: Number(r.spent ?? 0) }))
  return (
    <div>
      <Title sub="المخطط مقابل المصروف وسرعة الصرف">تحليل الميزانية</Title>
      <div className="grid gap-3 lg:grid-cols-3">
        <ChartCard title="استهلاك الميزانية" className="flex items-center justify-center">
          <ProgressRing value={consumed} sublabel={`${compact(totalSpent)} / ${compact(totalBudget)}`} size={128} tone={consumed > 0.95 ? 'danger' : consumed > 0.8 ? 'warning' : 'brand'} />
        </ChartCard>
        <ChartCard title="المخطط مقابل المصروف" className="lg:col-span-2">
          <RankingBarChart data={bars} bars={[{ key: 'budget', name: 'الميزانية', color: 'var(--border-strong)', kind: 'money' }, { key: 'spent', name: 'المصروف', color: 'var(--brand-600)', kind: 'money' }]} horizontal height={170} currency={data.currency} />
        </ChartCard>
      </div>
      {rows.length > 0 && (
        <ChartCard title="سرعة الصرف والتوقعات" subtitle="Pace >1 صرف أسرع من المخطط" className="mt-4">
          {/*
            PARTIAL-WITHHELD-001 is now the primitive's rule, not this table's.

            A null spend, remaining or projected figure is «no single figure», never «0», and every
            column of every table says that with the same dash. Pace keeps its own colouring because
            «spending faster than planned» is a judgement this slide makes and no other does.
          */}
          <DataMetricTable
            columns={[
              { key: 'campaign', label: 'الحملة', kind: 'text' },
              { key: 'budget', label: 'الميزانية', kind: 'money', currency: data.currency },
              { key: 'spent', label: 'المصروف', kind: 'money', currency: data.currency },
              { key: 'remaining', label: 'المتبقي', kind: 'money', currency: data.currency },
              { key: 'consumed', label: 'الاستهلاك', kind: 'percent', digits: 0 },
              { key: 'pace', label: 'Pace', kind: 'text' },
              { key: 'projected', label: 'الصرف المتوقع', kind: 'money', currency: data.currency },
            ]}
            rows={rows.map((r) => {
              const pace = Number(r.pace ?? 0)

              return {
                campaign: String(r.campaign_name ?? '—'),
                budget: r.budget as number,
                spent: r.spent as number,
                remaining: r.remaining as number,
                consumed: r.consumed_pct as number,
                pace: pace ? (
                  <span className={`tnum font-semibold ${pace > 1.1 ? 'text-danger' : pace < 0.9 ? 'text-warning' : 'text-text-primary'}`}>
                    {pace.toFixed(2)}
                  </span>
                ) : null,
                projected: r.projected_spend as number,
              }
            })}
            initialSort={{ column: 2, dir: 'desc' }}
          />
        </ChartCard>
      )}
    </div>
  )
}

/**
 * §14.7 — the same metrics, this period against the last one.
 *
 * The executive cards already carry a change pill, which answers «up or down» and not «from what».
 * A client asking whether 96,121 SAR is a lot needs the 75,000 it was last month beside it, and a
 * percentage on its own has never answered that.
 *
 * The rows follow the objective, so a brand report compares reach and CPM rather than revenue.
 */
function PeriodComparisonSlide({ data }: { data: ReportData }) {
  // `previousReading` reads a Direct figure against the previous period's DIRECT figure, never
  // against the blended total that `previous` holds under the same key.
  const rows = reportMetrics(data).map((m) => ({ ...m, before: previousReading(m, data) }))

  return (
    <div>
      <Title sub="الفترة الحالية مقابل الفترة السابقة بنفس الطول">المقارنات والاتجاهات</Title>
      {/*
        The opposite alignment defect from the platform table, in the same document.

        Every column here was `text-start`, so the figures hugged the right edge under RTL while the
        platform slide pushed them to the left — two tables in one report, disagreeing about where a
        number belongs. The primitive settles it once.

        The readings are already formatted strings (a reading carries its own units and its own
        refusals), so they travel as text; «لا مقارنة» stays a dash rather than a zero, which is the
        primitive's rule now.
      */}
      <DataMetricTable
        columns={[
          { key: 'label', label: 'المؤشر', kind: 'text' },
          { key: 'now', label: 'الفترة الحالية', kind: 'text' },
          { key: 'before', label: 'الفترة السابقة', kind: 'text' },
          { key: 'change', label: 'التغير', kind: 'text' },
        ]}
        rows={rows.map((r) => ({
          label: r.label,
          now: <span className="tnum font-bold text-text-primary">{readingText(r.reading)}</span>,
          before: <span className="tnum text-text-secondary">{r.before.text ?? '—'}</span>,
          change: r.before.change === null
            ? null
            : <TrendPill delta={r.before.change} invertGood={r.invertGood} />,
        }))}
      />
      <p className="mt-2 text-xs text-text-muted">
        تُقارن الفترة بفترة سابقة مساوية لها في عدد الأيام، وتُترك المقارنة فارغة عند غياب بيانات الفترة السابقة.
      </p>
    </div>
  )
}

const NOTE_TONE: Record<string, { border: string; text: string; Icon: typeof TriangleAlert }> = {
  critical: { border: 'border-danger/40', text: 'text-danger', Icon: OctagonAlert },
  warning: { border: 'border-warning/40', text: 'text-warning', Icon: TriangleAlert },
  positive: { border: 'border-success/40', text: 'text-success', Icon: CircleCheck },
  info: { border: 'border-border', text: 'text-text-secondary', Icon: Info },
}

/**
 * §14.7 — what this report's own numbers say, and nothing that they do not.
 *
 * Every card here was produced by a detector that found its condition in this scope's data and
 * printed the figures that made it true. When none fires the slide says so plainly: a report with
 * nothing alarming in it is a result, and filling the space with generic advice would teach a
 * reader that this section is decoration.
 */
function ObservationsSlide({ data }: { data: ReportData }) {
  const notes = data.observations ?? []

  return (
    <div>
      <Title sub="ملاحظات مستخرجة من أرقام هذا التقرير ونطاقه">الملاحظات والتنبيهات</Title>
      {notes.length === 0 ? (
        <p className="rounded-2xl border border-border bg-surface-secondary p-6 text-center text-sm text-text-secondary">
          لا توجد ملاحظات تستدعي الانتباه في هذه الفترة.
        </p>
      ) : (
        <div className="grid gap-2.5 md:grid-cols-2">
          {notes.map((n) => {
            const tone = NOTE_TONE[n.severity] ?? NOTE_TONE.info

            return (
              <div key={n.id} className={`rounded-2xl border ${tone.border} bg-surface-secondary p-3`}>
                <div className="flex items-start gap-2">
                  <tone.Icon size={16} className={`mt-0.5 shrink-0 ${tone.text}`} />
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h4 className="text-sm font-bold text-text-primary">{n.title}</h4>
                      {n.value && <span className="tnum rounded-md bg-surface px-1.5 py-0.5 text-xs text-text-secondary">{n.value}</span>}
                    </div>
                    <p className="mt-1 text-sm text-text-secondary">{n.detail}</p>
                    {/* Only when the title does not already name it — a card that repeats its own
                        subject reads as two facts where there is one. */}
                    {n.scope?.name && !n.title.includes(n.scope.name) && <p className="mt-1 text-xs text-text-muted">{n.scope.name}</p>}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

const FRESHNESS_LABEL: Record<string, { ar: string; tone: string }> = {
  fresh: { ar: 'محدثة', tone: 'text-success' },
  stale: { ar: 'لم تتحدث مؤخرًا', tone: 'text-warning' },
  failed: { ar: 'تعذّرت المزامنة', tone: 'text-danger' },
  no_data: { ar: 'لا يوجد مصدر مرتبط', tone: 'text-text-muted' },
  unknown: { ar: 'غير معروفة', tone: 'text-text-muted' },
}

/**
 * §14.10's last section — how much weight the rest of the deck can carry.
 *
 * It is always rendered, including when everything is healthy. A quality slide that appears only
 * when something is wrong teaches its reader that its absence means nothing, so the one time it
 * matters they have no baseline to read it against.
 */
/**
 * What a CLIENT is told instead — CLIENT-DIAGNOSTIC-SEPARATION-001.
 *
 * One fact, in their vocabulary: which figures the platforms did not report for this period, so a
 * blank in the report is not read as a zero. No source list, no states, no clock, and no
 * «المنصات المرتبطة» — a client does not know which platforms are «connected», they know which
 * platforms they buy on.
 *
 * Nothing at all when every metric arrived: a slide whose only content is «all good» is a slide that
 * teaches a reader to skip the section, including on the day it says something.
 */
function MissingMetricsNotice({ data }: { data: ReportData }) {
  const missing = Object.entries(data.reported ?? {})
    .filter(([, sent]) => !sent)
    .map(([key]) => SPECS[key]?.label.ar ?? key)

  if (missing.length === 0) return null

  return (
    <div>
      <Title sub="ما لم تُبلِّغ عنه المنصات في هذه الفترة">اكتمال الأرقام</Title>
      <p className="rounded-2xl border border-border bg-surface-secondary p-4 text-sm leading-relaxed text-text-secondary">
        لم تصل هذه المؤشرات لهذه الفترة: {missing.join('، ')} — تظهر في التقرير بلا قيمة بدلًا من صفر،
        لأن «لم يُبلَّغ عنه» و«صفر» ليسا الشيء نفسه.
      </p>
    </div>
  )
}

function DataQualitySlide({ data }: { data: ReportData }) {
  const f = data.freshness
  const state = FRESHNESS_LABEL[f?.state ?? 'unknown'] ?? FRESHNESS_LABEL.unknown
  const missing = Object.entries(data.reported ?? {}).filter(([, sent]) => !sent).map(([key]) => SPECS[key]?.label.ar ?? key)

  return (
    <div>
      <Title sub="مصدر الأرقام وحداثتها ومدى اكتمالها">جودة البيانات وحداثتها</Title>
      <div className="grid gap-2.5 sm:grid-cols-3">
        <div className="rounded-2xl border border-border bg-surface-secondary p-3">
          <div className="text-xs text-text-muted">حالة البيانات</div>
          <div className={`font-bold ${state.tone}`}>{state.ar}</div>
        </div>
        <div className="rounded-2xl border border-border bg-surface-secondary p-3">
          <div className="text-xs text-text-muted">آخر مزامنة</div>
          <div className="tnum font-bold text-text-primary">{f?.last_sync_at ? fmtDateTime(f.last_sync_at) : '—'}</div>
        </div>
        <div className="rounded-2xl border border-border bg-surface-secondary p-3">
          <div className="text-xs text-text-muted">أيام بلا بيانات</div>
          {/* A null is «not measured», which is not the same claim as «no days were missing». */}
          <div className="tnum font-bold text-text-primary">{f?.missing_days ?? '—'}</div>
        </div>
      </div>
      {(f?.sources?.length ?? 0) > 0 && (
        <div className="mt-3">
          <DataMetricTable
            columns={[
              { key: 'source', label: 'المصدر', kind: 'text' },
              { key: 'state', label: 'الحالة', kind: 'text' },
              { key: 'synced', label: 'آخر مزامنة', kind: 'text' },
            ]}
            rows={f!.sources!.map((s) => ({
              source: s.name ?? s.provider,
              state: (
                <span className={(FRESHNESS_LABEL[s.state ?? 'unknown'] ?? FRESHNESS_LABEL.unknown).tone}>
                  {(FRESHNESS_LABEL[s.state ?? 'unknown'] ?? FRESHNESS_LABEL.unknown).ar}
                </span>
              ),
              synced: s.last_sync_at ? fmtDateTime(s.last_sync_at) : null,
            }))}
          />
        </div>
      )}
      {missing.length > 0 && (
        <p className="mt-3 rounded-2xl border border-border bg-surface-secondary p-3 text-sm text-text-secondary">
          مؤشرات لا ترسلها المنصات المرتبطة خلال هذه الفترة: {missing.join('، ')} — تظهر في التقرير بلا قيمة بدلًا من صفر.
        </p>
      )}
    </div>
  )
}

/**
 * REPORTS-RECONCILIATION-001 — a snapshot says WHEN it was taken.
 *
 * `generated_at`, `mode`, `data_source` and `attribution_window` were all present on the payload
 * and all present in this file's type — and not one of them was ever rendered. A report opened a
 * month after it was generated showed month-old figures with nothing on screen to say so, which is
 * indistinguishable from current performance to the person reading it.
 *
 * That is the failure the requirement names directly: never present a stale snapshot as current.
 * It is also, again, a value carried the whole way to the component and then dropped — the same
 * shape as the creative's ads, the asset URL and `last_active_at` before it.
 *
 * A LIVE report says so instead of quoting a generation time, because for a live report the
 * generation time is not the answer to «how current is this».
 */
function SnapshotAge({ data }: { data: ReportData }) {
  const generated = data.generated_at ?? null
  const live = data.mode === 'live'

  // Nothing known, nothing claimed. A snapshot written before this metadata existed says nothing
  // rather than inventing a date for itself.
  if (!live && generated === null) return null

  const stamp = generated === null ? null : new Date(generated)

  return (
    <p className="mb-3 text-xs text-text-secondary" data-testid="report-snapshot-age">
      {live ? (
        'تقرير مباشر — الأرقام محسوبة الآن من أحدث البيانات'
      ) : (
        <>
          لقطة بتاريخ{' '}
          <span className="tnum font-semibold text-text-primary" dir="ltr">
            {fmtDateTime(stamp?.toISOString() ?? null)}
          </span>
          {' '}— الأرقام كما كانت في ذلك الوقت، وليست أداءً حاليًا
        </>
      )}
      {/* ATTRIBUTION-WINDOW-001 — the reader of this footer cannot decode a platform's parameter name. */}
      {data.attribution_window ? (
        <span className="ms-2 opacity-80">· أساس الإسناد: {attributionWindow(data.attribution_window, true).text}</span>
      ) : null}

      {/*
        * REPORTS-RECONCILIATION-001 — a snapshot from an older contract cannot reconcile.
        *
        * The figures beneath have since changed twice in ways that alter NUMBERS, not presentation:
        * unconvertible money is now withheld with its original where it used to become 0, and demo
        * rows no longer enter an operational total. A v1 snapshot beside today's Analytics is not
        * «a few hours stale» — the two were computed under different rules.
        *
        * Saying so is the honest alternative to letting a reader compare two incomparable numbers
        * and conclude one of the screens is broken.
        */}
      {!live && (data.data_version ?? 1) < CURRENT_DATA_VERSION && (
        <span className="mt-1 block text-warning" data-testid="report-stale-contract">
          هذه اللقطة حُسبت بقواعد أقدم — قد لا تطابق التحليلات الحالية. أعد إنشاء التقرير للمقارنة.
        </span>
      )}
    </p>
  )
}

/**
 * The aggregation contract the product computes under today — mirrors `ReportGenerator::DATA_VERSION`.
 *
 * Duplicated deliberately and narrowly: it is one integer whose only job is to be compared, and a
 * round trip to fetch it would make every report wait on a request to say «this is current».
 */
const CURRENT_DATA_VERSION = 2
