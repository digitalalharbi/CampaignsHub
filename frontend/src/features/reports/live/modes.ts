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
export type LiveMode = 'summary' | 'dashboard' | 'platforms' | 'content'

export type LiveForm = 'executive_summary' | 'detailed'

const DETAILED: LiveMode[] = ['summary', 'dashboard', 'platforms', 'content']

export function modesFor(form: LiveForm): LiveMode[] {
  return form === 'executive_summary' ? ['summary'] : DETAILED
}

/** The mode an address asks for, if this form offers it; otherwise the form's own default. */
export function readMode(raw: string | null | undefined, form: LiveForm): LiveMode {
  const asked = modesFor(form).find((m) => m === raw)

  return asked ?? (form === 'executive_summary' ? 'summary' : 'dashboard')
}

/** Views that read the whole link rather than the client's platform chips — they pick a platform themselves. */
export function ownsPlatformChoice(mode: LiveMode): boolean {
  return mode === 'platforms' || mode === 'content'
}

export const MODE_LABELS: Record<LiveMode, { ar: string; en: string }> = {
  summary: { ar: 'الملخص', en: 'Summary' },
  dashboard: { ar: 'لوحة الأداء', en: 'Dashboard' },
  platforms: { ar: 'المنصات', en: 'Platforms' },
  content: { ar: 'المحتوى', en: 'Content' },
}
