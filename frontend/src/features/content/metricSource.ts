/**
 * CONTENT-SOURCE-LABEL-001 — where a figure came from, in words.
 *
 * The creative detail's «المصدر» column rendered `row.source` straight, so it read
 * `platform_reported` — in the column that exists to answer «where did this number come from»,
 * which is the first thing a reader checking a figure wants to know.
 *
 * These are the values the backend emits for a metric row's provenance. `store_confirmed` is the
 * one that matters most: it means the shop itself saw the order, as opposed to the ad platform
 * claiming it, and `AttributionTransparency` exists precisely because those two disagree.
 */
export const METRIC_SOURCE_LABELS: Record<string, { ar: string; en: string }> = {
  platform_reported: { ar: 'ما أبلغت به المنصة', en: 'Platform-reported' },
  store_confirmed: { ar: 'مؤكَّد من المتجر', en: 'Store-confirmed' },
  campaign_page: { ar: 'صفحة الحملة', en: 'Campaign page' },
}

export function metricSourceLabel(source: string | null | undefined, ar: boolean): string {
  if (!source) return '—'

  const label = METRIC_SOURCE_LABELS[source]

  // An unknown provenance shows as itself. Where a number came from is exactly the thing not to
  // paper over when the product does not recognise the answer.
  return label ? (ar ? label.ar : label.en) : source
}
