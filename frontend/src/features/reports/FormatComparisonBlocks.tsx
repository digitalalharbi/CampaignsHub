import { isClientAudience } from './reportAudience'
import { type WithReportSections } from './reportSections'
import type { FormatComparisonPayload } from '@/features/content/api'
import { FormatComparisonStrip, tableHasFigures } from './FormatComparisonStrip'

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى» in a report: the live link, the saved report and its PDF.
 *
 * REPORT-FORMAT-COMPARE-COMPACT-001 (Owner 2026-11-13) — drawn ONCE per report as one table (`FormatComparisonStrip`, REPORT-FORMAT-COMPARE-TABLE-001):
 * a column per content type, a row per KPI of the report, and no row or column of nothing. The Analytics
 * tab keeps the full comparison (charts, switches, drill); a report is a reading, not a workbench.
 *
 * Drawn only where the server NAMED the part and sent it. What a client may read was decided on the server (no spend, no cost,
 * no ROAS, no campaign names); the renderer additionally treats a client audience as money-free so a stale payload can never put
 * a cost on a client's page.
 */
export interface WithFormatComparison extends WithReportSections {
  content_format_comparison?: FormatComparisonPayload | null
  audience?: string | null
}

export function formatComparisonShown(payload: WithFormatComparison | null | undefined): boolean {
  const list = payload?.report_sections

  return Array.isArray(list) && (list as string[]).includes('content_format_comparison') && payload?.content_format_comparison != null
}

const hidesMoney = (payload: WithFormatComparison, client?: boolean) =>
  client ?? (isClientAudience(payload.audience ?? undefined) || payload.content_format_comparison?.spend_hidden === true)

export function FormatComparisonReportBlock({ payload, ar, client }: { payload: WithFormatComparison; ar: boolean; client?: boolean }) {
  if (!formatComparisonShown(payload) || !payload.content_format_comparison) return null
  const forClient = hidesMoney(payload, client)
  if (!tableHasFigures(payload.content_format_comparison, forClient)) return null

  return (
    <div data-testid="report-format-comparison" className="min-w-0">
      <FormatComparisonStrip data={payload.content_format_comparison} ar={ar} client={forClient} />
    </div>
  )
}

/** The deck's (and the PDF's) one page of the comparison: every objective of the report, as the strip. */
export function FormatComparisonSlide({ data, ar = true }: { data: WithFormatComparison; ar?: boolean }) {
  const block = data.content_format_comparison
  if (!block || !tableHasFigures(block, hidesMoney(data))) return null

  return (
    <div data-testid="slide-format-comparison">
      <h2 className="mb-3 text-2xl font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h2>
      <FormatComparisonStrip data={block} ar={ar} client={hidesMoney(data)} title={false} />
    </div>
  )
}
