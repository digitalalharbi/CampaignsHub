import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDownRight, ArrowUpRight, BarChart3, Trophy } from 'lucide-react'
import { KpiSparkline, MetricLineChart } from '@/features/analytics/charts'
import { AdPoster } from '@/features/content/AdPoster'
import { Delta } from '@/components/ui/Delta'
import { Skeleton } from '@/components/ui/States'
import { campaigns as countedCampaigns, days as countedDays } from '@/lib/counted'
import { platformColor } from '@/lib/colors'
import { platformLabel } from '@/lib/platforms'
import { useWatchBoard, type DashboardWindow, type RankedCampaign, type WatchBoard as Board, type WatchContent } from './api'
import { Nothing, Panel, familyName, times, useBoxHeight, useFmt } from './parts'
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

/** One headline figure: the value, how it moved, and its own line over the window. Opens the analytics. */
function Stat({ label, value, to, testId, points, color, children }: { label: string; value: string; to: string; testId: string; points: number[]; color: string; children?: ReactNode }) {
  return (
    <Link
      to={to}
      data-testid={testId}
      className="group relative flex min-w-0 flex-col gap-1 overflow-hidden rounded-2xl border border-border bg-surface px-3.5 pb-1 pt-2.5 shadow-[var(--shadow-small)] transition hover:border-border-strong hover:shadow-[var(--shadow-medium)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
    >
      <span aria-hidden className="absolute inset-x-0 top-0 h-0.5" style={{ background: color }} />
      <span className="truncate text-xs font-semibold text-text-secondary">{label}</span>
      <span className="tnum truncate text-2xl font-extrabold leading-tight tracking-tight text-text-primary" data-testid={`${testId}-value`}>{value}</span>
      <span className="flex min-h-4 min-w-0 items-center gap-1.5 text-xs text-text-muted">{children}</span>
      <div className="-mx-1 h-9" aria-hidden><KpiSparkline points={points} color={color} height={36} /></div>
    </Link>
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
                  <span className="inline-flex shrink-0 items-center gap-1 text-xs text-text-muted">
                    <span aria-hidden className="h-2 w-2 rounded-full" style={{ background: platformColor(platform) }} />
                    {platformLabel(platform, f.ar)} · <span className="tnum font-semibold text-text-secondary">{f.short(r.result)}</span>
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

/** A content as it ran: the creative itself, its platform, and the two figures it is judged on. */
function ContentTile({ c, f, currency, tone }: { c: WatchContent; f: Fmt; currency: string | null; tone: 'best' | 'worst' }) {
  const t = f.ar ? T.ar : T.en
  const provider = c.provider ?? ''
  const primary = (c.conversions ?? 0) > 0 ? { label: t.conversions, value: f.short(c.conversions) } : { label: t.clicks, value: f.short(c.clicks) }
  const secondary = c.spend !== null ? { label: t.spend, value: f.money(c.spend, currency) } : { label: t.ctr, value: c.ctr === null ? '—' : f.pct(c.ctr > 1 ? c.ctr / 100 : c.ctr) }

  return (
    <figure data-testid={`watch-content-${tone}`} className="flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-surface">
      <div className="relative">
        <AdPoster preview={c.preview} name={c.name ?? ''} provider={provider} className="aspect-square h-auto w-full" />
        <span className="absolute start-1.5 top-1.5 inline-flex items-center gap-1 rounded-full bg-surface/90 px-2 py-0.5 text-xs font-semibold text-text-primary shadow-[var(--shadow-small)]">
          <span aria-hidden className="h-2 w-2 rounded-full" style={{ background: platformColor(provider) }} />
          {platformLabel(provider, f.ar)}
        </span>
      </div>
      <figcaption className="flex min-w-0 flex-col gap-1 p-2">
        <span className="truncate text-xs font-semibold text-text-primary" title={c.name ?? ''}>{c.name ?? '—'}</span>
        <span className="grid grid-cols-2 gap-1 text-xs text-text-muted">
          <span className="flex min-w-0 flex-col"><span className="truncate">{primary.label}</span><span className="tnum truncate text-xs font-bold text-text-primary">{primary.value}</span></span>
          <span className="flex min-w-0 flex-col"><span className="truncate">{secondary.label}</span><span className="tnum truncate text-xs font-bold text-text-primary">{secondary.value}</span></span>
        </span>
      </figcaption>
    </figure>
  )
}

function Body({ b, portal, f }: { b: Board; portal: 'agency' | 'app'; f: Fmt }) {
  const t = f.ar ? T.ar : T.en
  const p = b.performance ?? null
  const cur = b.currency
  const analytics = `/${portal}/dashboard${portal === 'agency' && b.unit && !b.unit.startsWith('p:') ? `?client=${b.unit}` : ''}`
  const trend = b.trend
  const impressions = sum(trend.map((d) => d.impressions))
  const clicks = sum(trend.map((d) => d.clicks))
  const ctr = impressions && clicks !== null ? clicks / impressions : null
  const chart = useBoxHeight(220)
  const resultLabel = p?.result_label?.[f.ar ? 'ar' : 'en'] ?? t.results
  const basis = b.content?.basis
  const basisLabel = basis ? (f.ar ? basis.label_ar : basis.label_en) : null

  return (
    <div className="grid min-w-0 grid-cols-1 gap-2">
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4" data-testid="watch-kpis">
        {b.can_money && (
          <Stat label={`${t.spend} · ${countedDays(b.window.days, f.ar ? 'ar' : 'en')}`} value={f.money(p?.spend, cur)} to={analytics} testId="watch-kpi-spend" points={trend.map((d) => d.spend ?? 0)} color="var(--brand-600)">
            {p && p.previous_spend !== null ? <><Delta metric="spend" current={p.spend} previous={p.previous_spend} ar={f.ar} testid="watch-spend-delta" /><span className="truncate">{t.vsPrevious}</span></> : null}
          </Stat>
        )}
        <Stat label={resultLabel} value={f.short(p?.results)} to={analytics} testId="watch-kpi-results" points={trend.map((d) => d.conversions ?? 0)} color="var(--success)">
          {p?.family ? <span className="truncate">{familyName(p.family, f.ar)}{b.can_money && p.cost_per_result !== null ? ` · ${t.cost} ${f.money(p.cost_per_result, cur)}` : ''}</span> : null}
        </Stat>
        <Stat label={t.impressions} value={f.short(impressions)} to={analytics} testId="watch-kpi-impressions" points={trend.map((d) => d.impressions)} color="var(--info)">
          {p ? <span className="truncate">{countedCampaigns(p.campaigns, f.ar ? 'ar' : 'en')}</span> : null}
        </Stat>
        <Stat label={t.clicks} value={f.short(clicks)} to={analytics} testId="watch-kpi-clicks" points={trend.map((d) => d.clicks)} color="var(--purple)">
          {ctr !== null ? <span className="truncate">{t.ctr} {f.pct(ctr)}</span> : null}
        </Stat>
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
                {b.content.best.map((c, i) => <ContentTile key={c.id ?? i} c={c} f={f} currency={cur} tone="best" />)}
              </div>
            )}
          </Panel>
          <Panel title={t.worstContent} to={`/${portal}/content`} testId="watch-panel-content-worst" className="lg:col-span-4">
            {b.content.worst.length === 0 ? <Nothing label={t.nothing} /> : (
              <div className="grid grid-cols-2 gap-2">
                {b.content.worst.map((c, i) => <ContentTile key={c.id ?? i} c={c} f={f} currency={cur} tone="worst" />)}
              </div>
            )}
          </Panel>
        </div>
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
  const [unit, setUnit] = useState<string | null>(null)
  const q = useWatchBoard(days, unit)
  const b = q.data
  const units = b?.units ?? []

  return (
    <section data-testid="watch-board" aria-busy={q.isFetching} className="grid min-w-0 grid-cols-1 gap-2">
      <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
        <h2 className="shrink-0 text-base font-extrabold tracking-tight text-text-primary">{t.title}</h2>
        {units.length > 1 && (
          <div role="radiogroup" aria-label={t.clients} data-testid="watch-units" className="flex min-w-0 gap-1.5 overflow-x-auto pb-0.5 [scrollbar-width:thin]">
            {[{ id: null as string | null, name: t.all }, ...units].map((u) => {
              const on = unit === u.id

              return (
                <button
                  key={u.id ?? 'all'}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  data-testid={`watch-unit-${u.id ?? 'all'}`}
                  onClick={() => setUnit(u.id)}
                  className={`min-h-8 shrink-0 whitespace-nowrap rounded-full border px-3 text-xs font-semibold transition-colors ${on ? 'border-transparent bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
                >
                  {u.name}
                </button>
              )
            })}
          </div>
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
