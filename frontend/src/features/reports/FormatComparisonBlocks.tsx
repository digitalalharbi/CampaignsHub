import { Badge } from '@/components/ui/Badge'
import { ContentTypesComparison, CoverageChip, FormatVerdictChip, ObjectiveFormats, rankedFormats } from '@/features/content/ContentTypesComparison'
import type { FormatComparisonPayload } from '@/features/content/api'
import { isClientAudience } from './reportAudience'
import { type WithReportSections } from './reportSections'

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى» in a report: the live link, the saved report and its PDF draw
 * the same component Analytics draws.
 *
 * Drawn only where the server NAMED the part and sent it. What a client may read was decided on the server (no
 * spend, no cost, no ROAS, no campaign names); the renderer additionally treats a client audience as money-free so a
 * stale payload can never put a cost on a client's page.
 */
export interface WithFormatComparison extends WithReportSections {
  content_format_comparison?: FormatComparisonPayload | null
  audience?: string | null
}

export function formatComparisonShown(payload: WithFormatComparison | null | undefined): boolean {
  const list = payload?.report_sections

  return Array.isArray(list) && (list as string[]).includes('content_format_comparison') && payload?.content_format_comparison != null
}

export function FormatComparisonReportBlock({ payload, ar, client }: { payload: WithFormatComparison; ar: boolean; client?: boolean }) {
  if (!formatComparisonShown(payload) || !payload.content_format_comparison) return null
  const data = payload.content_format_comparison
  const forClient = client ?? (isClientAudience(payload.audience ?? undefined) || data.spend_hidden === true)

  return (
    <div data-testid="report-format-comparison" className="min-w-0">
      <ContentTypesComparison data={data} ar={ar} client={forClient} />
    </div>
  )
}

/** One printed page of the comparison: an objective's charts and ranking, or a slice of its table. */
export function FormatComparisonSlide({ slide, data, ar = true, paged = false }: { slide: { id: string; type: string; range?: [number, number] }; data: WithFormatComparison; ar?: boolean; paged?: boolean }) {
  const block = data.content_format_comparison
  if (!block) return null
  const family = slide.id.split(':')[1]
  const objective = block.objectives.find((o) => o.family === family)
  if (!objective) return null
  const client = isClientAudience(data.audience ?? undefined) || block.spend_hidden === true
  const head = (chip?: string) => (
    <div className="mb-3 flex flex-wrap items-center gap-2">
      <h2 className="text-2xl font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h2>
      <Badge tone="info">{ar ? objective.label.ar : objective.label.en}</Badge>
      <CoverageChip objective={objective} ar={ar} client={client} />
      {chip && <Badge tone="neutral">{chip}</Badge>}
    </div>
  )

  if (slide.type === '__formats_table') {
    const [from, to] = slide.range ?? [0, 6]

    return (
      <div data-testid="slide-format-table" data-range={`${from}-${to}`}>
        {head(`${from + 1}–${Math.min(to, objective.formats.length)} / ${objective.formats.length}`)}
        <FormatVerdictChip objective={objective} ar={ar} client={client} />
        <div className="mt-3">
          <ObjectiveFormats objective={{ ...objective, formats: rankedFormats(objective).slice(from, to) }} ar={ar} client={client} currency={client ? null : block.currency} paged part="table" pageSize={6} />
        </div>
      </div>
    )
  }

  return (
    <div data-testid="slide-format-comparison">
      {head()}
      {paged
        ? <ObjectiveFormats objective={objective} ar={ar} client={client} currency={client ? null : block.currency} paged part="charts" />
        : <ObjectiveFormats objective={objective} ar={ar} client={client} currency={client ? null : block.currency} />}
    </div>
  )
}
