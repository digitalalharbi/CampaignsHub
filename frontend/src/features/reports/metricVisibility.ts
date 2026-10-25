/**
 * REPORT-KPI-VISIBILITY-001 — which metrics a report hides, and how every surface reads that.
 *
 * The SERVER is the authority: a hidden metric's figures are already out of the payload (and out of the CSV, the workbook and the
 * print route) by the time a page sees it. This module is only the page's half — it keeps a card, a column or a chart option that
 * names a hidden metric from being DRAWN empty, because «a column of dashes» tells the client exactly what was held back.
 *
 * The catalog mirrors the server's (`ReportMetricVisibility::CATALOG`); the grouped selector and the report both read it.
 */

export type MetricGroup = 'delivery' | 'results' | 'value' | 'cost'

export interface MetricChoice { key: string; group: MetricGroup; ar: string; en: string }

export const METRIC_GROUPS: ReadonlyArray<{ key: MetricGroup; ar: string; en: string }> = [
  { key: 'delivery', ar: 'الوصول والتفاعل', en: 'Delivery' },
  { key: 'results', ar: 'النتائج', en: 'Results' },
  { key: 'value', ar: 'القيمة والعائد', en: 'Value' },
  { key: 'cost', ar: 'التكاليف', en: 'Costs' },
]

export const METRIC_CHOICES: ReadonlyArray<MetricChoice> = [
  { key: 'spend', group: 'delivery', ar: 'الإنفاق', en: 'Spend' },
  { key: 'impressions', group: 'delivery', ar: 'مرات الظهور', en: 'Impressions' },
  { key: 'clicks', group: 'delivery', ar: 'النقرات', en: 'Clicks' },
  { key: 'ctr', group: 'delivery', ar: 'نسبة النقر', en: 'CTR' },
  { key: 'add_to_cart', group: 'results', ar: 'إضافات للسلة', en: 'Add to cart' },
  { key: 'purchases', group: 'results', ar: 'عمليات الشراء', en: 'Purchases' },
  { key: 'conversion_rate', group: 'results', ar: 'معدل التحويل', en: 'Conversion rate' },
  { key: 'revenue', group: 'value', ar: 'الإيرادات', en: 'Revenue' },
  { key: 'roas', group: 'value', ar: 'العائد على الإنفاق', en: 'ROAS' },
  { key: 'cpa', group: 'cost', ar: 'تكلفة الشراء', en: 'Cost per purchase (CPA)' },
  { key: 'cpl', group: 'cost', ar: 'تكلفة العميل المحتمل', en: 'Cost per lead (CPL)' },
  { key: 'cpc', group: 'cost', ar: 'تكلفة النقرة', en: 'Cost per click (CPC)' },
  { key: 'cpm', group: 'cost', ar: 'تكلفة الألف ظهور', en: 'CPM' },
]

/** What a NEW client-facing report hides until the operator says otherwise — the sales KPIs, without the cost per purchase. */
export const CLIENT_PRESET_HIDDEN: readonly string[] = ['cpa']

/** The table column a hidden metric takes with it (`cost` is the cost-per-result column of whichever objective the table reads). */
const COLUMN_OF: Record<string, string> = { cpa: 'cost', cpl: 'cost', cpc: 'cost', cpm: 'cost' }

/** The metrics a payload says are hidden. Absent means «this report never chose». */
export function hiddenMetricsOf(source: { hidden_metrics?: readonly string[] | null } | null | undefined): ReadonlySet<string> {
  return new Set(source?.hidden_metrics ?? [])
}

/** Whether a table column (`cost`, `spend`, `roas`…) is gone because the metric behind it is hidden. */
export function columnHidden(hidden: ReadonlySet<string>, column: string): boolean {
  if (hidden.size === 0) return false
  if (hidden.has(column)) return true

  return [...hidden].some((metric) => COLUMN_OF[metric] === column)
}

/** Keep only the columns whose metric is shown. The first column (the row's name) is never a metric. */
export function visibleColumns<T extends { key: string }>(columns: readonly T[], hidden: ReadonlySet<string>): T[] {
  return columns.filter((c, i) => i === 0 || !columnHidden(hidden, c.key))
}
