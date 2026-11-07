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
  | 'store_analytics'
  | 'ga4_overview'
  | 'ga4_acquisition'
  | 'ga4_platform_gap'
  | 'ga4_tables'
  | 'ga4_attribution'
  | 'ga4_pulse'
  | 'ga4_classification'
  | 'ga4_products'
  | 'ga4_gap_reasons'
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
 * STORE-ANALYTICS-DEPTH-001 §3.1 — the snapshot's page for «تحليلات المتجر والزيارات», only where the server named it.
 * Stricter than `sectionShown`: a snapshot resolved before the section existed never gains a page.
 */
export function withStoreAnalyticsSlide<S extends { id: string; type: string; order: number; visible: boolean }>(
  slides: S[],
  data: WithReportSections & { store_analytics?: unknown },
): S[] {
  const list = data.report_sections
  if (Array.isArray(list) && (list as string[]).includes('store_analytics') && data.store_analytics != null) {
    slides.push({ id: '__store_analytics', type: '__store_analytics', order: 9997, visible: true } as S)
  }

  return slides
}

/** GA4-REPORT-SECTIONS-001 — rows of a GA4 table on one fixed printed page, and how many of a table a file carries. */
export const GA4_ROWS_PER_PAGE = 8
export const GA4_PRINT_ROWS_MAX = 24
/** Platform rows of the attribution match on one printed page. */
export const GA4_MATCH_ROWS_PER_PAGE = 6
const GA4_TABLE_ORDER = ['sources', 'campaigns', 'landing_pages', 'products', 'countries'] as const

/**
 * GA4-REPORT-SECTIONS-001 — the saved report's pages for the store reading, only where the server named the part
 * and sent it (an older snapshot never gains a page).
 *
 * On screen the tables are ONE page with fixed-size paging inside it. On paper (`paged`) a table cannot be paged
 * by hand, so each table is split into pages of eight rows, never shrunk to fit: the slide id carries the table
 * and `range` the rows.
 */
/**
 * REPORT-WEBSITE-TAB-001 — a snapshot saved before the website tab carries no `website_layout` stamp, and renders as it was frozen:
 * its pages in their old order, the store-pulse page included («أي تغيير في Snapshot محفوظ يجب أن يحافظ على النسخة التاريخية»).
 * The stamp is written by the generator into every new snapshot; it is never added to or removed from a stored one.
 */
export function frozenBeforeWebsiteTab(data: object | null | undefined): boolean {
  return data != null && (data as { website_layout?: unknown }).website_layout == null
}

/** The pages exactly as a pre-website-tab snapshot laid them out (GA4-REPORT-SECTIONS-001 / GA4-ATTRIBUTION-MATCH-001). */
function legacyGa4Slides<S extends { id: string; type: string; order: number; visible: boolean; range?: [number, number] }>(
  slides: S[],
  data: Parameters<typeof withGa4Slides>[1],
  paged: boolean,
): S[] {
  const list = data.report_sections
  const named = (key: string, value: unknown) => Array.isArray(list) && (list as string[]).includes(key) && value != null
  if (named('ga4_overview', data.ga4_overview)) slides.push({ id: '__ga4_overview', type: '__ga4_overview', order: 9990, visible: true } as S)
  if (named('ga4_acquisition', data.ga4_acquisition)) slides.push({ id: '__ga4_acquisition', type: '__ga4_acquisition', order: 9991, visible: true } as S)
  if (named('ga4_platform_gap', data.ga4_platform_gap)) slides.push({ id: '__ga4_gap', type: '__ga4_gap', order: 9992, visible: true } as S)
  if (named('ga4_pulse', data.ga4_pulse)) slides.push({ id: '__ga4_pulse', type: '__ga4_pulse', order: 9994, visible: true } as S)
  if (named('ga4_attribution', data.ga4_attribution)) {
    const n = ((data.ga4_attribution as { platforms?: unknown[] }).platforms ?? []).length
    if (!paged || n <= GA4_MATCH_ROWS_PER_PAGE) slides.push({ id: '__ga4_attribution', type: '__ga4_attribution', order: 9995, visible: true } as S)
    else for (let i = 0; i < n; i += GA4_MATCH_ROWS_PER_PAGE) slides.push({ id: `__ga4_attribution:${i}`, type: '__ga4_attribution', order: 9995, visible: true, range: [i, Math.min(i + GA4_MATCH_ROWS_PER_PAGE, n)] } as S)
  }
  if (named('ga4_gap_reasons', data.ga4_gap_reasons)) slides.push({ id: '__ga4_reasons', type: '__ga4_reasons', order: 9996, visible: true } as S)
  if (named('ga4_classification', data.ga4_classification)) {
    const groups = (data.ga4_classification as { groups?: Record<string, { rows?: unknown[] }> }).groups ?? {}
    if (!paged) slides.push({ id: '__ga4_classification', type: '__ga4_classification', order: 9996, visible: true } as S)
    else {
      for (const [group, block] of Object.entries(groups)) {
        const n = block.rows?.length ?? 0
        for (let i = 0; i < Math.max(n, 1); i += GA4_ROWS_PER_PAGE) slides.push({ id: `__ga4_classification:${group}:${i}`, type: '__ga4_classification', order: 9996, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, n)] } as S)
      }
    }
  }
  if (named('ga4_products', data.ga4_products)) {
    const rows = Math.min(data.ga4_products?.table?.rows?.length ?? 0, GA4_PRINT_ROWS_MAX)
    if (!paged || rows <= GA4_ROWS_PER_PAGE) slides.push({ id: '__ga4_products:0', type: '__ga4_products', order: 9996, visible: true, range: [0, Math.min(rows, GA4_ROWS_PER_PAGE)] } as S)
    else for (let i = 0; i < rows; i += GA4_ROWS_PER_PAGE) slides.push({ id: `__ga4_products:${i}`, type: '__ga4_products', order: 9996, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, rows)] } as S)
  }
  if (named('ga4_tables', data.ga4_tables)) {
    if (!paged) slides.push({ id: '__ga4_tables', type: '__ga4_tables', order: 9993, visible: true } as S)
    else {
      for (const table of GA4_TABLE_ORDER) {
        const rows = Math.min(data.ga4_tables?.tables?.[table]?.rows?.length ?? 0, GA4_PRINT_ROWS_MAX)
        for (let i = 0; i < rows; i += GA4_ROWS_PER_PAGE) slides.push({ id: `__ga4_table:${table}:${i}`, type: '__ga4_table', order: 9993, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, rows)] } as S)
      }
    }
  }

  return slides
}

export function withGa4Slides<S extends { id: string; type: string; order: number; visible: boolean; range?: [number, number] }>(
  slides: S[],
  data: WithReportSections & { website_layout?: unknown; ga4_overview?: unknown; ga4_acquisition?: unknown; ga4_platform_gap?: unknown; ga4_attribution?: unknown; ga4_pulse?: unknown; ga4_classification?: unknown; ga4_products?: { table?: { rows?: unknown[] } } | null; ga4_gap_reasons?: unknown; ga4_tables?: { tables?: Partial<Record<(typeof GA4_TABLE_ORDER)[number], { rows?: unknown[] } | null>> } | null },
  paged = false,
): S[] {
  if (frozenBeforeWebsiteTab(data)) return legacyGa4Slides(slides, data, paged)
  const list = data.report_sections
  const named = (key: string, value: unknown) => Array.isArray(list) && (list as string[]).includes(key) && value != null
  /*
   * REPORT-WEBSITE-TAB-001 — the website pages follow the live link's «الموقع» tab, in its order: key figures and trend,
   * channels / devices / website funnel, the attribution match, the website against the platforms, why the gap, the
   * classification, the tables, the products. The one headline strip of the summary lives on the live link only.
   */
  // The headline strip (`ga4_pulse`) is a live-link element: a page of four figures on paper measured empty (util 0.01) and failed the layout gate, and the same figures are the first tiles of the overview page.
  if (named('ga4_overview', data.ga4_overview)) slides.push({ id: '__ga4_overview', type: '__ga4_overview', order: 9990, visible: true } as S)
  if (named('ga4_acquisition', data.ga4_acquisition)) slides.push({ id: '__ga4_acquisition', type: '__ga4_acquisition', order: 9991, visible: true } as S)
  if (named('ga4_attribution', data.ga4_attribution)) {
    // On paper the platform rows are split across pages of six, never shrunk onto one.
    const n = ((data.ga4_attribution as { platforms?: unknown[] }).platforms ?? []).length
    if (!paged || n <= GA4_MATCH_ROWS_PER_PAGE) slides.push({ id: '__ga4_attribution', type: '__ga4_attribution', order: 9992, visible: true } as S)
    else for (let i = 0; i < n; i += GA4_MATCH_ROWS_PER_PAGE) slides.push({ id: `__ga4_attribution:${i}`, type: '__ga4_attribution', order: 9992, visible: true, range: [i, Math.min(i + GA4_MATCH_ROWS_PER_PAGE, n)] } as S)
  }
  // The attribution match already sets the website beside each platform: the older comparison page stands only without it.
  if (named('ga4_platform_gap', data.ga4_platform_gap) && !named('ga4_attribution', data.ga4_attribution)) slides.push({ id: '__ga4_gap', type: '__ga4_gap', order: 9993, visible: true } as S)
  if (named('ga4_gap_reasons', data.ga4_gap_reasons)) slides.push({ id: '__ga4_reasons', type: '__ga4_reasons', order: 9994, visible: true } as S)
  if (named('ga4_classification', data.ga4_classification)) {
    const groups = (data.ga4_classification as { groups?: Record<string, { rows?: unknown[] }> }).groups ?? {}
    if (!paged) slides.push({ id: '__ga4_classification', type: '__ga4_classification', order: 9995, visible: true } as S)
    else {
      // One page per grouping and per eight rows: a long grouping splits, so no row is cut and no page is shrunk.
      for (const [group, block] of Object.entries(groups)) {
        const n = block.rows?.length ?? 0
        for (let i = 0; i < Math.max(n, 1); i += GA4_ROWS_PER_PAGE) slides.push({ id: `__ga4_classification:${group}:${i}`, type: '__ga4_classification', order: 9995, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, n)] } as S)
      }
    }
  }
  if (named('ga4_tables', data.ga4_tables)) {
    if (!paged) {
      slides.push({ id: '__ga4_tables', type: '__ga4_tables', order: 9996, visible: true } as S)
    } else {
      // The products have their own pages when that part is named: one product table, never two.
      for (const table of GA4_TABLE_ORDER.filter((t) => t !== 'products' || !named('ga4_products', data.ga4_products))) {
        const rows = Math.min(data.ga4_tables?.tables?.[table]?.rows?.length ?? 0, GA4_PRINT_ROWS_MAX)
        for (let i = 0; i < rows; i += GA4_ROWS_PER_PAGE) {
          slides.push({ id: `__ga4_table:${table}:${i}`, type: '__ga4_table', order: 9996, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, rows)] } as S)
        }
      }
    }
  }
  if (named('ga4_products', data.ga4_products)) {
    const rows = Math.min(data.ga4_products?.table?.rows?.length ?? 0, GA4_PRINT_ROWS_MAX)
    if (!paged || rows <= GA4_ROWS_PER_PAGE) slides.push({ id: '__ga4_products:0', type: '__ga4_products', order: 9997, visible: true, range: [0, Math.min(rows, GA4_ROWS_PER_PAGE)] } as S)
    else for (let i = 0; i < rows; i += GA4_ROWS_PER_PAGE) slides.push({ id: `__ga4_products:${i}`, type: '__ga4_products', order: 9997, visible: true, range: [i, Math.min(i + GA4_ROWS_PER_PAGE, rows)] } as S)
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

