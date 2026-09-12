import { percent, rowCostPer, rowMoney, rowRoas } from '@/features/analytics/format'
import { readMetricValue } from '@/lib/metricValue'
import type { MoneyTotals } from '@/lib/money/contract'

/**
 * AD-PREVIEW-FIGURES-001 — the figures a creative's dialog carries, read the way its table reads them.
 *
 * ## Why this is a module
 *
 * The list sat inline in a three-thousand-line page, which is how it came to disagree with the row
 * that opens it. Production printed «CPM 65.65%» and «CPC 125.38%» — costs in money, run through a
 * reader that multiplies by a hundred and appends a percent sign — while every table row in the same
 * FILE printed the same two figures through `rowCostPer`. ROAS went the same way: 4.2× as «420.00%».
 *
 * The comment above those lines even gave a reason: that `rowMoney` reads the money contract's
 * spend/revenue envelope and «a cost-per is a derived ratio rather than an amount with its own
 * withheld provenance». True, and beside the point — `rowCostPer` and `rowRoas` exist for exactly
 * those two shapes and carry that provenance themselves.
 *
 * ## What each reader is for
 *
 * `rowMoney` for an amount, `rowCostPer` for a cost per something, `rowRoas` for a return, `percent`
 * for a rate, and the count reader for a count. One shape, one reader — which is the whole of the
 * rule, and the reason the dialog can now promise what its docblock always claimed: that it cannot
 * disagree with the line the reader clicked.
 */
export type DialogFigure = { label: string; value: string }

/**
 * @param metrics the creative's own figures — the money contract's envelope, not a plain bag
 * @param currency the surface's reporting currency; null prints an amount bare
 */
export function creativeDialogFigures(metrics: MoneyTotals, currency: string | null, ar: boolean): DialogFigure[] {
  const bag = (metrics ?? {}) as Record<string, unknown>
  const n = (key: string): number => {
    const v = bag[key]

    return typeof v === 'number' ? v : 0
  }
  const rate = (key: string): string => {
    const v = bag[key]

    return typeof v === 'number' ? percent(v, 2) : '—'
  }

  const figures: DialogFigure[] = [
    { label: ar ? 'الإنفاق' : 'Spend', value: rowMoney(metrics, 'spend', currency) },
    { label: ar ? 'الظهور' : 'Impressions', value: readMetricValue('number', bag.impressions ?? null).text },
    { label: ar ? 'النقرات' : 'Clicks', value: readMetricValue('number', bag.clicks ?? null).text },
    { label: 'CTR', value: rate('ctr') },
    /*
     * The denominators are stated here, where the factor of a thousand is visible.
     *
     * `readCostPer` takes the number rather than a field name for CPM precisely so that «per
     * thousand impressions» is written at the call site instead of hidden behind an invented field
     * that no payload carries — a lookup that misses reads as «unavailable» and looks like a
     * provenance decision rather than a typo.
     */
    { label: 'CPC', value: costPer(metrics, 'cpc', n('clicks'), currency, bag.cpc) },
    { label: 'CPM', value: costPer(metrics, 'cpm', n('impressions') / 1000, currency, bag.cpm) },
  ]

  /*
   * Revenue and return appear only where the provider sent them — not as «—» on every brand ad.
   *
   * A tile reading «الإيرادات —» on every awareness creative in an account teaches a reader to skip
   * the row, and the row is where the sales creatives state theirs.
   */
  if (typeof bag.revenue === 'number') {
    figures.push({ label: ar ? 'الإيرادات' : 'Revenue', value: rowMoney(metrics, 'revenue', currency) })
  }

  if (typeof bag.roas === 'number') {
    figures.push({ label: 'ROAS', value: rowRoas(metrics) })
  }

  return figures
}

/**
 * A cost per something — and «—» where the provider never sent it.
 *
 * `rowCostPer` answers «unavailable» for both an absent figure and an unconvertible one, and the two
 * print the same dash. The explicit absence check is here so a zero denominator on a creative that
 * DID report its cost cannot turn a real figure into a dash.
 */
function costPer(
  metrics: MoneyTotals,
  key: string,
  denominator: number,
  currency: string | null,
  raw: unknown,
): string {
  return typeof raw === 'number' ? rowCostPer(metrics, key, denominator, currency) : '—'
}
