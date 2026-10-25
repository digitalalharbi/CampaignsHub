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
  | 'site_measurement'
  | 'store_sales'
  | 'store_products'
  | 'store_orders'
  | 'store_audience'
  | 'recommendations'
  | 'detailed_tables'
  | 'daily_records'
  | 'objective_breakdown'
  | 'advanced_segmentation'
  | 'account_breakdown'

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

/**
 * REPORT-COMPOSITION-CONTROL-001 — the daily ledger is printed only where the server NAMED it.
 *
 * Stricter than `sectionShown`, which reads an absent list as «everything»: a document resolved before
 * the component existed never gains a page it did not have.
 */
export function dailyRecordsChosen(data: WithReportSections | null | undefined): boolean {
  const list = data?.report_sections

  return Array.isArray(list) && (list as string[]).includes('daily_records')
}

/**
 * Days per deck page — a fixed page must never be cut by its edge, and the print layout gate refuses a
 * page it would have to shrink below 0.85. Fourteen days measured 0.839 on the CI Chromium (the PDF
 * export failed); ten measured 0.91 locally, so eight leave a margin for the CI fonts.
 */
export const DAILY_ROWS_PER_PAGE = 8

/** The deck's daily-records pages, appended like the stream page, one per eight days. */
export function withDailyRecordsSlides<S extends { id: string; type: string; order: number; visible: boolean; range?: [number, number] }>(
  slides: S[],
  data: WithReportSections & { timeseries?: unknown[] },
): S[] {
  if (!dailyRecordsChosen(data)) return slides
  const days = data.timeseries?.length ?? 0
  for (let i = 0; i < days; i += DAILY_ROWS_PER_PAGE) {
    slides.push({ id: `__daily-${i}`, type: '__daily', order: 9997, visible: true, range: [i, i + DAILY_ROWS_PER_PAGE] } as S)
  }

  return slides
}

/** WD-EVIDENCE-001 — one appendix screenshot as the print payload names it. */
export interface PrintEvidence {
  id: string
  provider: string
  period_start: string | null
  period_end: string | null
  captured_at: string | null
  note: string | null
  name: string | null
  image: boolean
  url: string | null
}

/**
 * WD-EVIDENCE-001 — the PDF's appendix of platform screenshots: one fixed page each, after the report
 * proper, present only when the server attached screenshots the Owner chose to include.
 */
export function withEvidenceSlides<S extends { id: string; type: string; order: number; visible: boolean; range?: [number, number] }>(
  slides: S[],
  data: { platform_evidence?: PrintEvidence[] },
): S[] {
  ;(data.platform_evidence ?? []).forEach((_, i) => {
    slides.push({ id: `__evidence-${i}`, type: '__evidence', order: 9999, visible: true, range: [i, i + 1] } as S)
  })

  return slides
}

