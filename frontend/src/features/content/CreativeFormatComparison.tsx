import { useQuery } from '@tanstack/react-query'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
import { DataMetricTable } from '@/components/ui/MetricTable'
import { formatIntelligence, type FormatIntelligencePayload } from './api'
import { formatWord, leadObjective, verdictFor, type Evidence } from './formatVerdict'
import { metricLabel } from './metrics'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — «image or video, here?», drawn once and reused.
 *
 * One module, three depths. The figures are identical at every depth because they are the same
 * server answer: only how much of it is drawn changes, which is what stops a dashboard, a campaign
 * page and a client's report quietly disagreeing about one advertiser.
 *
 *   compact  the answer and what it cost — for a dashboard or an executive summary.
 *   medium   the scoreboard and the spend mix — for an account or a campaign page.
 *   full     everything, with the exact table beneath — for Content Analytics and a detailed report.
 *
 * ## It is a scoreboard before it is a table
 *
 * A reader deciding what to commission next wants two columns they can hold in their head, not a
 * spreadsheet. The table stays — exact verification is what makes the rest trustworthy — but it sits
 * under the visuals rather than in front of them.
 *
 * ## It never crowns a format on thin evidence
 *
 * The refusal state is the behaviour being generalised, not one being replaced: with one asset in a
 * format, or no metric both formats reported, the module says so and shows the coverage that
 * explains why.
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
    <section className="grid min-w-0 gap-3" data-testid="creative-format-comparison" data-depth={depth}>
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
          depth={depth}
          ar={ar}
        />
      ))}

      {depth !== 'compact' && payload.spend_mix !== undefined && <SpendMix mix={payload.spend_mix} ar={ar} />}
    </section>
  )
}

function ObjectiveBlock({ objective, coverage, depth, ar }: {
  objective: FormatIntelligencePayload['objectives'][number]
  coverage: FormatIntelligencePayload['coverage']
  depth: 'compact' | 'medium' | 'full'
  ar: boolean
}) {
  const { comparison, evidence } = objective
  const verdict = verdictFor(comparison, evidence)
  const rows = Array.isArray(comparison.formats) ? comparison.formats : []
  const tooFew = Array.isArray(comparison.too_few_to_speak_for_their_format)
    ? comparison.too_few_to_speak_for_their_format
    : []

  return (
    <div className="grid min-w-0 gap-2 rounded-xl border border-border bg-surface p-3" data-testid={`format-objective-${objective.family}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-primary">{ar ? objective.label.ar : objective.label.en}</span>
        <EvidencePill evidence={evidence} ar={ar} />
      </div>

      <p className="text-sm font-bold text-text-primary" data-testid={`format-verdict-${objective.family}`}>
        {ar ? verdict.ar : verdict.en}
      </p>

      {/*
        THE SCOREBOARD — two columns, stacked on a phone.

        A desktop comparison table squeezed into 390px is unreadable, and this is the figure a reader
        on a phone most wants: what each format produced and what it cost.
      */}
      {rows.length > 0 && (
        <div className="grid gap-2 sm:grid-cols-2" data-testid={`format-scorecard-${objective.family}`}>
          {rows.map((row) => (
            <div
              key={row.format}
              className={`grid gap-0.5 rounded-lg border p-2 ${verdict.winner === row.format ? 'border-brand-500 bg-brand-primary-soft' : 'border-border'}`}
              data-testid={`format-card-${row.format}`}
            >
              <span className="text-[11px] font-bold text-text-secondary">{formatWord(row.format, ar)}</span>
              <span className="tnum text-base font-extrabold text-text-primary">
                <Num>{round(row.value)}</Num>
                <span className="ms-1 text-[11px] font-semibold text-text-muted">
                  {comparison.metric === null ? '' : metricLabel(comparison.metric, ar ? 'ar' : 'en')}
                </span>
              </span>
              <span className="text-[11px] text-text-muted" data-testid={`format-coverage-${row.format}`}>
                {evidenceLine(coverage[row.format], row.creatives, ar)}
              </span>
            </div>
          ))}
        </div>
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
 * Charts give understanding; the table is where a figure is verified.
 *
 * TABLE-PRESENTATION-CONTRACT-001 — through the product's one analytical table rather than a fourth
 * hand-rolled one. It owns the alignment, the abbreviation, the exact figure behind it, the currency
 * and what a missing value looks like, so this surface cannot answer any of those differently from
 * the tables beside it. A withheld spend becomes the primitive's own «—», which is the same mark the
 * rest of the product uses for an absence.
 */
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
      *
      * `MetricTable` already wraps itself in an `overflow-x-auto` box, and that box can only clip if
      * every ancestor is allowed to SHRINK: a flex or grid item defaults to `min-width: auto`, so a
      * 640px table grows the item to 640px instead of scrolling inside it. Measured on the client
      * report at 375px: 307px of sideways scroll, which is exactly 640 minus the usable width.
    */
    <div className="min-w-0 max-w-full" data-testid={`format-table-${objective.family}`}>
      <DataMetricTable
        columns={[
          { key: 'format', label: ar ? 'النوع' : 'Format', kind: 'text' },
          {
            key: 'value',
            label: metric === null ? '—' : metricLabel(metric, ar ? 'ar' : 'en'),
            kind: 'number',
          },
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
 * A proportional bar rather than a donut: it stays readable at 390px and it reads left to right in
 * the document's own direction, which a donut's legend does not.
 */
function SpendMix({ mix, ar }: { mix: FormatIntelligencePayload['spend_mix']; ar: boolean }) {
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

  return (
    <div className="grid gap-1" data-testid="format-spend-mix">
      <span className="text-[11px] font-bold text-text-muted">{ar ? 'توزيع الإنفاق' : 'Spend mix'}</span>
      <div className="flex h-2 w-full overflow-hidden rounded-full bg-surface-secondary">
        {mix.formats.map((f, i) => (
          <span
            key={f.format}
            data-testid={`format-spend-share-${f.format}`}
            title={`${formatWord(f.format, ar)} ${Math.round((f.share ?? 0) * 100)}%`}
            style={{ width: `${(f.share ?? 0) * 100}%`, opacity: 1 - i * 0.15 }}
            className="block bg-brand-600"
          />
        ))}
      </div>
      <p className="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-muted">
        {mix.formats.map((f) => (
          <span key={f.format}>
            {formatWord(f.format, ar)} <Num>{`${Math.round((f.share ?? 0) * 100)}%`}</Num>
          </span>
        ))}
      </p>
    </div>
  )
}

function EvidencePill({ evidence, ar }: { evidence: Evidence; ar: boolean }) {
  const words: Record<Evidence, { ar: string; en: string }> = {
    high: { ar: 'أدلة قوية', en: 'High evidence' },
    moderate: { ar: 'أدلة متوسطة', en: 'Moderate evidence' },
    insufficient: { ar: 'أدلة غير كافية', en: 'Insufficient evidence' },
  }

  return (
    <span
      data-testid={`format-evidence-${evidence}`}
      className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${evidence === 'insufficient' ? 'bg-surface-secondary text-text-muted' : 'bg-brand-primary-soft text-brand-700'}`}
    >
      {ar ? words[evidence].ar : words[evidence].en}
    </span>
  )
}

/**
 * «34 creatives, 27 with data» — the evidence base, said beside the figure it supports.
 *
 * Without it a reader takes a spend share for a performance verdict, which is the misreading the
 * requirement names in as many words.
 */
function evidenceLine(
  cover: { creatives: number; with_metrics: number; without_metrics: number } | undefined,
  ranked: number,
  ar: boolean,
): string {
  const total = cover?.creatives ?? ranked
  const withData = cover?.with_metrics ?? ranked

  return ar
    ? `${total} محتوى · ${withData} بأرقام`
    : `${total} creatives · ${withData} with data`
}

const round = (v: number): string => (Math.abs(v) >= 100 ? v.toFixed(0) : v.toFixed(2))
