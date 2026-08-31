/**
 * ATTRIBUTION-WINDOW-001 — «أساس الإسناد: 7d_click_1d_view», in a client's report.
 *
 * The window is printed on the report footer as the basis every figure above it was measured under
 * — which is exactly the reader who cannot be expected to decode a platform's parameter name.
 *
 * It is a PATTERN rather than an enum (`{n}d_click_{m}d_view`), so this parses it instead of keeping
 * a lookup that would miss the first window a platform invents. Anything it cannot parse is returned
 * unchanged: an unrecognised window is a fact about the data, and inventing «7 days» for it would be
 * the claim `AttributionTransparency` refuses to make one layer down.
 */
export type AttributionWindowReading = {
  /** Prose for the reader, or the raw value when it could not be parsed. */
  text: string
  /** False when the platform did not state a window, or stated one this cannot read. */
  known: boolean
}

/*
 * Deliberately NOT `lib/counted`, and this is the one place in the product that is true.
 *
 * That module counts a figure and its noun — «1 يوم», «2 يومان» — which is what a KPI and a summary
 * line want. This is PROSE: «نقرة خلال يوم واحد» and «نقرة خلال يومين», where the numeral is not
 * read as a figure at all and «خلال 1 يوم» is what a machine writes. The shared rule and this one
 * agree from three upwards, which is where the counting actually starts.
 */
const DAYS_AR = (n: number): string => (n === 1 ? 'يوم واحد' : n === 2 ? 'يومين' : n <= 10 ? `${n} أيام` : `${n} يومًا`)

export function attributionWindow(raw: string | null | undefined, ar: boolean): AttributionWindowReading {
  const value = (raw ?? '').trim()

  if (value === '') return { text: ar ? 'غير محدَّدة' : 'Not stated', known: false }

  /*
   * `default` means the platform did not tell us. Said plainly, because the word itself reads as a
   * setting the reader could change — and because the figures above it are still correct; only the
   * window they were measured under is unstated.
   */
  if (value === 'default') {
    return {
      text: ar ? 'لم تُفصح المنصة عن نافذة الإسناد' : 'The platform did not state its attribution window',
      known: false,
    }
  }

  const click = value.match(/(\d+)d_click/)
  const view = value.match(/(\d+)d_view/)

  if (!click && !view) return { text: value, known: false }

  const parts: string[] = []
  if (click) parts.push(ar ? `نقرة خلال ${DAYS_AR(Number(click[1]))}` : `${click[1]}-day click`)
  if (view) parts.push(ar ? `مشاهدة خلال ${DAYS_AR(Number(view[1]))}` : `${view[1]}-day view`)

  return { text: parts.join(ar ? '، و' : ' · '), known: true }
}
