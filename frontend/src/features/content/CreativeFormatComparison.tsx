import { useQuery } from '@tanstack/react-query'
import { Award, Minus, TrendingDown, TrendingUp } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { StatCard } from '@/components/ui/StatCard'
import { DataMetricTable } from '@/components/ui/MetricTable'
import { ChartCard, MetricLineChart, PlatformDonutChart, RankingBarChart } from '@/features/analytics/charts'
import { formatIntelligence, type FormatIntelligencePayload } from './api'
import { formatWord, leadObjective, verdictFor, type Evidence } from './formatVerdict'
import { metricLabel } from './metrics'
import { money } from '@/features/analytics/format'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — «image or video, here?», drawn once and reused.
 *
 * One module, three depths. The figures are identical at every depth because they are the same
 * server answer: only how much of it is drawn changes, which is what stops a dashboard, a campaign
 * page and a client's report quietly disagreeing about one advertiser.
 *
 *   compact  the verdict, the two figures behind it and the spend split — a dashboard or an
 *            executive summary, where this is one answer among many.
 *   medium   the scoreboard, the comparison chart and the spend mix — an account or a campaign page.
 *   full     everything, with the trend and the exact table — Content Analytics, detailed reports.
 *
 * ## Charts for understanding, a table for verification
 *
 * A reader deciding what to commission next holds two columns in their head, not a spreadsheet. So
 * the answer is a scoreboard, then a comparison a glance can read, then where the money actually is,
 * then whether the advantage is strengthening. The exact table stays underneath — it is what makes
 * the rest trustworthy — rather than in front.
 *
 * Every chart comes from the product's ONE chart layer. A module that drew its own would be a second
 * visual language for the same figures, which is the reason that layer exists.
 *
 * ## It never crowns a format on thin evidence
 *
 * The refusal is the behaviour being generalised, not one being replaced: with one asset in a format,
 * or no metric both formats reported, the module says so and shows the coverage that explains why.
 */
export function CreativeFormatComparison({ projectId, from, to, accountId, campaignId, depth = 'full', ar }: {
  projectId: string
  from?: string
  to?: string
  /** The ad account axis. Null is the project rollup, which names the accounts it blends. */
  accountId?: string | null
  campaignId?: string | null
  depth?: 'compact' | 'medium' | 'full'
  ar: boolean
}) {
  const answer = useQuery({
    queryKey: ['format-intelligence', projectId, from, to, accountId, campaignId],
    queryFn: () => formatIntelligence(projectId, { from, to, external_account_id: accountId, campaign_id: campaignId }),
  })

  if (answer.isLoading) return <Skeleton className="h-28 w-full rounded-xl" />
  if (answer.data === undefined) return null

  return <FormatComparisonView payload={answer.data} depth={depth} ar={ar} />
}

/**
 * The same module, drawn from a payload that is already in hand.
 *
 * A client report carries its answer in the report payload — the reader has no session and no
 * project endpoint to call — so the fetching and the drawing are separated rather than duplicated.
 * One component renders both, which is what makes «the same numbers everywhere» true by
 * construction rather than by discipline.
 */
export function FormatComparisonView({ payload, depth = 'full', ar }: {
  payload: FormatIntelligencePayload
  depth?: 'compact' | 'medium' | 'full'
  ar: boolean
}) {
  const lead = leadObjective(payload)

  if (lead === null) {
    return (
      <p className="text-xs text-text-muted" data-testid="format-comparison-empty">
        {ar ? 'لا توجد محتويات في هذا النطاق خلال هذه الفترة.' : 'No content ran in this scope during this period.'}
      </p>
    )
  }

  const shown = depth === 'full' ? payload.objectives : [lead]
  const accounts = Array.isArray(payload.accounts) ? payload.accounts : []

  return (
    <section className="grid min-w-0 gap-4" data-testid="creative-format-comparison" data-depth={depth}>
      {/*
        WHOSE answer this is. A project rollup that does not name its accounts invites the reader to
        take an agency-wide blend for one advertiser's truth.
      */}
      {accounts.length > 0 && depth !== 'compact' && (
        <p className="text-[11px] text-text-muted" data-testid="format-comparison-accounts">
          {ar ? 'الحسابات: ' : 'Accounts: '}
          {accounts.map((a) => a.name).join(' · ')}
        </p>
      )}

      {shown.map((objective) => (
        <ObjectiveBlock
          key={objective.family}
          objective={objective}
          coverage={payload.coverage}
          currency={payload.currency ?? null}
          depth={depth}
          ar={ar}
        />
      ))}

      {depth !== 'compact' && payload.spend_mix !== undefined && (
        <SpendMix mix={payload.spend_mix} currency={payload.currency ?? null} ar={ar} />
      )}

      {depth === 'full' && <FormatTrend trend={payload.trend} ar={ar} />}
    </section>
  )
}

type Depth = 'compact' | 'medium' | 'full'

function ObjectiveBlock({ objective, coverage, currency, depth, ar }: {
  objective: FormatIntelligencePayload['objectives'][number]
  coverage: FormatIntelligencePayload['coverage']
  currency: string | null
  depth: Depth
  ar: boolean
}) {
  const { comparison, evidence } = objective
  const verdict = verdictFor(comparison, evidence)
  const rows = Array.isArray(comparison.formats) ? comparison.formats : []
  const tooFew = Array.isArray(comparison.too_few_to_speak_for_their_format)
    ? comparison.too_few_to_speak_for_their_format
    : []
  const metric = comparison.metric
  const label = metric === null ? '' : metricLabel(metric, ar ? 'ar' : 'en')

  return (
    <div className="grid min-w-0 gap-3 rounded-2xl border border-border bg-surface p-4" data-testid={`format-objective-${objective.family}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm font-bold text-text-primary">{ar ? objective.label.ar : objective.label.en}</span>
        <EvidencePill evidence={evidence} ar={ar} />
      </div>

      {/*
        THE VERDICT, as a sentence a reader can repeat.

        It leads, because it is the thing somebody came for; everything beneath it is the working.
      */}
      <p
        className="flex items-start gap-2 text-sm font-bold leading-6 text-text-primary"
        data-testid={`format-verdict-${objective.family}`}
      >
        {verdict.kind === 'winner' && <Award size={16} className="mt-0.5 shrink-0 text-brand-600" aria-hidden />}
        <span>{ar ? verdict.ar : verdict.en}</span>
      </p>

      {/*
        THE SCOREBOARD — two columns, stacked on a phone.

        A desktop comparison table squeezed into 390px is unreadable, and this is what a reader on a
        phone most wants: what each format produced and what it cost.
      */}
      {rows.length > 0 && (
        <div className="grid min-w-0 gap-2 sm:grid-cols-2" data-testid={`format-scorecard-${objective.family}`}>
          {rows.map((row) => (
            <FormatCard
              key={row.format}
              row={row}
              label={label}
              winner={verdict.winner === row.format}
              cover={coverage[row.format]}
              currency={currency}
              ar={ar}
            />
          ))}
        </div>
      )}

      {/*
        THE COMPARISON, at a glance.

        The bars carry the one metric the verdict was decided on, so the picture and the sentence
        cannot say different things. Drawn only where there is something to compare — two bars of one
        value each is a decoration, not a chart.
      */}
      {depth !== 'compact' && rows.length > 1 && metric !== null && (
        <ChartCard title={ar ? `المقارنة على ${label}` : `Compared on ${label}`}>
          <div data-testid={`format-bars-${objective.family}`}>
            <RankingBarChart
              height={200}
              horizontal
              data={rows.map((row) => ({ label: formatWord(row.format, ar), value: row.value }))}
              bars={[{ key: 'value', name: label, kind: metricKind(metric) }]}
              currency={currency ?? undefined}
            />
          </div>
          {/*
            WHICH DIRECTION IS GOOD, said rather than assumed.

            On a cost per result the LONGEST bar is the worst one, and a reader scanning a chart takes
            the biggest bar for the winner — the one misreading a comparison chart invites for free.
            The rows are already ordered best-first by the server; this says why.
          */}
          <p className="mt-1 text-[11px] text-text-muted" data-testid={`format-bars-direction-${objective.family}`}>
            {comparison.lower_is_better
              ? (ar ? 'الأقل أفضل في هذا المؤشر — الشريط الأطول هو الأعلى تكلفة.' : 'Lower is better here — the longest bar is the most expensive.')
              : (ar ? 'الأعلى أفضل في هذا المؤشر.' : 'Higher is better on this metric.')}
          </p>
        </ChartCard>
      )}

      {/*
        A format held out for being one asset is NAMED. «One video ran, which is not enough to speak
        for video» is a true statement about the account and the reader deserves to see it.
      */}
      {tooFew.length > 0 && depth !== 'compact' && (
        <p className="text-[11px] text-text-muted" data-testid={`format-too-few-${objective.family}`}>
          {ar ? 'لم تكفِ للحكم: ' : 'Too few to judge: '}
          {tooFew.map((f) => `${formatWord(f.format, ar)} (${f.creatives})`).join(' · ')}
        </p>
      )}

      {depth === 'full' && rows.length > 0 && <ExactTable objective={objective} coverage={coverage} ar={ar} />}
    </div>
  )
}

/**
 * One format's column of the scoreboard — through the product's ONE labelled figure.
 *
 * `StatCard` owns what a labelled figure looks like: the label scale, the value's direction
 * handling, the exact-figure title and the tone palette. A module that built its own would be a
 * fourth opinion about that, which is the drift the card exists to end — and the guard that caught
 * this one is right to.
 *
 * What this surface brings is its own MEANING: which format leads, what it cost, and how much
 * evidence stands behind it. `tone` carries the verdict, `trailing` the badge, `hint` the evidence
 * base — in that order, because that is the order the reader's questions arrive in.
 */
function FormatCard({ row, label, winner, cover, currency, ar }: {
  row: { format: string; value: number; spend: number | null; creatives: number }
  label: string
  winner: boolean
  cover?: { creatives: number; with_metrics: number; without_metrics: number }
  currency: string | null
  ar: boolean
}) {
  const total = cover?.creatives ?? row.creatives
  const withData = cover?.with_metrics ?? row.creatives
  /*
    Money through the product's own formatter, under the currency the server named — «46,619» beside
    a cost per result is a count, and a symbol this surface guessed would be worse than none.
  */
  const spend = row.spend === null ? '—' : money(row.spend, currency)

  return (
    <StatCard
      testid={`format-card-${row.format}`}
      tone={winner ? 'brand' : 'neutral'}
      label={`${formatWord(row.format, ar)} · ${label}`}
      value={<Num>{round(row.value)}</Num>}
      /*
        «34 محتوى · 27 بأرقام», beside the figure it supports. Without it a reader takes a spend share
        for a performance verdict, which is the misreading the requirement names in as many words.
      */
      hint={ar
        ? `الإنفاق ${spend} · ${total} محتوى · ${withData} بأرقام`
        : `Spend ${spend} · ${total} creatives · ${withData} with data`}
      trailing={winner
        ? (
          /*
            «Best», not «Leads». In an advertising product `leads` is a METRIC, and a badge reading
            «Leads» on a card headed «Cost per result» invites exactly the misreading this module
            exists to prevent.
          */
          <span className="rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-bold text-white">
            {ar ? 'الأفضل' : 'Best'}
          </span>
        )
        : undefined}
    />
  )
}

/** Charts give understanding; the table is where a figure is verified. */
function ExactTable({ objective, coverage, ar }: {
  objective: FormatIntelligencePayload['objectives'][number]
  coverage: FormatIntelligencePayload['coverage']
  ar: boolean
}) {
  const metric = objective.comparison.metric
  const rows = Array.isArray(objective.comparison.formats) ? objective.comparison.formats : []

  return (
    /*
      `min-w-0 max-w-full`, or the container does not contain anything.

      `MetricTable` already wraps itself in an `overflow-x-auto` box, and that box can only clip if
      every ancestor is allowed to SHRINK: a flex or grid item defaults to `min-width: auto`, so a
      640px table grows the item to 640px instead of scrolling inside it. Measured on the client
      report at 375px: 307px of sideways scroll, which is exactly 640 minus the usable width.
    */
    <div className="min-w-0 max-w-full" data-testid={`format-table-${objective.family}`}>
      <DataMetricTable
        columns={[
          { key: 'format', label: ar ? 'النوع' : 'Format', kind: 'text' },
          { key: 'value', label: metric === null ? '—' : metricLabel(metric, ar ? 'ar' : 'en'), kind: 'number' },
          { key: 'spend', label: ar ? 'الإنفاق' : 'Spend', kind: 'money', currency: null },
          { key: 'creatives', label: ar ? 'المحتويات' : 'Creatives', kind: 'number' },
        ]}
        rows={rows.map((row) => ({
          format: formatWord(row.format, ar),
          value: row.value,
          // Null, not zero — the primitive prints «—» and the account never spent a zero.
          spend: row.spend,
          creatives: coverage[row.format]?.creatives ?? row.creatives,
        }))}
      />
    </div>
  )
}

/**
 * Where the money already is — every group, not only the two being ranked.
 *
 * A donut from the product's own chart layer rather than a bar of this module's invention: the
 * figure is a share of a whole, which is the one shape a donut reads better than anything else, and
 * the reader has met this chart on every other surface.
 *
 * Carousel, collection, catalog and unlabelled keep their slice even when the verdict is image
 * against video, because somebody deciding what to commission needs to see the money that is already
 * somewhere else.
 */
function SpendMix({ mix, currency, ar }: { mix: FormatIntelligencePayload['spend_mix']; currency: string | null; ar: boolean }) {
  if (!Array.isArray(mix.formats) || mix.formats.length === 0) return null

  if (!mix.complete) {
    return (
      <p className="text-[11px] text-text-muted" data-testid="format-spend-mix-unavailable">
        {ar
          ? 'توزيع الإنفاق غير متاح: لم يُبلَّغ عن الإنفاق كاملاً في هذا النطاق.'
          : 'Spend mix unavailable: spend was not fully reported in this scope.'}
      </p>
    )
  }

  const slices = mix.formats
    .filter((f) => f.spend !== null && f.spend > 0)
    .map((f) => ({ name: formatWord(f.format, ar), value: f.spend as number }))

  if (slices.length === 0) return null

  return (
    <ChartCard title={ar ? 'توزيع الإنفاق على أنواع المحتوى' : 'Spend by content format'}>
      <div data-testid="format-spend-mix">
        <PlatformDonutChart
          data={slices}
          colorBy="series"
          height={220}
          currency={currency ?? undefined}
          centerLabel={ar ? 'إجمالي الإنفاق' : 'Total spend'}
          centerValue={mix.total === null ? '—' : money(mix.total, currency)}
        />
      </div>
    </ChartCard>
  )
}

/**
 * Is the advantage STRENGTHENING or decaying?
 *
 * The same metric the verdict was decided on, read over buckets rather than days — a daily ratio
 * over a handful of creatives is noise wearing the shape of a trend. A bucket a format could not
 * answer is a GAP in the line: drawn at zero it would read as a collapse in performance, which is
 * the one misreading this module exists to prevent.
 */
function FormatTrend({ trend, ar }: { trend: FormatIntelligencePayload['trend']; ar: boolean }) {
  if (trend == null || trend.metric === null || trend.points.length < 2) return null

  const label = metricLabel(trend.metric, ar ? 'ar' : 'en')
  const keys = Array.from(
    new Set(trend.points.flatMap((p) => Object.keys(p).filter((k) => k !== 'from' && k !== 'to'))),
  )

  if (keys.length === 0) return null

  const data = trend.points.map((p) => {
    const row: Record<string, unknown> = { label: p.from }

    for (const key of keys) {
      const value = p[key]
      // Undefined rather than 0, so the line breaks where the format could not answer.
      row[key] = typeof value === 'number' ? value : undefined
    }

    return row
  })

  return (
    <ChartCard
      title={ar ? `${label} عبر الفترة` : `${label} over the period`}
      subtitle={ar
        ? 'مقسّمة على فترات لا على أيام — نسبة يوم واحد على عدد قليل من المحتويات ليست اتجاهًا'
        : 'In periods rather than days — one day’s ratio over a handful of creatives is not a trend'}
    >
      <div data-testid="format-trend">
        <MetricLineChart
          height={220}
          data={data}
          series={keys.map((key) => ({ key, name: formatWord(key, ar), kind: metricKind(trend.metric ?? '') }))}
        />
      </div>
      <p className="mt-1 text-[11px] text-text-muted" data-testid="format-trend-direction">
        {trend.lower_is_better
          ? (ar ? 'الأقل أفضل في هذا المؤشر.' : 'Lower is better on this metric.')
          : (ar ? 'الأعلى أفضل في هذا المؤشر.' : 'Higher is better on this metric.')}
      </p>
    </ChartCard>
  )
}

function EvidencePill({ evidence, ar }: { evidence: Evidence; ar: boolean }) {
  const words: Record<Evidence, { ar: string; en: string }> = {
    high: { ar: 'أدلة قوية', en: 'High evidence' },
    moderate: { ar: 'أدلة متوسطة', en: 'Moderate evidence' },
    insufficient: { ar: 'أدلة غير كافية', en: 'Insufficient evidence' },
  }
  const Icon = evidence === 'high' ? TrendingUp : evidence === 'moderate' ? Minus : TrendingDown

  return (
    <span
      data-testid={`format-evidence-${evidence}`}
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${evidence === 'insufficient' ? 'bg-surface-secondary text-text-muted' : 'bg-brand-primary-soft text-brand-700'}`}
    >
      <Icon size={11} aria-hidden />
      {ar ? words[evidence].ar : words[evidence].en}
    </span>
  )
}

/**
 * How the chart layer should format this metric's own figures — in ITS vocabulary, not a second one.
 *
 * `FmtKind` is the chart layer's own union, so a cost reads as money and a rate as a percentage with
 * exactly the notation every other chart in the product uses. Naming the kinds here differently
 * would be a second formatting language for the same numbers.
 */
function metricKind(metric: string): 'money' | 'num' | 'percent' | 'ratio' {
  if (metric === 'roas') return 'ratio'
  if (['ctr', 'conversion_rate', 'view_rate', 'completion_rate', 'hook_rate', 'engagement_rate'].includes(metric)) return 'percent'
  if (metric.startsWith('cp') || metric.startsWith('cost_per') || metric === 'aov') return 'money'

  return 'num'
}

const round = (v: number): string => (Math.abs(v) >= 100 ? v.toFixed(0) : v.toFixed(2))
