import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDownRight, ArrowUpRight, BarChart3, Building2, ChevronDown, Trophy } from 'lucide-react'
import { KpiSparkline, MetricLineChart } from '@/features/analytics/charts'
import { AdPoster } from '@/features/content/AdPoster'
import { AdPreviewDialog } from '@/features/content/AdPreviewDialog'
import type { CreativeCard } from '@/features/content/api'
import { Num } from '@/components/ui/Num'
import { moneyExact } from '@/features/analytics/format'
import { PlatformMark } from '@/components/brand/PlatformMark'
import { StatCard, type StatTone } from '@/components/ui/StatCard'
import { Skeleton } from '@/components/ui/States'
import { campaigns as countedCampaigns, days as countedDays } from '@/lib/counted'
import { platformColor } from '@/lib/colors'
import { platformLabel } from '@/lib/platforms'
import { useAgencyClient } from '@/stores/agencyClient'
import { useWatchBoard, type DashboardWindow, type RankedCampaign, type WatchBoard as Board, type WatchContent } from './api'
import { Door, Nothing, Panel, familyName, times, useBoxHeight, useFmt } from './parts'
import { PlatformComparePanel, SpendSharePanel } from './performance'

/*
 * WATCH-BOARD-001 — «لوحة المتابعة»: follow every client from one page, without opening each platform.
 *
 *   chips           every client the member reaches, or one of them («كل العملاء» first)
 *   four figures    spend (against the window before it), results, impressions, clicks — each with its own daily line
 *   الأداء اليومي    spend and clicks day by day, on two scales
 *   أفضل / أضعف      the leading and the weakest campaigns of every platform together, ranked inside one objective
 *   المحتويات        the leading and the weakest content, as it ran (the report's own ranker)
 *   المنصات          the platform comparison and the spend share
 *
 * Every figure is the server's (`GET /me/dashboard/watch`), already narrowed to the member's reach; a client he cannot
 * reach is never offered and never served. A withheld figure stays «—», never 0. The page scrolls; nothing is squeezed.
 */

const T = {
  ar: {
    title: 'لوحة المتابعة', all: 'كل العملاء', spend: 'الإنفاق', results: 'النتائج', impressions: 'الظهور', clicks: 'النقرات',
    vsPrevious: 'مقارنة بالفترة السابقة', ctr: 'نسبة النقر', daily: 'الأداء اليومي', best: 'أفضل الحملات', worst: 'أضعف الحملات',
    bestContent: 'أفضل المحتويات', worstContent: 'أضعف المحتويات', analytics: 'التفاصيل في التحليلات', nothing: 'لا توجد بيانات بعد',
    rankedBy: 'الترتيب حسب', cost: 'التكلفة', roas: 'العائد', conversions: 'التحويلات', clients: 'العملاء', loadFailed: 'تعذّر تحميل لوحة المتابعة',
  },
  en: {
    title: 'Watch board', all: 'All clients', spend: 'Spend', results: 'Results', impressions: 'Impressions', clicks: 'Clicks',
    vsPrevious: 'vs previous period', ctr: 'CTR', daily: 'Daily performance', best: 'Top campaigns', worst: 'Weakest campaigns',
    bestContent: 'Top content', worstContent: 'Weakest content', analytics: 'Details in analytics', nothing: 'No data yet',
    rankedBy: 'Ranked by', cost: 'Cost', roas: 'ROAS', conversions: 'Conversions', clients: 'Clients', loadFailed: 'Could not load the watch board',
  },
}

type Fmt = ReturnType<typeof useFmt>

const sum = (xs: Array<number | null | undefined>): number | null => {
  const known = xs.filter((x): x is number => x !== null && x !== undefined)

  return known.length === 0 ? null : known.reduce((a, b) => a + b, 0)
}

/** One headline figure — the shared card (StatCard), its daily line in the spark slot, opening the analytics. */
function Stat({ label, value, to, testId, points, color, tone, hint, delta }: {
  label: string; value: string; to: string; testId: string; points: number[]; color: string; tone: StatTone; hint?: string
  delta?: { metric: string; current: number | null | undefined; previous: number | null | undefined }
}) {
  return (
    <Door to={to} className="block min-w-0 rounded-2xl transition hover:shadow-[var(--shadow-medium)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
      <StatCard label={label} value={value} hint={hint} tone={tone} dot delta={delta} testid={testId} spark={<div className="h-9" aria-hidden><KpiSparkline points={points} color={color} height={36} /></div>} />
    </Door>
  )
}

/** The leading or the weakest campaigns, each a bar of its results against the list's largest. */
function RankList({ rows, tone, portal, f, testId }: { rows: RankedCampaign[]; tone: 'best' | 'worst'; portal: 'agency' | 'app'; f: Fmt; testId: string }) {
  const t = f.ar ? T.ar : T.en
  if (rows.length === 0) return <Nothing label={t.nothing} />
  const max = Math.max(...rows.map((r) => r.result ?? 0), 1)
  const Arrow = tone === 'best' ? ArrowUpRight : ArrowDownRight

  return (
    <ol className="flex flex-col gap-2" data-testid={testId}>
      {rows.map((r, i) => {
        const platform = r.platform ?? ''
        const figure = r.roas !== null ? `${t.roas} ${times(r.roas)}` : `${t.cost} ${f.short(r.cost_per_result)}`

        return (
          <li key={r.id} data-testid={`${testId}-${r.id}`} className="min-w-0">
            <Link to={r.project_id ? `/${portal}/campaigns/${r.project_id}/${r.id}` : `/${portal}/campaigns`} className="group flex min-w-0 items-center gap-2.5 rounded-xl px-1.5 py-1 hover:bg-surface-hover">
              <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-lg text-xs font-extrabold tnum ${tone === 'best' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>{i + 1}</span>
              <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="flex min-w-0 items-center justify-between gap-2 text-xs">
                  <span className="truncate font-semibold text-text-primary group-hover:underline" title={r.name}>{r.name}</span>
                  <span className={`inline-flex shrink-0 items-center gap-0.5 font-bold tnum ${tone === 'best' ? 'text-success' : 'text-danger'}`}><Arrow size={12} aria-hidden />{figure}</span>
                </span>
                <span className="flex min-w-0 items-center gap-2">
                  <span aria-hidden className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                    <span className="block h-full rounded-full" style={{ width: `${Math.max(4, Math.round(((r.result ?? 0) / max) * 100))}%`, background: platformColor(platform) }} />
                  </span>
                  {/* PLATFORM-MARK-ONLY-001 — the platform's own mark, its name for a screen reader and on hover: room for the figure. */}
                  <span className="inline-flex shrink-0 items-center gap-1 text-xs text-text-muted" title={platformLabel(platform, f.ar)}>
                    <PlatformMark platform={platform} size={14} />
                    <span className="sr-only">{platformLabel(platform, f.ar)}</span>
                    <span className="tnum font-semibold text-text-secondary">{f.short(r.result)}</span>
                  </span>
                </span>
              </span>
            </Link>
          </li>
        )
      })}
    </ol>
  )
}

/**
 * HOME-CONTENT-REPORT-CARD-001 — Owner 2026-11-19: the home's best / weakest content read like the report's content card
 * (`ReportAdsSection` → `AdCard`) and open in a popup, not as tall posters with two loose figures.
 *
 * A filled cover, the name, the platform's logo and name, then three small figure boxes in the order that answers «did it
 * work»: the figure it was RANKED on (the board's own basis — ROAS, cost per result…), spend, and the results only where the
 * ranking family counts them. That last rule is the fix for «التحويلات 35.9K» under an awareness video: views counted as
 * conversions by its platform were printed under a sales ranking.
 */
function ContentTile({ c, f, currency, tone, rank, basis, onOpen }: {
  c: WatchContent; f: Fmt; currency: string | null; tone: 'best' | 'worst'; rank: number
  basis: { metric: string | null; label_ar: string | null; label_en: string | null; family: string | null } | null
  onOpen: (c: WatchContent) => void
}) {
  const provider = c.provider ?? ''
  const figures = contentFigures(c, f, currency, basis)

  return (
    <button
      type="button"
      onClick={() => onOpen(c)}
      data-testid={`watch-content-${tone}`}
      aria-label={f.ar ? `تفاصيل ${c.name ?? ''}` : `Details for ${c.name ?? ''}`}
      className="relative flex min-w-0 flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-2.5 text-start transition-colors hover:border-brand-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500"
    >
      <span aria-hidden className={`absolute start-3.5 top-3.5 z-10 grid h-6 min-w-6 place-items-center rounded-full px-1.5 text-xs font-extrabold text-white shadow-[var(--shadow-small)] ${tone === 'best' ? 'bg-success' : 'bg-danger'}`}>{rank}</span>
      <AdPoster preview={c.preview} name={c.name ?? ''} provider={provider} className="h-28 w-full rounded-xl" fit="cover" />
      <span className="min-w-0">
        <span dir="auto" className="block truncate text-start text-sm font-bold text-text-primary" title={c.name ?? ''}>{c.name ?? '—'}</span>
        <span className="flex items-center gap-1 truncate text-xs text-text-muted">
          <PlatformMark platform={provider} size={13} />
          <span className="truncate">{platformLabel(provider, f.ar)}</span>
        </span>
      </span>
      <dl className="grid grid-cols-3 gap-1.5 text-center" data-testid="watch-content-figures">
        {figures.map((x) => (
          <div key={x.key} className={`rounded-lg p-1.5 ${x.key === 'basis' ? (tone === 'best' ? 'bg-[var(--positive-background)]' : 'bg-[var(--warning-background)]') : 'bg-surface-secondary'}`}>
            <dt className="truncate text-xs font-semibold text-text-muted">{x.label}</dt>
            <dd className="text-xs font-bold text-text-primary"><Num>{x.value}</Num></dd>
          </div>
        ))}
      </dl>
    </button>
  )
}

const CONVERSION_FAMILIES = new Set(['sales', 'conversion', 'leads', 'app_promotion', 'app'])

/** The three figures of a content card: the ranked-on figure first, then spend, then results only where they mean results. */
function contentFigures(c: WatchContent, f: Fmt, currency: string | null, basis: { metric: string | null; label_ar: string | null; label_en: string | null; family: string | null } | null) {
  const t = f.ar ? T.ar : T.en
  const out: { key: string; label: string; value: string }[] = []
  const metric = basis?.metric ?? null
  const label = basis ? (f.ar ? basis.label_ar : basis.label_en) : null
  const val = metric === 'roas' ? (c.roas === null ? null : `${c.roas.toFixed(2)}×`)
    : metric === 'cpa' ? (c.cpa === null ? null : moneyExact(c.cpa, currency))
      : metric === 'ctr' ? (c.ctr === null ? null : f.pct(c.ctr > 1 ? c.ctr / 100 : c.ctr))
        : null
  if (metric && label) out.push({ key: 'basis', label, value: val ?? '—' })
  if (c.spend !== null) out.push({ key: 'spend', label: t.spend, value: f.money(c.spend, currency) })
  const counts = basis?.family ? CONVERSION_FAMILIES.has(basis.family) : true
  if (counts && c.conversions !== null) out.push({ key: 'results', label: t.conversions, value: f.short(c.conversions) })
  else out.push({ key: 'clicks', label: t.clicks, value: f.short(c.clicks) })

  return out.slice(0, 3)
}

function Body({ b, portal, f }: { b: Board; portal: 'agency' | 'app'; f: Fmt }) {
  const t = f.ar ? T.ar : T.en
  const p = b.performance ?? null
  const cur = b.currency
  // ANALYTICS-ONE-BOARD-001 — the board is «التحليلات»; the headline figures open its overview in a popup (ANALYTICS-POPUP-001).
  const analytics = `/${portal}/analytics`
  const trend = b.trend
  const impressions = sum(trend.map((d) => d.impressions))
  const clicks = sum(trend.map((d) => d.clicks))
  const ctr = impressions && clicks !== null ? clicks / impressions : null
  const chart = useBoxHeight(220)
  const resultLabel = p?.result_label?.[f.ar ? 'ar' : 'en'] ?? t.results
  const basis = b.content?.basis
  const basisLabel = basis ? (f.ar ? basis.label_ar : basis.label_en) : null
  // HOME-CONTENT-REPORT-CARD-001 — a content opens in the library's own popup, over Home; «التفاصيل» goes to its page.
  const [openContent, setOpenContent] = useState<WatchContent | null>(null)

  return (
    <div className="grid min-w-0 grid-cols-1 gap-2">
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4" data-testid="watch-kpis">
        {b.can_money && (
          <Stat label={`${t.spend} · ${countedDays(b.window.days, f.ar ? 'ar' : 'en')}`} value={f.money(p?.spend, cur)} to={analytics} testId="watch-kpi-spend" points={trend.map((d) => d.spend ?? 0)} color="var(--brand-600)" tone="brand"
            hint={p && p.previous_spend !== null ? t.vsPrevious : undefined} delta={p && p.previous_spend !== null ? { metric: 'spend', current: p.spend, previous: p.previous_spend } : undefined} />
        )}
        <Stat label={resultLabel} value={f.short(p?.results)} to={analytics} testId="watch-kpi-results" points={trend.map((d) => d.conversions ?? 0)} color="var(--success)" tone="success"
          hint={p?.family ? `${familyName(p.family, f.ar)}${b.can_money && p.cost_per_result !== null ? ` · ${t.cost} ${f.money(p.cost_per_result, cur)}` : ''}` : undefined} />
        <Stat label={t.impressions} value={f.short(impressions)} to={analytics} testId="watch-kpi-impressions" points={trend.map((d) => d.impressions)} color="var(--info)" tone="info"
          hint={p ? countedCampaigns(p.campaigns, f.ar ? 'ar' : 'en') : undefined} />
        <Stat label={t.clicks} value={f.short(clicks)} to={analytics} testId="watch-kpi-clicks" points={trend.map((d) => d.clicks)} color="var(--purple)" tone="neutral"
          hint={ctr !== null ? `${t.ctr} ${f.pct(ctr)}` : undefined} />
      </div>

      <div className="grid grid-cols-1 gap-2 lg:grid-cols-12">
        <Panel title={t.daily} to={analytics} testId="watch-panel-daily" className="min-h-[280px] lg:col-span-8">
          {trend.length < 2 ? <Nothing label={t.nothing} /> : (
            <div ref={chart.ref} className="h-full min-h-[220px]">
              <MetricLineChart
                height={chart.height}
                currency={cur ?? ''}
                data={trend}
                rightAxisFor="clicks"
                series={[
                  ...(b.can_money ? [{ key: 'spend', name: t.spend, color: 'var(--brand-600)', kind: 'money' as const }] : [{ key: 'impressions', name: t.impressions, color: 'var(--info)' }]),
                  { key: 'clicks', name: t.clicks, color: 'var(--purple)' },
                ]}
              />
            </div>
          )}
        </Panel>
        <SpendSharePanel platforms={b.platforms ?? []} currency={cur} portal={portal} className="min-h-[280px] lg:col-span-4" />
      </div>

      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
        <Panel title={t.best} to={`/${portal}/campaigns`} testId="watch-panel-best">
          <div className="mb-1 flex items-center gap-1.5 text-xs text-text-muted"><Trophy size={12} aria-hidden className="text-warning" />{b.rank_family ? `${t.rankedBy}: ${familyName(b.rank_family, f.ar)}` : null}</div>
          <RankList rows={b.best ?? []} tone="best" portal={portal} f={f} testId="watch-best" />
        </Panel>
        <Panel title={t.worst} to={`/${portal}/campaigns`} testId="watch-panel-worst">
          <div className="mb-1 flex items-center gap-1.5 text-xs text-text-muted"><BarChart3 size={12} aria-hidden />{b.rank_family ? `${t.rankedBy}: ${familyName(b.rank_family, f.ar)}` : null}</div>
          <RankList rows={b.worst ?? []} tone="worst" portal={portal} f={f} testId="watch-worst" />
        </Panel>
      </div>

      {b.content !== null && (
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-12">
          <Panel title={t.bestContent} to={`/${portal}/content`} testId="watch-panel-content-best" className="lg:col-span-8">
            {basisLabel && <p className="mb-1.5 text-xs text-text-muted">{t.rankedBy}: {basisLabel}</p>}
            {b.content.best.length === 0 ? <Nothing label={t.nothing} /> : (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {b.content.best.map((c, i) => <ContentTile key={c.id ?? i} c={c} f={f} currency={cur} tone="best" rank={i + 1} basis={basis ?? null} onOpen={setOpenContent} />)}
              </div>
            )}
          </Panel>
          <Panel title={t.worstContent} to={`/${portal}/content`} testId="watch-panel-content-worst" className="lg:col-span-4">
            {b.content.worst.length === 0 ? <Nothing label={t.nothing} /> : (
              <div className="grid grid-cols-2 gap-2">
                {b.content.worst.map((c, i) => <ContentTile key={c.id ?? i} c={c} f={f} currency={cur} tone="worst" rank={i + 1} basis={basis ?? null} onOpen={setOpenContent} />)}
              </div>
            )}
          </Panel>
        </div>
      )}

      {openContent && openContent.id && (
        <AdPreviewDialog
          creative={{ id: openContent.id, name: openContent.name ?? '', provider: openContent.provider ?? '', preview: openContent.preview, metrics: { spend: openContent.spend, impressions: openContent.impressions, clicks: openContent.clicks, conversions: openContent.conversions, ctr: openContent.ctr, cpa: openContent.cpa, roas: openContent.roas } } as unknown as CreativeCard}
          locale={f.ar ? 'ar' : 'en'}
          figures={contentFigures(openContent, f, cur, basis ?? null).map((x) => ({ key: x.key, label: x.label, value: x.value }))}
          detailsTo={`/${portal}/content/${openContent.id}`}
          onClose={() => setOpenContent(null)}
        />
      )}

      <PlatformComparePanel platforms={b.platforms ?? []} family={b.rank_family ?? null} portal={portal} />

      <Link to={analytics} data-testid="watch-open-analytics" className="justify-self-center rounded-xl border border-border bg-surface px-4 py-2 text-sm font-semibold text-brand-600 shadow-[var(--shadow-small)] hover:bg-surface-hover">
        {t.analytics}
      </Link>
    </div>
  )
}

export function WatchBoard({ portal, days }: { portal: 'agency' | 'app'; days: DashboardWindow }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  /*
   * CLIENT-SCOPE-TOP-001 — on the agency portal a CLIENT chosen here is the client of every section (the top bar's choice,
   * `useAgencyClient`), and the top bar's choice opens here. A client-less project is this board's own narrowing.
   */
  const stored = useAgencyClient((s) => s.currentClientId)
  const setStored = useAgencyClient((s) => s.setCurrentClientId)
  const [local, setLocal] = useState<string | null>(null)
  const unit = local ?? (portal === 'agency' ? stored : null)
  const choose = (id: string | null) => {
    if (portal === 'agency' && (id === null || !id.startsWith('p:'))) {
      setLocal(null)
      setStored(id)

      return
    }
    setLocal(id)
  }
  const q = useWatchBoard(days, unit)
  const b = q.data
  const units = b?.units ?? []
  const shown = b ? b.unit : unit

  return (
    <section data-testid="watch-board" aria-busy={q.isFetching} className="grid min-w-0 grid-cols-1 gap-2">
      <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
        <h2 className="shrink-0 text-base font-extrabold tracking-tight text-text-primary">{t.title}</h2>
        {/*
          CLIENT-SCOPE-TOP-001 — ONE compact picker, not a row of chips: with twenty clients a chip row ran off the screen
          and read as clutter. A native select opens as the phone's own list, and on the agency portal it is the same choice
          as the top bar's (every section follows it).
        */}
        {units.length > 1 && (
          <label data-testid="watch-units" className={`relative inline-flex h-9 min-w-0 max-w-full items-center gap-1.5 self-start rounded-full border px-3 text-xs font-semibold sm:max-w-72 ${shown ? 'border-transparent bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary'}`}>
            <Building2 size={14} aria-hidden className="shrink-0" />
            <span className="sr-only">{t.clients}</span>
            <select
              data-testid="watch-unit-select"
              value={shown ?? ''}
              onChange={(e) => choose(e.target.value === '' ? null : e.target.value)}
              className="min-w-0 flex-1 cursor-pointer appearance-none truncate bg-transparent pe-4 outline-none [&>option]:bg-surface [&>option]:text-text-primary"
            >
              <option value="">{t.all}</option>
              {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <ChevronDown size={14} aria-hidden className="pointer-events-none absolute end-2.5 shrink-0" />
          </label>
        )}
      </div>

      {q.isLoading && <><div className="grid grid-cols-2 gap-2 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-28" />)}</div><Skeleton className="h-72" /></>}
      {q.isError && !b && <Nothing label={t.loadFailed} />}
      {b && (
        <div className={q.isPlaceholderData ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>
          <Body b={b} portal={portal} f={f} />
        </div>
      )}
    </section>
  )
}
