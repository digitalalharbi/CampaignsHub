/**
 * REPORT-SECTION-SURFACES-001 — the page draws the sections the server resolved, and nothing else.
 *
 * Every surface's payload carries `report_sections`: the ordered keys of the sections that are
 * visible. A hidden section's data is ABSENT from the payload (not emptied), so a renderer must
 * never read a section's keys without asking this first — and a key two sections share (`timeseries`
 * feeds the KPI sparklines and the trend chart; `platforms` feeds the comparison and the tables) is
 * still present when only one of them is visible, which is why presence is not the test.
 *
 * A payload without the list comes from a server older than the section model; everything it holds
 * is drawn, as before.
 */
export type ReportSectionKey =
  | 'kpis'
  | 'trends'
  | 'platform_comparison'
  | 'budget_pacing'
  | 'funnel'
  | 'content_performance'
  | 'recommendations'
  | 'detailed_tables'
  | 'objective_breakdown'
  | 'advanced_segmentation'

export interface WithReportSections {
  report_sections?: ReportSectionKey[] | string[]
}

export function sectionShown(payload: WithReportSections | null | undefined, key: ReportSectionKey): boolean {
  const list = payload?.report_sections

  return !Array.isArray(list) || (list as string[]).includes(key)
}

/**
 * REPORT-SECTION-STREAMS-001 — the deck's business-stream page, added where the server sent streams.
 *
 * The template engine's slide list predates the streams, so the page is appended after the report's
 * own slides (before the methodology note) rather than stored in every snapshot's config.
 */
export function withStreamsSlide<S extends { id: string; type: string; order: number; visible: boolean }>(
  slides: S[],
  data: WithReportSections & { business_streams?: unknown[] },
): S[] {
  if (sectionShown(data, 'advanced_segmentation') && (data.business_streams?.length ?? 0) > 0) {
    slides.push({ id: '__streams', type: '__streams', order: 9998, visible: true } as S)
  }

  return slides
}
