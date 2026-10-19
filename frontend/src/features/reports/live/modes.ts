/**
 * The live client link's MODES — four products over one live payload, not one page retitled.
 *
 * Owner defect row 96 was «the modes look effectively the same»: an executive summary and a detailed
 * report measured eleven words apart. The answer is not more conditionals inside one long scroll; it
 * is separate compositions a client moves between, each built for one question:
 *
 *   summary    — «how did it go?»            headline KPIs and three to five visuals, nothing else.
 *   dashboard  — «show me everything»         the full live board: trends, distribution, platforms,
 *                                              best and weakest content, funnel, budget. The primary mode.
 *   platforms  — «how did each channel do?»   one platform at a time, its own figures, trend and content.
 *   content    — «which content worked?»      platform → content, with previews, figures and trends.
 *
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 sets the drilldown: platform → content. There is no campaign mode,
 * and nothing here may add one.
 *
 * A link shared as an EXECUTIVE SUMMARY is that product only — it has no tabs, because the operator
 * chose to send a short document, and the server has already trimmed what a summary does not carry
 * (`ReportComposition`). A DETAILED link opens on the dashboard and offers all four.
 */
export type LiveMode = 'summary' | 'standard' | 'dashboard' | 'platforms' | 'content' | 'tables'

/** REPORT-ARCHITECTURE-001 — the product the Owner chose: three different compositions, not one with a dial. */
export type LiveLevel = 'summary' | 'standard' | 'detailed'

export type LiveForm = 'executive_summary' | 'detailed'

/*
 * REPORT-ARCHITECTURE-001 — what each LEVEL is made of.
 *
 *   summary   — the executive one-pager: one view, no tabs.
 *   standard  — the balanced client report: ONE composition (`StandardView`), no tabs, no tables.
 *   detailed  — an analytical workspace: the executive summary, the full board, each platform on
 *               its own, the content browser, and a tables view — five views a reader moves between.
 *
 * A non-summary link whose level is not yet known (the payload has not arrived) is read as detailed,
 * which is what every link made before levels existed is.
 */
const BY_LEVEL: Record<LiveLevel, LiveMode[]> = {
  summary: ['summary'],
  standard: ['standard'],
  // The executive summary stays the Owner's chosen opening view (REPORT-EXECUTIVE-SUMMARY-001); the
  // analytical views are one tab away, and `tables` exists nowhere else.
  detailed: ['summary', 'dashboard', 'platforms', 'content', 'tables'],
}

export function levelOf(form: LiveForm, level?: LiveLevel | null): LiveLevel {
  if (form === 'executive_summary') return 'summary'

  return level === 'standard' || level === 'detailed' ? level : 'detailed'
}

export function modesFor(form: LiveForm, level?: LiveLevel | null): LiveMode[] {
  return BY_LEVEL[levelOf(form, level)]
}

/** The mode an address asks for, if this level offers it; otherwise the level's own opening view. */
export function readMode(raw: string | null | undefined, form: LiveForm, level?: LiveLevel | null): LiveMode {
  const offered = modesFor(form, level)

  return offered.find((m) => m === raw) ?? offered[0]
}

/**
 * REPORT-COMPOSITION-CONTROL-001 — the level's views, narrowed by the main sections the server says
 * this link offers. A payload from before the control (no list) keeps every view, and a list that
 * would leave nothing is ignored rather than leaving a reader with an empty page.
 */
export function offeredViews(offered: LiveMode[], reportViews?: string[] | null): LiveMode[] {
  if (!Array.isArray(reportViews)) return offered
  const kept = offered.filter((m) => reportViews.includes(m))

  return kept.length > 0 ? kept : offered
}

/** Views that read the whole link rather than the client's platform chips — they pick a platform themselves. */
export function ownsPlatformChoice(mode: LiveMode): boolean {
  return mode === 'platforms' || mode === 'content'
}

export const MODE_LABELS: Record<LiveMode, { ar: string; en: string }> = {
  summary: { ar: 'الملخص', en: 'Summary' },
  standard: { ar: 'التقرير', en: 'Report' },
  tables: { ar: 'الجداول', en: 'Tables' },
  dashboard: { ar: 'لوحة الأداء', en: 'Dashboard' },
  platforms: { ar: 'المنصات', en: 'Platforms' },
  content: { ar: 'المحتوى', en: 'Content' },
}
