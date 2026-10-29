import { ProviderMark, ProviderName } from '@/components/ProviderMark'
import { useMemo, useState } from 'react'
import { CheckCircle2, CircleDashed, Lightbulb, Sparkles, TrendingUp, Trophy } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { KpiCard, TrendPill, platformColor } from '@/features/analytics/components'
import { ChartCard, ConversionFunnelChart, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { moneyExact, num } from '@/features/analytics/format'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import type { Locale } from '@/stores/ui'
import type { LivePayload } from '../api'
import type { ReportAd } from '../ReportAdsSection'
import { attentionAction, attentionHeadline, type AttentionItem } from '../attention'
import { ContentTile } from './LiveContent'
import { liveSpark } from './liveSpark'
import { ComparisonWithheld, kpiKeysFor, visibleKpiKeys } from './LiveKpis'
import { cardHasFigure, metricLabel, type useLiveMetricReader } from './liveMetrics'
import { landingLabel, NATIVE_LEADS_LABEL, nativeLeads } from './leadResults'

type Reader = ReturnType<typeof useLiveMetricReader>

/**
 * REPORT-EXECUTIVE-SUMMARY-001 / CLIENT-REPORT-FRAMING-001 — the summary a client's live link opens on.
 *
 * The report is a digital-marketing specialist's follow-up for the client: what was measured, what
 * the work achieved, and what is being optimised next. The owner set its frame: «لا تتطرق للإنفاق
 * أبدًا» and campaign data is campaign management's, not the client's — content names are the only
 * entity named. So this page states RESULTS: the platforms summary first (the view the owner chose as
 * the report's main summary), the results board, the shape of the period, each platform's share of
 * the results, the funnel, the best content and the content with room to improve, the
 * data's coverage, and the optimisation steps.
 *
 * Every figure is the payload's, read through the same canonical readers as the rest of the report
 * (`reader.meta`), so this view cannot disagree with another tab. Absent is shown as absent. No spend,
 * budget, cost-per or return-on-spend figure is drawn here, and no campaign is named or counted.
 * A movement against the objective is still stated — its true size and direction — in the neutral
 * tone (`calm`), not as an alarm. Picking a platform narrows the content blocks to it.
 */

/** The result an objective is judged on, in the order the reader's keys may name them. */
const RESULT_KEYS = ['purchases', 'conversions', 'leads', 'installs', 'landing_page_views', 'engagements', 'clicks', 'reach', 'impressions']
/** Each result's cost, as the totals carry it — kept for the callers that still read it; never drawn here. */
const COST_FOR: Record<string, string> = {
  purchases: 'cpa', conversions: 'cpa', leads: 'cpl', installs: 'cpi', landing_page_views: 'cost_per_lpv',
  engagements: 'cpe', clicks: 'cpc', reach: 'cpm', impressions: 'cpm',
}
/** The results board, after the headline result: outcomes first, then the reach that led to them. */
const BOARD_KEYS = ['revenue', 'purchases', 'leads', 'installs', 'landing_page_views', 'engagements', 'reach', 'impressions', 'clicks', 'ctr', 'conversion_rate']
/** Spend and every figure derived from it — never on the client's summary. */
const SPEND_DERIVED = new Set(['spend', 'roas', 'cpa', 'cpm', 'cpc', 'cpl', 'cpi', 'cpe', 'cost_per_lpv', 'budget', 'profit', 'mer', 'blended_roas'])

export function headlineKeys(payload: LivePayload, reader: Reader): { result: string; cost: string } {
  const keys = reader.keys(payload)
  const totals = payload.totals ?? {}
  // The objective's own result first, in RESULT_KEYS' priority — a reach figure never outranks an order.
  const result = RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined && Number(totals[k]) > 0)
    ?? RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined)
    ?? 'clicks'

  return { result, cost: COST_FOR[result] ?? 'cpa' }
}

/** The board's keys: the headline result, then every result figure the period actually carries. */
function resultBoardKeys(payload: LivePayload, reader: Reader, result: string): string[] {
  const t = payload.totals ?? {}
  // «Results» and «Purchases» are one figure on a sales objective; the same count is not shown twice.
  const sameAsResult = (k: string) => (k === 'purchases' || k === 'conversions') && (result === 'purchases' || result === 'conversions') && Number(t[k]) === Number(t[result])
  const carried = BOARD_KEYS.filter((k) => k !== result && !sameAsResult(k) && reader.meta[k] && !SPEND_DERIVED.has(k) && t[k] !== null && t[k] !== undefined && Number(t[k]) > 0)

  return [result, ...carried].slice(0, 8)
}

/**
 * NASEL 2026-10-29 — what the platforms summary, the split and the comparison count, read ONCE. Where verified leads are configured it is the
 * server's native lead-form figure (`results.leads`), per platform and in total, so the cards add up to the total below and a landing-page
 * completion never lands in a platform card. Otherwise the objective's own result, as before.
 */
export type ResultSource = { key: string; name: string; of: (p: Record<string, unknown>) => number | null; total: number | null }

export function resultSource(payload: LivePayload, reader: Reader, result: string, ar: boolean): ResultSource {
  const native = nativeLeads(payload)
  if (native) {
    return { key: 'form_leads', name: ar ? NATIVE_LEADS_LABEL.ar : NATIVE_LEADS_LABEL.en, of: (p) => native.of(String(p.provider ?? '')), total: native.total }
  }

  return { key: result, name: metricName(reader, result, ar, payload), of: (p) => count(p[result]), total: count(payload.totals?.[result]) }
}

const metricName = (reader: Reader, key: string, ar: boolean, payload?: LivePayload) => (reader.meta[key] ? metricLabel(reader.meta[key], key, payload, ar) : key)
const count = (v: unknown) => (v === null || v === undefined ? null : Number(v))

function Panel({ title, testid, children, className = '', action }: { title: string; testid: string; children: React.ReactNode; className?: string; action?: React.ReactNode }) {
  return (
    <section data-testid={testid} className={`min-w-0 rounded-2xl border border-border bg-surface p-4 ${className}`}>
      <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-text-primary">{title}</h3>
        {action}
      </header>
      {children}
    </section>
  )
}

export function ExecutiveSummary({
  payload,
  reader,
  currency,
  locale,
  onOpenContent,
  sectionOn,
}: {
  payload: LivePayload
  reader: Reader
  currency: string
  locale: Locale
  onOpenContent: (content: ReportAd) => void
  sectionOn: (key: 'kpis' | 'trends' | 'platform_comparison' | 'funnel' | 'content_performance' | 'recommendations') => boolean
}) {
  const ar = locale === 'ar'
  const [platform, setPlatform] = useState<string | null>(null)
  const { result } = headlineKeys(payload, reader)
  const source = resultSource(payload, reader, result, ar)
  const funnelShown = sectionOn('funnel') && (payload.funnel ?? []).filter((s) => s.reported).length >= 2

  const platformRows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  const manyPlatforms = platformRows.length > 1
  // One platform: its card, its donut (a single 100% slice) and its table all say the same thing.
  // The compact page states it once — in the KPI row — and spends the room on the trend and the funnel.
  const coverageNeeded = (payload.freshness ?? []).some((f) => f.state === 'awaiting_credentials')
  const improveShown = sectionOn('content_performance') && (payload.ads_weakest ?? []).length > 0

  return (
    <div data-testid="live-mode-summary" className="flex flex-col gap-4">
      {/* The platforms summary is the Owner's chosen main view (CLIENT-REPORT-FRAMING-001): it leads. */}
      {sectionOn('platform_comparison') && <PlatformCards payload={payload} ar={ar} source={source} selected={platform} onSelect={setPlatform} />}

      {sectionOn('kpis') && <ResultsBoard payload={payload} reader={reader} ar={ar} result={result} />}

      {(sectionOn('trends') || sectionOn('platform_comparison') || funnelShown) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {sectionOn('trends') && (
            <TrendPanel payload={payload} reader={reader} ar={ar} currency={currency} source={source} wide={!(manyPlatforms && sectionOn('platform_comparison')) && !funnelShown} />
          )}
          {manyPlatforms && sectionOn('platform_comparison') && (
            <ResultsSplit payload={payload} ar={ar} source={source} wide={!sectionOn('trends')} selected={platform} onSelect={setPlatform} />
          )}
          {!manyPlatforms && funnelShown && <FunnelPanel payload={payload} ar={ar} narrow />}
        </div>
      )}

      {manyPlatforms && sectionOn('platform_comparison') && (
        <PlatformComparison payload={payload} reader={reader} ar={ar} source={source} selected={platform} onSelect={setPlatform} wide />
      )}

      {sectionOn('content_performance') && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <ContentBlock kind="best" half={improveShown} payload={payload} locale={locale} currency={currency} platform={platform} onClear={() => setPlatform(null)} onOpen={onOpenContent} />
          <ContentBlock kind="improve" half payload={payload} locale={locale} currency={currency} platform={platform} onClear={() => setPlatform(null)} onOpen={onOpenContent} />
        </div>
      )}

      {(manyPlatforms && funnelShown) || coverageNeeded ? (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {manyPlatforms && funnelShown && <FunnelPanel payload={payload} ar={ar} wide={!coverageNeeded} />}
          {coverageNeeded && <CoverageStrip payload={payload} ar={ar} wide={!(manyPlatforms && funnelShown)} />}
        </div>
      ) : null}

      {nativeLeads(payload) && <LeadSummary payload={payload} reader={reader} ar={ar} currency={currency} />}

      {sectionOn('recommendations') && <OptimisationSteps payload={payload} ar={ar} onOpenContent={onOpenContent} />}
    </div>
  )
}

/* ─────────────────────────────── Platforms summary ─────────────────────────────── */

const shownPlatform = (p: Record<string, unknown>, source: ResultSource) => Number(p.impressions ?? 0) > 0 || Number(p.clicks ?? 0) > 0 || Number(source.of(p) ?? 0) > 0

function PlatformCards({ payload, ar, source, selected, onSelect }: { payload: LivePayload; ar: boolean; source: ResultSource; selected: string | null; onSelect: (p: string | null) => void }) {
  const rows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  if (rows.length === 0) return null
  const total = rows.reduce((s, p) => s + Number(source.of(p) ?? 0), 0)
  const resultName = source.name
  const ctr = (p: Record<string, unknown>) => (p.ctr === null || p.ctr === undefined ? null : `${(Number(p.ctr) * 100).toFixed(2)}%`)
  const card = (key: string | null, title: string, value: number | null, impressions: number | null, rate: string | null, share: number | null, color: string) => {
    const on = selected === key

    return (
      <button
        key={key ?? 'all'}
        type="button"
        role="tab"
        aria-selected={on}
        data-testid={`exec-platform-card-${key ?? 'all'}`}
        onClick={() => onSelect(key === null || on ? null : key)}
        className={`min-w-0 overflow-hidden rounded-2xl border bg-surface text-start transition-colors ${on ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-border hover:border-brand-400'}`}
      >
        <span className="block h-1.5" style={{ background: color }} aria-hidden />
        <span className="flex flex-col gap-1 p-3">
          {/* The platform's logo and name (Owner, 2026-10-19); «all platforms» is a word, not a platform. */}
          <span className="flex min-w-0 text-sm font-bold text-text-primary">
            {key === null ? <span className="truncate">{title}</span> : <ProviderName provider={key} locale={ar ? 'ar' : 'en'} size={20} />}
          </span>
          <span className="tnum truncate text-xl font-extrabold text-text-primary"><Num>{num(value)}</Num></span>
          <span className="truncate text-[11px] font-semibold text-text-muted">{resultName}</span>
          <span className="tnum mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-secondary">
            {impressions !== null && <span>{ar ? 'الظهور' : 'Impr.'} <Num>{num(impressions)}</Num></span>}
            {rate !== null && <span>{ar ? 'نسبة النقر' : 'CTR'} <Num>{rate}</Num></span>}
          </span>
          {share !== null && (
            <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-label={ar ? 'الحصة من النتائج' : 'Share of results'}>
              <span className="block h-full rounded-full" style={{ width: `${Math.round(share * 100)}%`, background: color }} />
            </span>
          )}
        </span>
      </button>
    )
  }

  /*
   * REPORT-COMPOSITION-CONTROL-001 — the cards fill the row. A fixed six-column grid left four cards
   * in a narrow cluster with two empty slots beside them; the row now has exactly as many columns as
   * cards (up to six), so the summary is as wide as the report under it.
   */
  const cards = rows.length + (rows.length > 1 ? 1 : 0)

  return (
    <section data-testid="exec-platform-cards" aria-label={ar ? 'ملخص المنصات' : 'Platforms summary'} className="flex flex-col gap-2">
      <h2 className="text-base font-extrabold text-text-primary">{ar ? 'ملخص المنصات' : 'Platforms summary'}</h2>
      <div
        role="tablist"
        data-cards={cards}
        className="grid grid-cols-2 gap-3 md:grid-cols-[repeat(var(--cards-md),minmax(0,1fr))] xl:grid-cols-[repeat(var(--cards),minmax(0,1fr))]"
        style={{ '--cards': Math.min(cards, 6), '--cards-md': cards <= 4 ? cards : 3 } as React.CSSProperties}
      >
        {rows.length > 1 && card(null, ar ? 'كل المنصات' : 'All platforms', source.total, count(payload.totals?.impressions), ctr(payload.totals ?? {}), null, 'var(--brand-600)')}
        {rows.map((p) => {
          const key = canonicalPlatform(p.provider)
          return card(key, providerLabel(key, ar ? 'ar' : 'en'), source.of(p), count(p.impressions), ctr(p), total > 0 ? Number(source.of(p) ?? 0) / total : null, platformColor(key))
        })}
      </div>
    </section>
  )
}

/* ─────────────────────────────── Results board ─────────────────────────────── */

function ResultsBoard({ payload, reader, ar, result }: { payload: LivePayload; reader: Reader; ar: boolean; result: string }) {
  const t = payload.totals
  const withheld = payload.comparison !== undefined && !payload.comparison.comparable
  const d: Record<string, number | null | undefined> = withheld ? {} : (payload.deltas ?? {})
  // The Owner's own KPI selection leads — spend and its cost when selected and valid — then the
  // results the period carried. Same selection as the dashboard, so the two cannot disagree.
  // With no selection made in setup the client summary stays results-only (the standing framing
  // rule); with one, the selection is the truth and spend shows when it was ticked and is valid.
  const chosenInSetup = (payload.metrics?.length ?? 0) > 0
  const selected = visibleKpiKeys(payload, reader, kpiKeysFor(payload, reader)).filter((k) => chosenInSetup || !SPEND_DERIVED.has(k))
  const extras = resultBoardKeys(payload, reader, result).filter((k) => !selected.includes(k) && !(k === 'leads' && t.leads !== null && t.leads !== undefined && t.leads === t.conversions))
    // Verified leads configured: the platform's pixel «Lead» is never offered beside them as if it were the same figure.
    .filter((k) => !(k === 'leads' && nativeLeads(payload) !== null))
  const keys = [...selected, ...extras].slice(0, 8)
  const card = (key: string) => {
    const meta = reader.meta[key]
    if (!meta) return null
    const read = meta.format(t, payload, reader.asMoney, reader.count)
    if (!cardHasFigure(key, read.text, payload)) return null

    return (
      <KpiCard key={key} label={metricLabel(meta, key, payload, ar)} value={read.text} exact={read.exact ?? undefined} delta={d[key]} invertGood={meta.invertGood} spark={liveSpark(key, payload.timeseries)} accent="var(--purple)" calm />
    )
  }

  const compact = (key: string) => {
    const meta = reader.meta[key]
    if (!meta) return null
    const read = meta.format(t, payload, reader.asMoney, reader.count)
    if (!cardHasFigure(key, read.text, payload)) return null

    return (
      <div key={key} className="min-w-0" data-testid={`exec-kpi-compact-${key}`}>
        <dt className="text-[11px] text-text-muted">{metricLabel(meta, key, payload, ar)}</dt>
        <dd className="tnum text-sm font-bold text-text-primary" title={read.exact ?? undefined}><Num>{read.text}</Num></dd>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <ComparisonWithheld payload={payload} ar={ar} />
      <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4" data-testid="live-kpis">{keys.slice(0, 4).map(card)}</div>
      {/*
        REPORT-KPI-HIERARCHY-001 — four large primary cards, then the rest COMPACT: one strip of label and
        figure, never a second row of equally large cards («never eight to ten equal cards»).
      */}
      {keys.length > 4 && (
        <dl className="flex flex-wrap gap-x-5 gap-y-2 rounded-xl border border-border bg-surface px-3 py-2" data-testid="exec-kpis-secondary">
          {keys.slice(4).map(compact)}
        </dl>
      )}
    </div>
  )
}

/* ─────────────────────────────── Trend ─────────────────────────────── */

function TrendPanel({ payload, reader, ar, currency, source, wide }: { payload: LivePayload; reader: Reader; ar: boolean; currency: string; source: ResultSource; wide: boolean }) {
  const result = source.key
  const options = [
    { key: result, name: source.key === 'form_leads' ? source.name : metricName(reader, result, ar, payload), color: 'var(--purple)', kind: 'num' as const },
    ...(result !== 'clicks' ? [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }] : []),
    ...(result !== 'impressions' ? [{ key: 'impressions', name: ar ? 'الظهور' : 'Impressions', color: 'var(--brand-600)', kind: 'num' as const }] : []),
  ]
  const [on, setOn] = useState<string[]>(options.slice(0, 2).map((o) => o.key))
  const series = options.filter((o) => on.includes(o.key))
  // The larger-scale series takes the second axis, so a few hundred results are not flattened under thousands of clicks.
  const rightAxisFor = series.length > 1 ? (on.includes('impressions') ? 'impressions' : on.includes('clicks') ? 'clicks' : undefined) : undefined

  return (
    <div className={wide ? 'lg:col-span-12' : 'lg:col-span-8'}>
      <ChartCard title={ar ? 'النتائج خلال الفترة' : 'Results over the period'}>
        <div className="mb-2 flex flex-wrap gap-1.5" data-testid="exec-trend-toggles">
          {options.map((o) => (
            <button
              key={o.key}
              type="button"
              aria-pressed={on.includes(o.key)}
              data-testid={`exec-trend-${o.key}`}
              onClick={() => setOn((cur) => (cur.includes(o.key) ? (cur.length > 1 ? cur.filter((k) => k !== o.key) : cur) : [...cur, o.key]))}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold ${on.includes(o.key) ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: o.color }} />
              {o.name}
            </button>
          ))}
        </div>
        <div data-testid="exec-trend">
          <MetricLineChart data={payload.timeseries ?? []} currency={currency} height={230} rightAxisFor={rightAxisFor} series={series} />
        </div>
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Results split ─────────────────────────────── */

function ResultsSplit({ payload, ar, source, wide, selected, onSelect }: { payload: LivePayload; ar: boolean; source: ResultSource; wide: boolean; selected: string | null; onSelect: (p: string | null) => void }) {
  const rows = (payload.platforms ?? []).flatMap((p) => {
    const value = Number(source.of(p) ?? 0)
    return value > 0 ? [{ key: canonicalPlatform(p.provider), name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value }] : []
  })
  const total = rows.reduce((s, r) => s + r.value, 0)
  const resultName = source.name

  return (
    <Panel title={ar ? `من أين جاءت ${resultName}` : `Where the ${resultName.toLowerCase()} came from`} testid="exec-results-split" className={wide ? 'lg:col-span-12' : 'lg:col-span-4'}>
      {total <= 0 ? (
        <p className="flex h-[200px] items-center justify-center text-center text-sm text-text-muted">
          {ar ? 'لم تُسجَّل نتائج موزّعة على المنصات في هذه الفترة' : 'No results by platform were recorded in this period'}
        </p>
      ) : (
        <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
          <PlatformDonutChart data={rows.map((r) => ({ name: r.name, value: r.value, key: r.key }))} height={170} legend={false} centerValue={num(total)} centerLabel={resultName} />
          <ul className="flex flex-col gap-1.5">
            {[...rows].sort((a, b) => b.value - a.value).map((r) => (
              <li key={r.key}>
                <button
                  type="button"
                  data-testid={`exec-split-${r.key}`}
                  aria-pressed={selected === r.key}
                  onClick={() => onSelect(selected === r.key ? null : r.key)}
                  className={`flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs ${selected === r.key ? 'bg-brand-500/10' : 'hover:bg-surface-hover'}`}
                >
                  <span className="flex min-w-0 items-center gap-1.5 text-text-secondary">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(r.key) }} />
                    <ProviderMark provider={r.key} size={16} />
                    <span className="truncate">{r.name}</span>
                  </span>
                  <span className="tnum font-bold text-text-primary"><Num>{`${Math.round((r.value / total) * 100)}%`}</Num></span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Panel>
  )
}

/* ─────────────────────────────── Platform comparison ─────────────────────────────── */

function PlatformComparison({
  payload, reader, ar, source, selected, onSelect, wide,
}: { payload: LivePayload; reader: Reader; ar: boolean; source: ResultSource; selected: string | null; onSelect: (p: string | null) => void; wide: boolean }) {
  const result = source.key
  const rows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  const extra = (['impressions', 'clicks'] as const).filter((k) => k !== result)
  const maxResult = Math.max(0, ...rows.map((p) => Number(source.of(p) ?? 0)))
  const ctrText = (p: Record<string, unknown>) => (p.ctr === null || p.ctr === undefined ? '—' : `${(Number(p.ctr) * 100).toFixed(2)}%`)

  return (
    <Panel
      title={ar ? 'مقارنة نتائج المنصات' : 'Results by platform'}
      testid="exec-platforms"
      className={wide ? 'lg:col-span-12' : 'lg:col-span-7'}
      action={selected ? (
        <button type="button" onClick={() => onSelect(null)} className="text-[11px] font-semibold text-accent hover:underline" data-testid="exec-platform-clear">
          {ar ? 'كل المنصات' : 'All platforms'}
        </button>
      ) : undefined}
    >
      {rows.length === 0 ? (
        <p className="text-sm text-text-muted">{ar ? 'لا نتائج مسجّلة لأي منصة في هذه الفترة' : 'No platform recorded results in this period'}</p>
      ) : (
        <div className="min-w-0 text-xs" data-testid="exec-platform-table">
          <MetricTable
            head={[ar ? 'المنصة' : 'Platform', source.name, ...extra.map((k) => metricName(reader, k, ar, payload)), ar ? 'نسبة النقر' : 'CTR']}
            rows={rows.map((p) => {
              const key = canonicalPlatform(p.provider)
              const value = source.of(p)
              const mov = (p as { movement?: Record<string, number | null> }).movement?.[result]

              return [
                <button
                  key="p"
                  type="button"
                  data-testid={`exec-platform-${key}`}
                  aria-pressed={selected === key}
                  onClick={() => onSelect(selected === key ? null : key)}
                  className={`flex items-center gap-1.5 rounded-lg px-1.5 py-0.5 font-semibold text-text-primary ${selected === key ? 'bg-brand-500/15' : 'hover:bg-surface-hover'}`}
                >
                  {/* REPORT-PLATFORM-ICONS-001 — the one provider mark every table uses, in place of a bare colour dot. */}
                  <ProviderMark provider={key} size={16} />
                  {providerLabel(key, ar ? 'ar' : 'en')}
                </button>,
                <span key="r" className="inline-flex items-center gap-2">
                  <span className="h-1.5 w-14 overflow-hidden rounded-full bg-surface-secondary">
                    <span className="block h-full rounded-full" style={{ width: `${value === null || maxResult <= 0 ? 0 : Math.round((value / maxResult) * 100)}%`, background: platformColor(key) }} />
                  </span>
                  <span className="font-semibold text-text-primary"><Num>{num(value)}</Num></span>
                  {mov !== undefined && mov !== null && <TrendPill delta={mov} calm />}
                </span>,
                ...extra.map((k) => <span key={k} className="text-text-primary"><Num>{num(count(p[k]))}</Num></span>),
                <span key="ctr" className="text-text-secondary"><Num>{ctrText(p)}</Num></span>,
              ]
            })}
            values={rows.map((p): SortValues => [
              providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'),
              source.of(p),
              ...extra.map((k) => count(p[k])),
              count(p.ctr),
            ])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </div>
      )}
      <p className="mt-2 text-[11px] text-text-muted">{ar ? 'اضغط منصة لترى محتواها أدناه.' : 'Pick a platform to see its content below.'}</p>
    </Panel>
  )
}

/* ─────────────────────────────── Lead summary ─────────────────────────────── */

/**
 * NASEL 2026-10-29 — the small cards under the report: native leads, then the landing-page funnel (visits, form starts, contact taps,
 * completions, completion rate), their sum («total interest»), and spend with its costs. Every figure is the server's one resolver
 * (`results.leads`, its `landing_funnel` over landing campaigns only), so nothing here is a second calculation; a step no platform reports
 * reads «غير متاح». Spend and its costs appear only where the link shows spend.
 */
function LeadSummary({ payload, reader, ar, currency }: { payload: LivePayload; reader: Reader; ar: boolean; currency: string }) {
  const leads = payload.results?.leads
  const native = nativeLeads(payload)
  if (!leads || !native) return null
  const funnel = leads.landing_funnel
  const na = ar ? 'غير متاح' : 'Not available'
  const n = (v: number | null | undefined) => (v === null || v === undefined ? na : num(Number(v)))
  const moneyOrNa = (v: number | null | undefined) => (v === null || v === undefined ? na : moneyExact(Number(v), currency))
  const rate = (v: number | null | undefined) => (v === null || v === undefined ? na : `${(Number(v) * 100).toFixed(1)}%`)
  const spendShown = reader.keys(payload).includes('spend') && payload.totals?.spend !== null && payload.totals?.spend !== undefined
  const items: Array<[string, string, string]> = [
    ['native', ar ? NATIVE_LEADS_LABEL.ar : NATIVE_LEADS_LABEL.en, n(native.total)],
    ['page-views', ar ? 'زيارات الصفحة' : 'Page visits', n(funnel ? funnel.page_views : count(payload.totals?.landing_page_views))],
    ['form-starts', ar ? 'بدء تعبئة النموذج' : 'Form starts', n(funnel?.form_starts)],
    ['contacts', ar ? 'ضغطات واتساب/اتصال/إيميل' : 'WhatsApp / call / email taps', n(funnel?.contacts)],
    ['landing', `${landingLabel(payload, ar)} ${ar ? '(منسوبة للمنصات)' : '(platform-attributed)'}`, n(leads.landing)],
    // NASEL 2026-10-29 — submissions the website itself stored: no source for them is connected, so the card says so rather than borrowing
    // the platforms' attributed count, which can claim one submission on several platforms.
    ['confirmed', ar ? 'نماذج مؤكدة من الموقع' : 'Confirmed website leads', ar ? 'غير مربوط' : 'Not linked'],
    ['completion-rate', ar ? 'معدل الإكمال' : 'Completion rate', rate(funnel?.completion_rate)],
    ['interest', ar ? 'إجمالي الاهتمام' : 'Total interest', n(leads.total)],
    ...(spendShown
      ? ([
          ['spend', ar ? 'الإنفاق' : 'Spend', moneyExact(Number(payload.totals.spend), currency)],
          ['cost', ar ? 'تكلفة النتيجة' : 'Cost per result', moneyOrNa(leads.cost)],
          ['landing-cost', ar ? 'تكلفة إتمام نموذج الصفحة' : 'Cost per landing completion', moneyOrNa(funnel?.cost_per_completion)],
        ] as Array<[string, string, string]>)
      : []),
  ]

  return (
    <section className="flex flex-col gap-1" aria-label={ar ? 'العملاء المحتملون وأحداث صفحة الهبوط' : 'Leads and landing-page events'}>
      <dl className="grid grid-cols-2 gap-2 rounded-2xl border border-border bg-surface p-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="exec-lead-summary">
        {items.map(([key, label, value]) => (
          <div key={key} className="min-w-0" data-testid={`exec-lead-${key}`}>
            <dt className="truncate text-[11px] text-text-muted">{label}</dt>
            <dd className="tnum text-sm font-bold text-text-primary"><Num>{value}</Num></dd>
          </div>
        ))}
      </dl>
      {/* What the counts are and are not: platform attributions, never unique people or deduplicated submissions. */}
      <p className="text-[11px] text-text-muted" data-testid="exec-lead-basis">
        {ar
          ? 'أحداث صفحة الهبوط من مسار صفحة الهبوط وحده، كما تنسبها كل منصة — ليست عددًا فريدًا للأشخاص أو للنماذج المؤكدة.'
          : 'Landing-page events from the landing-page path only, as each platform attributes them — not a count of unique people or confirmed submissions.'}
      </p>
      <p className="text-[11px] text-text-muted" data-testid="exec-lead-confirmed-source">
        {ar
          ? 'النماذج المؤكدة تتطلب ربط سجل الطلبات المحفوظة في الموقع بمعرّف lead_id ثابت يصدره الخادم؛ هذا المصدر غير مربوط بعد.'
          : 'Confirmed leads need the website’s stored requests, linked by a server-issued lead_id; that source is not linked yet.'}
      </p>
    </section>
  )
}

/* ─────────────────────────────── Funnel ─────────────────────────────── */

function FunnelPanel({ payload, ar, narrow = false, wide = false }: { payload: LivePayload; ar: boolean; narrow?: boolean; wide?: boolean }) {
  // The stages without their cost: the client's funnel shows how far people went, not what each step cost.
  const stages = (payload.funnel ?? []).filter((s) => s.reported).map((s) => ({ ...s, cost_per: null }))

  return (
    /*
     * REPORT-COMPOSITION-CONTROL-001 — alone on its row the funnel spans the grid, aligned like the
     * dashboard's «قمع الأداء»; it used to keep a seven-column width with nothing beside it.
     */
    <div className={narrow ? 'lg:col-span-4' : wide ? 'lg:col-span-12' : 'lg:col-span-7'} data-testid="exec-funnel" data-wide={wide ? 'true' : undefined}>
      <ChartCard title={ar ? 'من الظهور إلى النتيجة' : 'From impression to result'}>
        <ConversionFunnelChart stages={stages} ar={ar} />
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Content ─────────────────────────────── */

/**
 * «مرتّبة على أداء المبيعات» — which objective the headline order rests on, when the scope mixed several.
 *
 * CLIENT-REPORT-FRAMING-001: the client's summary names no money metric, so the line names the FAMILY
 * (sales, traffic, awareness…) and not the ROAS or cost the server ranked on. A single-objective scope
 * needs no line: the order is the report's own objective.
 */
export function basisLine(basis: LivePayload['ads_basis'], ar: boolean): string | null {
  if (!basis || !basis.metric || !basis.family) return null
  const family = (ar ? basis.family_label_ar : basis.family_label_en) ?? basis.family
  return ar ? `مرتّبة على أداء ${family}` : `Ranked by ${family.toLowerCase()} performance`
}

function ContentBlock({ kind, half = false, payload, locale, currency, platform, onClear, onOpen }: {
  kind: 'best' | 'improve'; half?: boolean; payload: LivePayload; locale: Locale; currency: string; platform: string | null; onClear: () => void; onOpen: (c: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const all = useMemo(() => (kind === 'best' ? payload.ads : payload.ads_weakest) ?? [], [kind, payload])
  // A creative already among the best is not also listed as one to improve.
  const best = useMemo(() => new Set((payload.ads ?? []).slice(0, 4).map((a) => a.content_key).filter(Boolean)), [payload])
  const items = useMemo(
    () => all
      .filter((a) => kind === 'best' || !best.has(a.content_key))
      .filter((a) => platform === null || (a.provider && canonicalPlatform(a.provider) === platform))
      .slice(0, 4),
    [all, best, kind, platform],
  )
  const title = kind === 'best' ? (ar ? 'أفضل المحتوى أداءً' : 'Best performing content') : (ar ? 'محتوى بفرصة للتحسين' : 'Content with room to improve')
  const Icon = kind === 'best' ? Trophy : Sparkles

  if (kind === 'improve' && items.length === 0) return null

  return (
    <Panel
      title={platform === null ? title : `${title} — ${providerLabel(platform, locale)}`}
      testid={kind === 'best' ? 'exec-best-content' : 'exec-improve-content'}
      className={half ? 'lg:col-span-6' : 'lg:col-span-12'}
      action={(
        <span className="flex items-center gap-2">
          {platform !== null && <button type="button" onClick={onClear} className="text-[11px] font-semibold text-accent hover:underline">{ar ? 'كل المنصات' : 'All platforms'}</button>}
          <Icon size={15} className={kind === 'best' ? 'text-warning' : 'text-info'} aria-hidden />
        </span>
      )}
    >
      {kind === 'improve' && (
        <p className="-mt-1 mb-3 text-[11px] text-text-muted">{ar ? 'ما نعمل على تحسينه في الفترة القادمة.' : 'What we are working to improve next.'}</p>
      )}
      {/* REPORT-BEST-NOT-SPEND-001 — the order says what it rests on; a client never reads «best» as «most expensive». */}
      {items.length > 0 && basisLine(payload.ads_basis, ar) && (
        <p className="-mt-1 mb-3 text-[11px] text-text-muted" data-testid={kind === 'best' ? 'exec-best-basis' : 'exec-improve-basis'}>{basisLine(payload.ads_basis, ar)}</p>
      )}
      {items.length === 0 ? (
        <p data-testid="exec-best-content-empty" className="text-sm text-text-muted">
          {platform === null
            ? (ar ? 'لا محتوى بنتائج قابلة للترتيب في هذه الفترة' : 'No content with rankable results in this period')
            : (ar ? 'لا محتوى مرتّب لهذه المنصة في هذه الفترة' : 'No ranked content for this platform in this period')}
        </p>
      ) : (
        <div className={`grid grid-cols-2 gap-3 ${half ? 'lg:grid-cols-2' : 'lg:grid-cols-4'}`}>
          {items.map((ad, i) => (
            <ContentTile key={ad.content_key ?? `${ad.provider}-${i}`} content={ad} locale={locale} currency={currency || null} onOpen={onOpen} rank={kind === 'best' ? i + 1 : undefined} resultsOnly />
          ))}
        </div>
      )}
    </Panel>
  )
}

/* ─────────────────────────────── Data coverage ─────────────────────────────── */

const STORES: Record<string, { ar: string; en: string }> = { salla: { ar: 'سلة', en: 'Salla' }, zid: { ar: 'زد', en: 'Zid' }, shopify: { ar: 'شوبيفاي', en: 'Shopify' } }
const sourceName = (provider: string, ar: boolean) => STORES[provider]?.[ar ? 'ar' : 'en'] ?? providerLabel(canonicalPlatform(provider), ar ? 'ar' : 'en')

/** The newest day in the window that carries any figure — not the link's allowed range, which can run ahead of the data. */
function lastDataDay(payload: LivePayload): string {
  const days = (payload.timeseries ?? [])
    .filter((row) => ['spend', 'impressions', 'clicks', 'conversions'].some((k) => Number(row[k] ?? 0) > 0))
    .map((row) => String(row.date ?? ''))
    .filter((d) => d !== '')
    .sort()

  return days[days.length - 1] ?? payload.period.to
}

function CoverageStrip({ payload, ar, wide }: { payload: LivePayload; ar: boolean; wide: boolean }) {
  const missing = (payload.freshness ?? []).filter((f) => f.state === 'awaiting_credentials')
  const covered = (payload.freshness ?? []).filter((f) => f.state === 'synced')

  return (
    <div className={`min-w-0 rounded-2xl border border-border bg-surface p-4 ${wide ? 'lg:col-span-12' : 'lg:col-span-5'}`} data-testid="exec-health">
      <div data-testid="exec-health-sources">
        <p className="text-sm font-bold text-text-primary">{ar ? 'مصادر القياس' : 'Measurement sources'}</p>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {covered.map((f) => (
            <span key={`ok-${f.provider}-${f.name ?? ''}`} className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-secondary">
              <CheckCircle2 size={11} className="text-success" />{sourceName(f.provider, ar)}
            </span>
          ))}
          {missing.map((f) => (
            <span key={`miss-${f.provider}-${f.name ?? ''}`} data-testid={`exec-source-missing-${f.provider}`} className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-muted">
              <CircleDashed size={11} />{sourceName(f.provider, ar)} — {ar ? 'غير مشمولة' : 'not included'}
            </span>
          ))}
        </div>
        <p className="mt-2 text-[11px] text-text-muted" data-testid="exec-data-through">{ar ? 'آخر يوم ببيانات' : 'Last day with data'} <Num>{lastDataDay(payload)}</Num></p>
      </div>
    </div>
  )
}

/* ─────────────────────────────── Optimisation steps ─────────────────────────────── */

/** The signals a client's summary states: delivery and response, never spend, cost or return on spend. */
const CLIENT_SIGNALS = new Set(['ctr_rise', 'ctr_drop', 'lpv_rate_rise', 'lpv_rate_drop'])

function OptimisationSteps({ payload, ar, onOpenContent }: { payload: LivePayload; ar: boolean; onOpenContent: (c: ReportAd) => void }) {
  const items: AttentionItem[] = (payload.attention ?? [])
    .filter((a) => a.decision !== 'hidden' && CLIENT_SIGNALS.has(a.code))
    // What is working leads; what is being optimised follows.
    .sort((a, b) => Number(a.nature === 'problem') - Number(b.nature === 'problem'))
  if (items.length === 0) return null
  const open = (item: AttentionItem) => {
    const key = item.evidence?.content?.[0]?.content_key
    const ad = key ? (payload.ads ?? []).find((a) => a.content_key === key) : undefined
    if (ad) onOpenContent(ad)
  }

  return (
    <section data-testid="exec-actions" aria-label={ar ? 'خطوات التحسين' : 'Optimisation steps'}>
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Lightbulb size={15} className="text-warning" />{ar ? 'خطوات التحسين' : 'Optimisation steps'}</h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.slice(0, 6).map((item) => {
          const hasEvidence = !!item.evidence?.content?.[0]?.content_key
          const working = item.nature !== 'problem'

          return (
            <article key={item.key} data-testid={`exec-action-${item.key}`} className="relative flex min-w-0 flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3 ps-4">
              <span className={`absolute inset-y-0 start-0 w-1 ${working ? 'bg-success' : 'bg-info'}`} />
              <div className="flex items-center justify-between gap-2">
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${working ? 'bg-[var(--positive-background)] text-success' : 'bg-surface-secondary text-info'}`}>
                  {working ? <TrendingUp size={10} /> : <Sparkles size={10} />}
                  {working ? (ar ? 'ما ينجح' : 'Working') : (ar ? 'قيد التحسين' : 'Being optimised')}
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] text-text-muted">
                  <ProviderMark provider={canonicalPlatform(item.platform)} size={14} />
                  {providerLabel(canonicalPlatform(item.platform), ar ? 'ar' : 'en')}
                </span>
              </div>
              <p className="text-sm font-bold leading-snug text-text-primary">{attentionHeadline(item.code, ar)}</p>
              <button
                type="button"
                disabled={!hasEvidence}
                onClick={() => open(item)}
                className="mt-auto inline-flex items-center justify-between gap-2 rounded-xl bg-surface-secondary px-3 py-2 text-start text-xs font-semibold text-text-primary enabled:hover:bg-surface-hover"
                data-testid="exec-action-do"
              >
                <span>{attentionAction(item.action, ar)}</span>
              </button>
            </article>
          )
        })}
      </div>
    </section>
  )
}
