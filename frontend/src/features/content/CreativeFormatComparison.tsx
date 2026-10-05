import { useQuery } from '@tanstack/react-query'
import { Num } from '@/components/ui/Num'
import { Skeleton } from '@/components/ui/States'
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

  const payload = answer.data
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
    <section className="grid gap-3" data-testid="creative-format-comparison" data-depth={depth}>
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
    <div className="grid gap-2 rounded-xl border border-border bg-surface p-3" data-testid={`format-objective-${objective.family}`}>
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

/** Charts give understanding; the table is where a figure is verified. */
function ExactTable({ objective, coverage, ar }: {
  objective: FormatIntelligencePayload['objectives'][number]
  coverage: FormatIntelligencePayload['coverage']
  ar: boolean
}) {
  const metric = objective.comparison.metric

  return (
    <div className="overflow-x-auto" data-testid={`format-table-${objective.family}`}>
      <table className="w-full min-w-[320px] text-[11px]">
        <thead>
          <tr className="text-text-muted">
            <th className="p-1 text-start font-semibold">{ar ? 'النوع' : 'Format'}</th>
            <th className="p-1 text-start font-semibold">{metric === null ? '—' : metricLabel(metric, ar ? 'ar' : 'en')}</th>
            <th className="p-1 text-start font-semibold">{ar ? 'الإنفاق' : 'Spend'}</th>
            <th className="p-1 text-start font-semibold">{ar ? 'المحتويات' : 'Creatives'}</th>
          </tr>
        </thead>
        <tbody>
          {objective.comparison.formats.map((row) => (
            <tr key={row.format} className="border-t border-border">
              <td className="p-1 font-semibold text-text-primary">{formatWord(row.format, ar)}</td>
              <td className="tnum p-1"><Num>{round(row.value)}</Num></td>
              {/* A withheld amount is «—», never a zero the account never spent. */}
              <td className="tnum p-1">{row.spend === null ? '—' : <Num>{round(row.spend)}</Num>}</td>
              <td className="tnum p-1">
                <Num>{String(coverage[row.format]?.creatives ?? row.creatives)}</Num>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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
