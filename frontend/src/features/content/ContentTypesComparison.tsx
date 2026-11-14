import { useMemo, useState, type ReactNode } from 'react'
import { Badge } from '@/components/ui/Badge'
import { StatCard } from '@/components/ui/StatCard'
import { FixedPageTable, type FixedColumn } from '@/components/ui/FixedPageTable'
import { Num } from '@/components/ui/Num'
import { ChartCard, MetricLineChart, PlatformDonutChart, RankingBarChart } from '@/features/analytics/charts'
import { money, num, percent, ratio } from '@/features/analytics/format'
import { creativeKindLabel } from './creativeKind'
import type { FormatComparisonCreative, FormatComparisonObjective, FormatComparisonPayload, FormatComparisonRow } from './api'

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى», drawn the same way in Analytics, the live link, the saved
 * report and its PDF.
 *
 * It draws; it never decides. Every figure, every share, the verdict and the small-sample flag arrive from the server
 * (`ContentFormatComparison`), and a client's copy arrives with its money already nulled — so nothing here can show a
 * client spend, and nothing here can compute a second answer. A null is drawn as «—», never as a zero bar or slice.
 *
 * Words: a kind is named through `creativeKindLabel` (the one vocabulary: صورة، فيديو، كاروسيل، كولكشن، كتالوج،
 * غير مصنّف) and the unclassified slice lists the platform's own tokens beside it, labelled as the platform's.
 */

export type ShareBasis = 'spend' | 'results'
export type BarMetric = 'cost_per_result' | 'ctr' | 'cpm' | 'cpc' | 'conversion_rate' | 'roas' | 'results'

const METRIC_WORDS: Record<BarMetric, { ar: string; en: string }> = {
  cost_per_result: { ar: 'تكلفة النتيجة', en: 'Cost/result' },
  ctr: { ar: 'نسبة النقر', en: 'CTR' },
  cpm: { ar: 'CPM', en: 'CPM' },
  cpc: { ar: 'CPC', en: 'CPC' },
  conversion_rate: { ar: 'التحويل', en: 'CVR' },
  roas: { ar: 'ROAS', en: 'ROAS' },
  results: { ar: 'النتائج', en: 'Results' },
}

const MONEY_METRICS: BarMetric[] = ['cost_per_result', 'cpm', 'cpc', 'roas']

/** The table columns a client's copy never draws — its values are null anyway; the column would be a row of dashes. */
const MONEY_COLUMNS = new Set(['spend', 'cost_per_result', 'share_of_spend'])

const REFUSAL: Record<string, { ar: string; en: string }> = {
  fewer_than_two_comparable_formats: { ar: 'لا مقارنة كافية', en: 'Not enough to compare' },
  tie: { ar: 'تعادل', en: 'Tie' },
  no_result_metric_for_objective: { ar: 'لا نتيجة لهذا الهدف', en: 'No result for this goal' },
}

export const RESULT_WORDS: Record<string, { ar: string; en: string }> = {
  conversions: { ar: 'تحويلات', en: 'Conversions' },
  leads: { ar: 'عملاء محتملون', en: 'Leads' },
  installs: { ar: 'تثبيتات', en: 'Installs' },
  clicks: { ar: 'نقرات', en: 'Clicks' },
  impressions: { ar: 'ظهور', en: 'Impressions' },
  engagements: { ar: 'تفاعلات', en: 'Engagements' },
  video_views: { ar: 'مشاهدات', en: 'Views' },
}

/** The series colours, in the chart layer's own palette, by position of the format in the objective. */
const SERIES = ['var(--brand-600)', 'var(--info)', 'var(--purple)', 'var(--teal)', 'var(--warning)', 'var(--text-muted)']

export function kindName(format: string, ar: boolean): string {
  return creativeKindLabel(format, ar)
}

/** A figure in its own unit; null is «—». */
export function metricText(metric: BarMetric | 'spend' | 'share', value: number | null | undefined, currency: string | null): string {
  if (value === null || value === undefined) return '—'
  switch (metric) {
    case 'cost_per_result': case 'cpm': case 'cpc': case 'spend': return money(value, currency)
    case 'ctr': case 'conversion_rate': case 'share': return percent(value, 1)
    case 'roas': return ratio(value)
    default: return num(value)
  }
}

/** The bar metrics a reader of this copy can be offered: a client has no money metric, and an unanswered one is not offered. */
export function barMetrics(objective: FormatComparisonObjective, client: boolean): BarMetric[] {
  const all: BarMetric[] = client ? ['results', 'ctr', 'conversion_rate'] : ['cost_per_result', 'results', 'ctr', 'cpm', 'cpc', 'conversion_rate', 'roas']

  return all.filter((m) => !(client && MONEY_METRICS.includes(m)) && objective.formats.some((f) => typeof f[m] === 'number'))
}

/**
 * The server's verdict first — the ranking never puts a small sample, or (for a client, who has no cost to sort on) a
 * format with more but dearer results, above the one its card calls the best. Then by cost per result (smaller is
 * better), then by results; a format without the figure goes last, and the unclassified slice after everything.
 */
export function rankedFormats(objective: FormatComparisonObjective): FormatComparisonRow[] {
  const winner = objective.winner?.format ?? null
  const key = (f: FormatComparisonRow): [number, number, number] => [
    f.format === winner ? 0 : f.format === 'unclassified' ? 2 : 1,
    typeof f.cost_per_result === 'number' ? f.cost_per_result : Number.POSITIVE_INFINITY,
    -(f.results ?? -1),
  ]

  return [...objective.formats].sort((a, b) => {
    const [a1, a2, a3] = key(a)
    const [b1, b2, b3] = key(b)
    return a1 - b1 || a2 - b2 || a3 - b3
  })
}

function change(current: number | null | undefined, previous: number | null | undefined): number | null {
  if (typeof current !== 'number' || typeof previous !== 'number' || previous === 0) return null
  return (current - previous) / previous
}

function Chips<T extends string>({ options, value, onChange, label, testId, words }: { options: T[]; value: T; onChange: (v: T) => void; label: string; testId: string; words: (v: T) => string }) {
  if (options.length < 2) return null

  return (
    <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1" data-testid={testId}>
      {options.map((o) => (
        <button
          key={o}
          type="button"
          role="radio"
          aria-checked={o === value}
          data-testid={`${testId}-${o}`}
          onClick={() => onChange(o)}
          className={`rounded-full px-3 py-1 text-xs font-bold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 ${o === value ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'}`}
        >
          {words(o)}
        </button>
      ))}
    </div>
  )
}

export function FormatVerdictChip({ objective, ar, client }: { objective: FormatComparisonObjective; ar: boolean; client: boolean }) {
  if (objective.winner) {
    return (
      <Badge tone="success" data-testid="format-winner">
        {kindName(objective.winner.format, ar)} · {client ? (ar ? 'الأنسب للهدف' : 'Best fit') : (ar ? 'الأقل تكلفة للنتيجة' : 'Lowest cost/result')}
      </Badge>
    )
  }
  const why = REFUSAL[objective.winner_refusal ?? ''] ?? REFUSAL.fewer_than_two_comparable_formats

  return <Badge tone="neutral" data-testid="format-no-winner">{ar ? why.ar : why.en}</Badge>
}

function SmallSample({ ar }: { ar: boolean }) {
  return <Badge tone="warning" data-testid="format-small-sample">{ar ? 'عينة صغيرة' : 'Small sample'}</Badge>
}

function formatColumns(ar: boolean, client: boolean, currency: string | null, onDrill?: (format: string) => void): FixedColumn<FormatComparisonRow>[] {
  const cols: FixedColumn<FormatComparisonRow>[] = [
    {
      key: 'format', header: ar ? 'النوع' : 'Type', width: client ? '30%' : '20%', value: (r) => kindName(r.format, ar),
      render: (r) => (
        <span className="inline-flex min-w-0 items-center gap-1.5">
          {onDrill
            ? <button type="button" className="truncate font-semibold text-brand-600 underline-offset-2 hover:underline" onClick={() => onDrill(r.format)} data-testid={`format-open-${r.format}`}>{kindName(r.format, ar)}</button>
            : <span className="truncate font-semibold">{kindName(r.format, ar)}</span>}
          {r.small_sample && <span className="text-xs text-warning" aria-label={ar ? 'عينة صغيرة' : 'Small sample'}>●</span>}
        </span>
      ),
    },
    { key: 'creatives', header: ar ? 'المحتوى' : 'Ads', width: '10%', align: 'end', value: (r) => r.creatives, render: (r) => <Num>{num(r.creatives)}</Num> },
    { key: 'spend', header: ar ? 'الإنفاق' : 'Spend', width: '14%', align: 'end', value: (r) => r.spend, render: (r) => <Num>{r.spend_withheld ? `${money(r.spend_original, r.original_currency)}*` : metricText('spend', r.spend, currency)}</Num> },
    { key: 'results', header: ar ? 'النتائج' : 'Results', width: client ? '18%' : '12%', align: 'end', value: (r) => r.results, render: (r) => <Num>{metricText('results', r.results, currency)}</Num> },
    { key: 'cost_per_result', header: ar ? 'التكلفة' : 'Cost', width: '13%', align: 'end', value: (r) => r.cost_per_result, render: (r) => <Num>{metricText('cost_per_result', r.cost_per_result, currency)}</Num> },
    { key: 'ctr', header: ar ? 'النقر' : 'CTR', width: client ? '14%' : '10%', align: 'end', value: (r) => r.ctr, render: (r) => <Num>{metricText('ctr', r.ctr, currency)}</Num> },
    { key: 'share_of_spend', header: ar ? '٪ إنفاق' : 'Spend %', width: '10%', align: 'end', value: (r) => r.share_of_spend, render: (r) => <Num>{metricText('share', r.share_of_spend, currency)}</Num> },
    { key: 'share_of_results', header: ar ? '٪ نتائج' : 'Results %', width: client ? '14%' : '11%', align: 'end', value: (r) => r.share_of_results, render: (r) => <Num>{metricText('share', r.share_of_results, currency)}</Num> },
  ]
  return cols.filter((c) => !(client && MONEY_COLUMNS.has(c.key)))
}

function creativeColumns(ar: boolean, client: boolean, currency: string | null): FixedColumn<FormatComparisonCreative>[] {
  const cols: FixedColumn<FormatComparisonCreative>[] = [
    { key: 'name', header: ar ? 'المحتوى' : 'Content', width: client ? '46%' : '34%', value: (r) => r.name, title: (r) => r.name, render: (r) => <span dir="auto" className="truncate font-semibold">{r.name}</span> },
    { key: 'spend', header: ar ? 'الإنفاق' : 'Spend', width: '16%', align: 'end', value: (r) => r.spend, render: (r) => <Num>{metricText('spend', r.spend, currency)}</Num> },
    { key: 'results', header: ar ? 'النتائج' : 'Results', width: client ? '18%' : '16%', align: 'end', value: (r) => r.results, render: (r) => <Num>{r.results_not_attributable ? (ar ? 'غير منسوبة' : 'Not attributed') : metricText('results', r.results, currency)}</Num> },
    { key: 'cost_per_result', header: ar ? 'التكلفة' : 'Cost', width: '18%', align: 'end', value: (r) => r.cost_per_result, render: (r) => <Num>{metricText('cost_per_result', r.cost_per_result, currency)}</Num> },
    { key: 'ctr', header: ar ? 'النقر' : 'CTR', width: client ? '36%' : '16%', align: 'end', value: (r) => r.ctr, render: (r) => <Num>{metricText('ctr', r.ctr, currency)}</Num> },
  ]

  return cols.filter((c) => !(client && MONEY_COLUMNS.has(c.key)))
}

/** The format's creatives as a fixed-size paged table — the report drill, and Analytics' fallback. */
export function FormatCreativeTable({ row, ar, client, currency, pageSize = 6, range }: { row: FormatComparisonRow; ar: boolean; client: boolean; currency: string | null; pageSize?: number; range?: [number, number] }) {
  return (
    <FixedPageTable
      testId="format-creatives-table"
      ar={ar}
      pageSize={pageSize}
      pager={range === undefined}
      rows={range ? row.creative_list.slice(range[0], range[1]) : row.creative_list}
      rowKey={(r) => r.id}
      columns={creativeColumns(ar, client, currency)}
    />
  )
}

/**
 * One objective's comparison: share donut, metric bars, ranked list with the verdict, trend, table, drill.
 */
export function ObjectiveFormats({
  objective, ar, client, currency, renderDrill, pageSize = 6, paged = false, part = 'all',
}: {
  objective: FormatComparisonObjective
  ar: boolean
  client: boolean
  currency: string | null
  renderDrill?: (row: FormatComparisonRow, objective: FormatComparisonObjective) => ReactNode
  pageSize?: number
  /** A printed page: no switches, no drill, shorter charts. */
  paged?: boolean
  /** Which half a printed page draws. */
  part?: 'all' | 'charts' | 'table'
}) {
  const shareOptions: ShareBasis[] = client || objective.formats.every((f) => f.share_of_spend === null) ? ['results'] : ['spend', 'results']
  const [share, setShare] = useState<ShareBasis>(shareOptions[0])
  const metrics = barMetrics(objective, client)
  const [chosen, setChosen] = useState<BarMetric | null>(null)
  const metric = chosen !== null && metrics.includes(chosen) ? chosen : metrics[0] ?? null
  const [drill, setDrill] = useState<string | null>(null)
  const ranked = useMemo(() => rankedFormats(objective), [objective])
  const colour = (format: string) => SERIES[Math.max(0, objective.formats.findIndex((f) => f.format === format)) % SERIES.length]
  const resultWord = RESULT_WORDS[objective.result_metric ?? ''] ?? { ar: 'النتائج', en: 'Results' }

  const slices = objective.formats
    .map((f) => ({ name: kindName(f.format, ar), key: f.format, value: share === 'spend' ? f.share_of_spend : f.share_of_results, raw: share === 'spend' ? f.spend : f.results }))
    .filter((s): s is { name: string; key: string; value: number; raw: number | null } => typeof s.value === 'number' && s.value > 0)
  const bars = metric === null ? [] : objective.formats
    .filter((f) => typeof f[metric] === 'number')
    .map((f) => ({ label: kindName(f.format, ar), value: f[metric] as number }))
  const trendKey = client ? 'results' : 'cost_per_result'
  const trend = (objective.trend?.points ?? []).map((p) => {
    const point: Record<string, unknown> = { date: p.from }
    for (const f of objective.formats) point[f.format] = p.formats[f.format]?.[trendKey] ?? null
    return point
  })
  const trendHasData = trend.some((p) => objective.formats.some((f) => typeof p[f.format] === 'number'))
  const open = drill === null ? null : objective.formats.find((f) => f.format === drill) ?? null
  const chartHeight = paged ? 190 : 240

  const charts = (
    <div className="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2">
      <ChartCard
        title={ar ? 'الحصة' : 'Share'}
        action={paged ? <Badge tone="neutral">{share === 'spend' ? (ar ? 'الإنفاق' : 'Spend') : (ar ? 'النتائج' : 'Results')}</Badge> : <Chips options={shareOptions} value={share} onChange={setShare} label={ar ? 'أساس الحصة' : 'Share basis'} testId="format-share" words={(v) => (v === 'spend' ? (ar ? 'الإنفاق' : 'Spend') : (ar ? 'النتائج' : 'Results'))} />}
      >
        <div data-testid="format-donut" data-basis={share} data-slices={slices.map((s) => `${s.key}:${s.value.toFixed(4)}`).join(',')}>
          {slices.length === 0
            ? <p className="py-10 text-center text-sm text-text-muted">{ar ? 'لا حصة معلنة' : 'No share reported'}</p>
            : <PlatformDonutChart data={slices.map((s) => ({ name: s.name, value: s.value, key: s.key }))} colorBy="series" valueKind="percent" height={chartHeight} centerLabel={share === 'spend' ? (ar ? 'الإنفاق' : 'Spend') : (ar ? resultWord.ar : resultWord.en)} />}
        </div>
      </ChartCard>
      <ChartCard
        title={ar ? 'المقارنة' : 'Compare'}
        action={paged || metric === null ? (metric ? <Badge tone="neutral">{ar ? METRIC_WORDS[metric].ar : METRIC_WORDS[metric].en}</Badge> : undefined) : <Chips options={metrics} value={metric} onChange={setChosen} label={ar ? 'المؤشر' : 'Metric'} testId="format-metric" words={(v) => (ar ? METRIC_WORDS[v].ar : METRIC_WORDS[v].en)} />}
      >
        <div data-testid="format-bars" data-metric={metric ?? ''} data-values={bars.map((b) => `${b.label}:${b.value}`).join(',')}>
          {bars.length === 0
            ? <p className="py-10 text-center text-sm text-text-muted">{ar ? 'غير متاح من المنصة' : 'Not reported by the platform'}</p>
            : <RankingBarChart data={bars} bars={[{ key: 'value', name: metric ? (ar ? METRIC_WORDS[metric].ar : METRIC_WORDS[metric].en) : '', kind: metric === 'ctr' || metric === 'conversion_rate' ? 'percent' : metric === 'roas' ? 'ratio' : metric === 'results' ? 'num' : 'money' }]} currency={currency ?? ''} height={chartHeight} />}
        </div>
      </ChartCard>
    </div>
  )

  const list = (
    <ChartCard title={ar ? 'الترتيب' : 'Ranking'} action={<FormatVerdictChip objective={objective} ar={ar} client={client} />}>
      <ol className="flex flex-col gap-2" data-testid="format-ranked">
        {ranked.slice(0, paged ? 6 : ranked.length).map((f, i) => {
          const isWinner = objective.winner?.format === f.format
          const delta = change(client ? f.results : f.cost_per_result, client ? f.previous?.results : f.previous?.cost_per_result)

          return (
            <li key={f.format} data-testid={`format-row-${f.format}`} className={`flex min-w-0 flex-wrap items-center gap-2 rounded-xl border px-3 py-2 ${isWinner ? 'border-success/50 bg-success/5' : 'border-border'}`}>
              <span className="tnum w-5 shrink-0 text-sm font-extrabold text-text-muted"><Num>{i + 1}</Num></span>
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: colour(f.format) }} aria-hidden />
              <span className="min-w-0 truncate text-sm font-bold text-text-primary">{kindName(f.format, ar)}</span>
              {f.small_sample && <SmallSample ar={ar} />}
              {f.spend_withheld && !client && <Badge tone="neutral">{ar ? 'عملة غير محوّلة' : 'Unconverted'}</Badge>}
              {f.results_partial && <Badge tone="neutral">{ar ? 'نتائج جزئية' : 'Partial results'}</Badge>}
              {f.format === 'unclassified' && f.tokens.length > 0 && (
                <span className="truncate text-xs text-text-muted" data-testid="format-tokens">
                  {(ar ? 'رمز المنصة: ' : 'Platform token: ') + f.tokens.map((t) => (t.token === '' ? '—' : t.token)).join('، ')}
                </span>
              )}
              <span className="ms-auto flex items-center gap-3 text-sm">
                <span className="text-text-secondary"><Num>{metricText('results', f.results, currency)}</Num> {ar ? resultWord.ar : resultWord.en}</span>
                {!client && <span className="font-bold text-text-primary"><Num>{metricText('cost_per_result', f.cost_per_result, currency)}</Num></span>}
                {delta !== null && <span className={`text-xs ${(client ? delta > 0 : delta < 0) ? 'text-success' : 'text-danger'}`}><Num>{`${delta > 0 ? '↑' : '↓'}${percent(Math.abs(delta), 0)}`}</Num></span>}
                {!paged && (
                  <button type="button" aria-expanded={drill === f.format} data-testid={`format-drill-${f.format}`} onClick={() => setDrill(drill === f.format ? null : f.format)} className="rounded-lg border border-border px-2 py-0.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
                    {ar ? 'المحتوى' : 'Ads'}
                  </button>
                )}
              </span>
            </li>
          )
        })}
      </ol>
    </ChartCard>
  )

  const table = (
    <ChartCard title={ar ? 'التفاصيل' : 'Details'}>
      <FixedPageTable testId="format-table" ar={ar} pageSize={pageSize} pager={!paged} rows={ranked} rowKey={(r) => r.format} columns={formatColumns(ar, client, currency, paged ? undefined : (f) => setDrill(f))} />
    </ChartCard>
  )

  /*
    The Owner's layout (2026-11-07, permanent): big cards per format with the headline result and the cost of a result,
    small sub-cards under each, then the interactive charts and the comparison, and only a short paged table of the
    top creatives at the end — no long tables. The full format table is drawn on a printed table page only.
  */
  const cards = (
    <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="format-cards">
      {ranked.map((f) => {
        const isWinner = objective.winner?.format === f.format
        const subs: Array<{ key: string; label: string; value: string }> = [
          { key: 'ctr', label: ar ? 'النقر' : 'CTR', value: metricText('ctr', f.ctr, currency) },
          { key: 'conversion_rate', label: ar ? 'التحويل' : 'CVR', value: metricText('conversion_rate', f.conversion_rate, currency) },
          { key: 'creatives', label: ar ? 'المحتوى' : 'Ads', value: num(f.creatives) },
        ]

        /*
          The DOM order is a reader's order too (a screen reader, a copied block, the page text): every RATE comes before
          the cost figures, so a percentage never trails a cost label — «CPM 450 SAR التحويل 12%» reads as the cost of a
          conversion rate. The grid lines below put the cost back beside the results on screen.
        */
        return (
          <article key={f.format} data-testid={`format-card-${f.format}`} className={`grid min-w-0 grid-cols-1 content-start gap-3 rounded-2xl border bg-surface p-4 shadow-[var(--shadow-small)] sm:grid-cols-2 ${isWinner ? 'border-success/60' : 'border-border'}`}>
            <header className="col-span-full row-start-1 flex min-w-0 flex-wrap items-center gap-2">
              <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: colour(f.format) }} aria-hidden />
              <h3 className="truncate text-lg font-extrabold text-text-primary">{kindName(f.format, ar)}</h3>
              {isWinner && <Badge tone="success">{client ? (ar ? 'الأنسب' : 'Best fit') : (ar ? 'الأقل تكلفة' : 'Lowest cost')}</Badge>}
              {f.small_sample && <SmallSample ar={ar} />}
            </header>
            <div className="row-start-2 min-w-0 sm:col-start-1">
              <StatCard
                testid={`format-card-results-${f.format}`}
                label={ar ? resultWord.ar : resultWord.en}
                value={metricText('results', f.results, currency)}
                delta={objective.result_metric ? { metric: objective.result_metric, current: f.results, previous: f.previous?.results } : undefined}
              />
            </div>
            <dl className={`col-span-full ${client ? 'row-start-3' : 'row-start-4 sm:row-start-3'} grid grid-cols-3 gap-2`}>
              {subs.map((sub) => (
                <div key={sub.key} className="min-w-0 rounded-xl bg-surface-secondary px-3 py-2">
                  <dt className="truncate text-xs text-text-muted">{sub.label}</dt>
                  <dd className="tnum text-sm font-bold text-text-primary"><Num>{sub.value}</Num></dd>
                </div>
              ))}
            </dl>
            <div className={`col-span-full flex flex-col gap-1 ${client ? 'row-start-4' : 'row-start-5 sm:row-start-4'}`} data-testid={`format-card-shares-${f.format}`}>
              {!client && (
                <div className="flex items-center gap-2 text-xs text-text-secondary">
                  <span className="w-14 shrink-0">{ar ? 'الإنفاق' : 'Spend'}</span>
                  <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full bg-text-muted" style={{ width: `${Math.round((f.share_of_spend ?? 0) * 100)}%` }} /></span>
                  <Num>{metricText('share', f.share_of_spend, currency)}</Num>
                </div>
              )}
              <div className="flex items-center gap-2 text-xs text-text-secondary">
                <span className="w-14 shrink-0">{ar ? 'النتائج' : 'Results'}</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full" style={{ width: `${Math.round((f.share_of_results ?? 0) * 100)}%`, background: colour(f.format) }} /></span>
                <Num>{metricText('share', f.share_of_results, currency)}</Num>
              </div>
            </div>
            {!client && (
              <div className="row-start-3 flex min-w-0 flex-col gap-2 sm:col-start-2 sm:row-start-2">
                <StatCard
                  testid={`format-card-cost-${f.format}`}
                  label={ar ? 'تكلفة النتيجة' : 'Cost/result'}
                  value={metricText('cost_per_result', f.cost_per_result, currency)}
                  delta={objective.cost_metric ? { metric: objective.cost_metric, current: f.cost_per_result, previous: f.previous?.cost_per_result } : undefined}
                />
                <dl className="rounded-xl bg-surface-secondary px-3 py-2">
                  <dt className="text-xs text-text-muted">CPM</dt>
                  <dd className="tnum text-sm font-bold text-text-primary"><Num>{metricText('cpm', f.cpm, currency)}</Num></dd>
                </dl>
              </div>
            )}
          </article>
        )
      })}
    </div>
  )

  const top = objective.formats
    .flatMap((f) => f.creative_list.map((c) => ({ ...c, kind: f.format })))
    .sort((a, b) => (b.results ?? -1) - (a.results ?? -1))
    .slice(0, 10)
  const topTable = top.length === 0 ? null : (
    <ChartCard title={ar ? 'أبرز المحتوى' : 'Top content'}>
      <FixedPageTable
        testId="format-top-creatives"
        ar={ar}
        pageSize={5}
        rows={top}
        rowKey={(r) => r.id}
        columns={[
          { key: 'name', header: ar ? 'المحتوى' : 'Content', width: client ? '44%' : '36%', value: (r) => r.name, title: (r) => r.name, render: (r) => <span dir="auto" className="truncate font-semibold">{r.name}</span> },
          { key: 'kind', header: ar ? 'النوع' : 'Type', width: '18%', value: (r) => kindName(r.kind, ar), render: (r) => kindName(r.kind, ar) },
          { key: 'results', header: ar ? 'النتائج' : 'Results', width: '16%', align: 'end', value: (r) => r.results, render: (r) => <Num>{r.results_not_attributable ? '—' : metricText('results', r.results, currency)}</Num> },
          ...(client ? [] : [{ key: 'cost', header: ar ? 'التكلفة' : 'Cost', width: '14%', align: 'end' as const, value: (r: (typeof top)[number]) => r.cost_per_result, render: (r: (typeof top)[number]) => <Num>{metricText('cost_per_result', r.cost_per_result, currency)}</Num> }]),
          { key: 'ctr', header: ar ? 'النقر' : 'CTR', width: client ? '22%' : '16%', align: 'end', value: (r) => r.ctr, render: (r) => <Num>{metricText('ctr', r.ctr, currency)}</Num> },
        ]}
      />
    </ChartCard>
  )

  if (part === 'charts') return <div className="flex min-w-0 flex-col gap-3">{charts}{list}</div>
  if (part === 'table') return table

  return (
    <div className="flex min-w-0 flex-col gap-4" data-testid={`format-objective-${objective.family}`}>
      {cards}
      {charts}
      {trendHasData && !paged && (
        <ChartCard title={ar ? 'الاتجاه' : 'Trend'} subtitle={client ? (ar ? resultWord.ar : resultWord.en) : (ar ? 'تكلفة النتيجة' : 'Cost per result')}>
          <div data-testid="format-trend">
            <MetricLineChart data={trend} series={objective.formats.map((f) => ({ key: f.format, name: kindName(f.format, ar), color: colour(f.format), kind: client ? 'num' : 'money' }))} currency={currency ?? ''} height={200} />
          </div>
        </ChartCard>
      )}
      {list}
      {open && (
        <ChartCard title={`${kindName(open.format, ar)} · ${ar ? 'المحتوى' : 'Ads'}`} action={<button type="button" onClick={() => setDrill(null)} className="rounded-lg px-2 py-0.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover" data-testid="format-drill-close">{ar ? 'إغلاق' : 'Close'}</button>}>
          <div data-testid="format-drill" data-format={open.format}>
            {renderDrill ? renderDrill(open, objective) : <FormatCreativeTable row={open} ar={ar} client={client} currency={currency} />}
          </div>
        </ChartCard>
      )}
      {topTable}
    </div>
  )
}

/** The coverage chip: how much of the money (or the results) the platform named a format for. */
export function CoverageChip({ objective, ar, client }: { objective: FormatComparisonObjective; ar: boolean; client: boolean }) {
  const spend = client ? null : objective.coverage.spend_known_share
  const results = objective.coverage.results_known_share
  const parts = [
    spend !== null ? `${percent(spend, 0)} ${ar ? 'من الإنفاق' : 'of spend'}` : null,
    results !== null ? `${percent(results, 0)} ${ar ? 'من النتائج' : 'of results'}` : null,
  ].filter(Boolean)

  return (
    <Badge tone={parts.length === 0 ? 'neutral' : 'info'} data-testid="format-coverage">
      <Num>{`${ar ? 'النوع معروف: ' : 'Type known: '}${parts.length === 0 ? '—' : parts.join(' · ')}`}</Num>
    </Badge>
  )
}

/**
 * The whole comparison: an objective selector when the scope mixes objectives (results are never compared across
 * them), the coverage chip, and the objective's comparison.
 */
export function ContentTypesComparison({
  data, ar, client = false, renderDrill, pageSize = 6, title = true,
}: {
  data: FormatComparisonPayload
  ar: boolean
  client?: boolean
  renderDrill?: (row: FormatComparisonRow, objective: FormatComparisonObjective) => ReactNode
  pageSize?: number
  title?: boolean
}) {
  const [index, setIndex] = useState(0)
  // A payload that is not this shape (an older or foreign answer) draws the empty state, never a crash.
  const objectives = Array.isArray(data?.objectives) ? data.objectives : []
  const objective = objectives[Math.min(index, objectives.length - 1)]
  const hidesMoney = client || data.spend_hidden === true

  if (objective === undefined) {
    return <p className="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-text-secondary" data-testid="format-comparison-empty">{ar ? 'لا محتوى في هذه الفترة' : 'No content in this period'}</p>
  }

  return (
    <section data-testid="format-comparison" className="flex min-w-0 flex-col gap-4">
      <header className="flex flex-wrap items-center gap-2">
        {title && <h2 className="text-lg font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h2>}
        <CoverageChip objective={objective} ar={ar} client={hidesMoney} />
        <Badge tone="neutral"><Num>{`${num(objective.totals.creatives)} ${ar ? 'محتوى' : 'ads'}`}</Num></Badge>
      </header>
      {objectives.length > 1 && (
        <div role="tablist" aria-label={ar ? 'الهدف' : 'Goal'} className="flex flex-wrap gap-1.5" data-testid="format-objectives">
          {objectives.map((o, i) => (
            <button
              key={o.family}
              type="button"
              role="tab"
              aria-selected={o === objective}
              data-testid={`format-objective-tab-${o.family}`}
              onClick={() => setIndex(i)}
              className={`rounded-xl border px-3 py-1.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 ${o === objective ? 'border-transparent bg-brand-600 text-white' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              {ar ? o.label.ar : o.label.en}
            </button>
          ))}
        </div>
      )}
      <ObjectiveFormats key={objective.family} objective={objective} ar={ar} client={hidesMoney} currency={hidesMoney ? null : data.currency} renderDrill={renderDrill} pageSize={pageSize} />
    </section>
  )
}
