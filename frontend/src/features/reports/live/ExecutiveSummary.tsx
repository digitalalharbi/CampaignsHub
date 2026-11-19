import { ProviderMark } from '@/components/ProviderMark'
import { useMemo, useState } from 'react'
import { CheckCircle2, CircleDashed, Clock, Lightbulb, Sparkles, TrendingUp, Trophy } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { KpiCard, TrendPill, platformColor } from '@/features/analytics/components'
import { ChartCard, ConversionFunnelChart, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { moneyExact, num } from '@/features/analytics/format'
import { canonicalPlatform, isAdPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import type { Locale } from '@/stores/ui'
import type { LivePayload } from '../api'
import type { ReportAd } from '../ReportAdsSection'
import { attentionAction, attentionHeadline, type AttentionItem } from '../attention'
import { ContentTile } from './LiveContent'
import { liveSpark } from './liveSpark'
import { ComparisonWithheld, kpiKeysFor, visibleKpiKeys } from './LiveKpis'
import { cardHasFigure, metricLabel } from './liveMetrics'
import { landingLabel, NATIVE_LEADS_LABEL, nativeLeads, revenueReported } from './leadResults'
import {
  basisLine, contentLists, count, funnelStages, headlineKeys, metricName, platformCards, rankPlatforms, resultBoardKeys, resultSource, resultsSplit,
  rightAxisFor, shownPlatform, splitTitle, SPEND_DERIVED, toggleTrend, trendOptions, type Reader, type ResultSource,
} from '@/features/reportLogic'
import { PlatformCardsView } from '@/features/reportLogic/PlatformCardsView'

/* REPORT-LOGIC-001 — the result logic lives in `features/reportLogic`; the old names are re-exported below. */
export { headlineKeys, resultSource, splitTitle, basisLine }
export type { ResultSource }


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
  const coverageNeeded = (payload.freshness ?? []).some((f) => f.state === 'awaiting_credentials' && isAdPlatform(f.provider))
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

      {/* NASEL 2026-10-29 — the lead strip is the conversion path's: «مسار التحويل» off hides it. */}
      {nativeLeads(payload) && sectionOn('funnel') && <LeadSummary payload={payload} reader={reader} ar={ar} currency={currency} />}

      {sectionOn('recommendations') && <OptimisationSteps payload={payload} ar={ar} onOpenContent={onOpenContent} />}
    </div>
  )
}

/* ─────────────────────────────── Platforms summary ─────────────────────────────── */

function PlatformCards({ payload, ar, source, selected, onSelect }: { payload: LivePayload; ar: boolean; source: ResultSource; selected: string | null; onSelect: (p: string | null) => void }) {
  // REPORT-LOGIC-001 — the cards are the shared layer's model and the shared layer's view.
  return <PlatformCardsView models={platformCards(payload, source, ar)} resultName={source.name} ar={ar} selected={selected} onSelect={onSelect} />
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
    // Verified leads configured: the platform's pixel «Lead» is never offered beside them as if it were the same figure, and the funnel steps
    // (checkout = the form start, the basket) belong to the lead strip's landing funnel, not to a second all-campaign count beside it.
    .filter((k) => !(nativeLeads(payload) !== null && (k === 'leads' || k === 'checkout' || k === 'add_to_cart')))
    // NASEL 2026-10-29 — no revenue reported anywhere (a lead purchase carries value 0): no revenue and no ROAS card.
    .filter((k) => revenueReported(payload) || (k !== 'revenue' && k !== 'roas'))
  const keys = [...selected, ...extras].slice(0, 8)
  /* REPORT-COMPACT-001 — the small cards fill their row: the delivery rates (CPM, CPC, CTR, frequency) join them when the period has them. */
  // A link whose operator chose its metrics shows ONLY those: an unchosen rate (and never a cost-per-click standing in for a hidden result) stays off.
  const fill = ['ctr', 'cpm', 'cpc', 'frequency'].filter((k) => !keys.includes(k) && (chosenInSetup ? payload.metrics?.includes(k) === true : !SPEND_DERIVED.has(k)))
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
      <div key={key} className="min-w-0 rounded-xl border border-border bg-surface px-3 py-2" data-testid={`exec-kpi-compact-${key}`}>
        <dt className="truncate text-[11px] text-text-muted">{metricLabel(meta, key, payload, ar)}</dt>
        <dd className="tnum truncate text-base font-extrabold text-text-primary" title={read.exact ?? undefined}><Num>{read.text}</Num></dd>
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
        /* REPORT-COMPACT-001 — Owner 2026-11-17: «بطاقات صغيرة جنب الكبيرة» — the rest are small cards, not a line of text. */
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))] gap-2" data-testid="exec-kpis-secondary">
          {[...keys.slice(4), ...fill].slice(0, 8).map(compact)}
        </dl>
      )}
    </div>
  )
}

/* ─────────────────────────────── Trend ─────────────────────────────── */

function TrendPanel({ payload, reader, ar, currency, source, wide }: { payload: LivePayload; reader: Reader; ar: boolean; currency: string; source: ResultSource; wide: boolean }) {
  const options = trendOptions(payload, reader, source, ar)
  const [on, setOn] = useState<string[]>(options.slice(0, 2).map((o) => o.key))
  const series = options.filter((o) => on.includes(o.key))
  const rightAxis = rightAxisFor(series.length > 1 ? on : [])

  return (
    <div className={wide ? 'lg:col-span-12' : 'lg:col-span-8'}>
      <ChartCard title={ar ? `${options[0].name} خلال الفترة` : `${options[0].name} over the period`}>
        <div className="mb-2 flex flex-wrap gap-1.5" data-testid="exec-trend-toggles">
          {options.map((o) => (
            <button
              key={o.key}
              type="button"
              aria-pressed={on.includes(o.key)}
              data-testid={`exec-trend-${o.key}`}
              onClick={() => setOn((cur) => toggleTrend(cur, o.key))}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold ${on.includes(o.key) ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: o.color }} />
              {o.name}
            </button>
          ))}
        </div>
        <div data-testid="exec-trend">
          <MetricLineChart data={payload.timeseries ?? []} currency={currency} height={230} rightAxisFor={rightAxis} series={series} />
        </div>
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Results split ─────────────────────────────── */

function ResultsSplit({ payload, ar, source, wide, selected, onSelect }: { payload: LivePayload; ar: boolean; source: ResultSource; wide: boolean; selected: string | null; onSelect: (p: string | null) => void }) {
  const { rows, total } = resultsSplit(payload, source, ar)
  const resultName = source.name

  return (
    <Panel title={splitTitle(source.key === 'clicks', resultName, ar)} testid="exec-results-split" className={wide ? 'lg:col-span-12' : 'lg:col-span-4'}>
      {total <= 0 ? (
        <p className="flex h-[200px] items-center justify-center text-center text-sm text-text-muted">
          {ar ? 'لم تُسجَّل نتائج موزّعة على المنصات في هذه الفترة' : 'No results by platform were recorded in this period'}
        </p>
      ) : (
        <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
          <PlatformDonutChart data={rows.map((r) => ({ name: r.name, value: r.value, key: r.key }))} height={170} legend={false} centerValue={num(total)} centerLabel={resultName} />
          <ul className="flex flex-col gap-1.5">
            {rows.map((r) => (
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
                  <span className="tnum font-bold text-text-primary"><Num>{`${Math.round(r.share * 100)}%`}</Num></span>
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
  const model = rankPlatforms(payload, source, ar, 'result', 'desc')
  const rows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  const extra = (['impressions', 'clicks'] as const).filter((k) => k !== result)
  const ctrText = (v: number | null) => (v === null ? '—' : `${(v * 100).toFixed(2)}%`)
  // REPORT-FILTERS-FUNCTIONAL-001 — no CTR column where the rows carry no CTR (not chosen): a column of dashes says a figure is withheld.
  const ctrShown = rows.length === 0 || rows.some((p) => 'ctr' in p)

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
          pageSize={8}
            head={[ar ? 'المنصة' : 'Platform', source.name, ...extra.map((k) => metricName(reader, k, ar, payload)), ...(ctrShown ? [ar ? 'نسبة النقر' : 'CTR'] : [])]}
            rows={model.map((m) => {
              const key = m.key

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
                  {m.name}
                </button>,
                <span key="r" className="inline-flex items-center gap-2">
                  <span className="h-1.5 w-14 overflow-hidden rounded-full bg-surface-secondary">
                    <span className="block h-full rounded-full" style={{ width: `${Math.round(m.bar * 100)}%`, background: platformColor(key) }} />
                  </span>
                  <span className="font-semibold text-text-primary"><Num>{num(m.result)}</Num></span>
                  {m.movement !== null && <TrendPill delta={m.movement} calm />}
                </span>,
                ...extra.map((k) => <span key={k} className="text-text-primary"><Num>{num(m[k])}</Num></span>),
                ...(ctrShown ? [<span key="ctr" className="text-text-secondary"><Num>{ctrText(m.ctr)}</Num></span>] : []),
              ]
            })}
            values={model.map((m): SortValues => [
              m.name,
              m.result,
              ...extra.map((k) => m[k]),
              ...(ctrShown ? [m.ctr] : []),
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
/** NASEL 2026-10-29 — when the funnel's steps are counted from different platforms, say so: 127 starts from three and 43 visits from one is not a funnel. */
function coverageNote(coverage: Record<string, string[]> | undefined, ar: boolean): string | null {
  if (!coverage) return null
  const steps: Array<[string, string, string]> = [['page_views', 'زيارات الصفحة', 'Page visits'], ['form_starts', 'بدء التعبئة', 'Form starts'], ['completions', 'الإتمامات', 'Completions']]
  const present = steps.filter(([key]) => (coverage[key] ?? []).length > 0)
  const sets = new Set(present.map(([key]) => [...(coverage[key] ?? [])].sort().join(',')))
  if (sets.size <= 1) return null
  const names = (key: string) => (coverage[key] ?? []).map((p) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')).join(ar ? '، ' : ', ')

  return (ar ? 'تختلف تغطية الخطوات بين المنصات: ' : 'Steps are counted from different platforms: ') + present.map(([key, a, e]) => `${ar ? a : e} — ${names(key)}`).join(ar ? ' · ' : ' · ')
}

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
          // The cost of the interest above (native + landing), named after it — not «تكلفة النتيجة», which belongs to the link's Results.
    ['cost', ar ? 'تكلفة الاهتمام' : 'Cost per interest', moneyOrNa(leads.cost)],
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
      {coverageNote(funnel?.coverage, ar) && <p className="text-[11px] text-warning" data-testid="exec-lead-coverage">{coverageNote(funnel?.coverage, ar)}</p>}
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
  const stages = funnelStages(payload)

  return (
    /*
     * REPORT-COMPOSITION-CONTROL-001 — alone on its row the funnel spans the grid, aligned like the
     * dashboard's «قمع الأداء»; it used to keep a seven-column width with nothing beside it.
     */
    <div className={narrow ? 'lg:col-span-4' : wide ? 'lg:col-span-12' : 'lg:col-span-7'} data-testid="exec-funnel" data-wide={wide ? 'true' : undefined}>
      <ChartCard title={ar ? 'قمع المنصات: من الظهور إلى النتيجة' : 'Platforms funnel: impression to result'}>
        <ConversionFunnelChart stages={stages} ar={ar} />
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Content ─────────────────────────────── */

function ContentBlock({ kind, half = false, payload, locale, currency, platform, onClear, onOpen }: {
  kind: 'best' | 'improve'; half?: boolean; payload: LivePayload; locale: Locale; currency: string; platform: string | null; onClear: () => void; onOpen: (c: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const items = useMemo(() => contentLists(payload, kind, platform), [payload, kind, platform])
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
            <ContentTile key={ad.content_key ?? `${ad.provider}-${i}`} content={ad} locale={locale} currency={currency || null} onOpen={onOpen} rank={kind === 'best' ? i + 1 : undefined} resultsOnly rankedBy={kind === 'best' ? payload.ads_basis : undefined} />
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

/**
 * REPORT-WEBSITE-TAB-001 — the measurement sources as ONE compact row of status chips, not a half-empty card:
 * each ad platform is ok, delayed or not included, and the last day with data closes the row. The website is not an ad
 * platform and is never listed here; it is named in its own tab only.
 */
function CoverageStrip({ payload, ar, wide }: { payload: LivePayload; ar: boolean; wide: boolean }) {
  const sources = (payload.freshness ?? []).filter((f) => (f.state === 'awaiting_credentials' || f.state === 'synced') && isAdPlatform(f.provider))
  const stateOf = (f: (typeof sources)[number]): 'ok' | 'delayed' | 'none' =>
    f.state === 'awaiting_credentials' ? 'none' : f.data_status === 'delayed' || f.data_status === 'stale' ? 'delayed' : 'ok'

  return (
    <div className={`min-w-0 ${wide ? 'lg:col-span-12' : 'lg:col-span-5'}`} data-testid="exec-health">
      <div data-testid="exec-health-sources" role="group" aria-label={ar ? 'مصادر القياس' : 'Measurement sources'} className="flex min-w-0 flex-wrap items-center gap-1.5 rounded-2xl border border-border bg-surface px-3 py-2">
        {sources.map((f) => {
          const state = stateOf(f)

          return (
            <span
              key={`${state}-${f.provider}-${f.name ?? ''}`}
              data-testid={state === 'none' ? `exec-source-missing-${f.provider}` : `exec-source-${state}-${f.provider}`}
              data-state={state}
              className={`inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] ${state === 'ok' ? 'text-text-secondary' : state === 'delayed' ? 'text-warning' : 'text-text-muted'}`}
            >
              {state === 'ok' ? <CheckCircle2 size={11} className="text-success" aria-hidden /> : state === 'delayed' ? <Clock size={11} aria-hidden /> : <CircleDashed size={11} aria-hidden />}
              {sourceName(f.provider, ar)}
              {state === 'delayed' && <span>{ar ? 'متأخرة' : 'delayed'}</span>}
              {state === 'none' && <span>{ar ? 'غير مشمولة' : 'not included'}</span>}
            </span>
          )
        })}
        <span className="ms-auto text-[11px] text-text-muted" data-testid="exec-data-through">{ar ? 'آخر يوم ببيانات' : 'Last day with data'} <Num>{lastDataDay(payload)}</Num></span>
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
