import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { compact, moneyFromTotals } from '@/features/analytics/format'
import type { MoneyTotals } from '@/lib/money/contract'
import type { LivePayload } from './api'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-PRODUCT-MODEL-001 §D — what makes a LIVE report detailed rather than a dashboard with a
 * longer label.
 *
 * ## The defect
 *
 * A live link whose form is `detailed` rendered the dashboard: a spend chart, a platform donut and
 * a top-EIGHT campaign bar. Above it the page printed «تقرير تفصيلي — كل المنصات والحملات
 * والإعلانات». A client who counted the bars found eight campaigns where the sentence promised all
 * of them, and no way to see the ninth. The label was not wrong about what the product can do; it
 * was wrong about what that page was.
 *
 * So the detailed form gets what the sentence says: every campaign and every platform in the chosen
 * window, as tables, ordered by whatever column the reader picks.
 *
 * ## Every campaign, and no invented figure
 *
 * The rows are the live payload's own, unsliced — the dashboard's top-eight is a chart's ceiling and
 * has no business here. Money goes through the same contract as the rest of the page: a figure whose
 * currency is withheld, partial or mixed reads «—» rather than being printed under this report's
 * currency, and it sorts LAST rather than as a zero.
 */
export function LiveDetailTables({
  payload,
  currency,
  locale,
}: {
  payload: LivePayload
  currency: string
  locale: Locale
}) {
  const ar = locale === 'ar'
  const t = {
    campaigns: ar ? 'كل الحملات' : 'Every campaign',
    platforms: ar ? 'كل المنصات' : 'Every platform',
    campaign: ar ? 'الحملة' : 'Campaign',
    platform: ar ? 'المنصة' : 'Platform',
    spend: ar ? 'الإنفاق' : 'Spend',
    impressions: ar ? 'الظهور' : 'Impressions',
    clicks: ar ? 'النقرات' : 'Clicks',
    results: ar ? 'النتائج' : 'Results',
    none: ar ? 'لا توجد صفوف في هذه الفترة.' : 'No rows in this period.',
  }

  const numberOf = (row: Record<string, unknown>, key: string): number | null => {
    const v = row[key]

    return typeof v === 'number' ? v : null
  }

  /** The money contract's reading, so a withheld or mixed amount is «—» and never this currency. */
  const spendOf = (row: Record<string, unknown>) => moneyFromTotals(row as MoneyTotals, 'spend', ar, currency)

  /*
   * What a spend cell SORTS by. The reading decides first: a figure the page refused to print — a
   * withheld amount, one awaiting a rate, one spanning currencies — sorts as an absence, because
   * ordering a table by a number the reader cannot see is a ranking nobody can check.
   */
  const spendValue = (row: Record<string, unknown>): number | null =>
    spendOf(row).text === '\u2014' ? null : numberOf(row, 'spend')

  /*
   * NUMBER-PRESENTATION-001 — the exact figure behind every abbreviation on this table.
   *
   * `compact()` prints «90K» where the column is 60px wide, which is the right call for scanning and
   * the wrong one for deciding: two campaigns both reading «32K» can be a thousand results apart.
   * The full number is one hover away, and `null` where there is nothing to reveal — a tooltip that
   * repeats what is already on screen teaches a reader to stop looking at them.
   */
  const full = (v: number | null): string | null => {
    if (v === null) return null
    const shown = compact(v)
    const whole = v.toLocaleString('en-US')

    return shown === whole ? null : whole
  }

  const body = (rows: Array<Record<string, unknown>>, nameOf: (row: Record<string, unknown>) => React.ReactNode) => ({
    rows: rows.map((row) => [
      nameOf(row),
      <span key="spend" dir="ltr">{spendOf(row).text}</span>,
      <span key="impressions" dir="ltr">{compact(numberOf(row, 'impressions'))}</span>,
      <span key="clicks" dir="ltr">{compact(numberOf(row, 'clicks'))}</span>,
      <span key="conversions" dir="ltr">{compact(numberOf(row, 'conversions'))}</span>,
    ]),
    /*
     * The raw figures behind the cells. `spend` is the money reading's own value rather than the
     * row's, so a «—» sorts as an absence — sorting on a number the page refused to print would
     * order the table by a figure the reader cannot see.
     */
    values: rows.map((row): SortValues => [
      String(row.campaign_name ?? row.provider ?? ''),
      spendValue(row),
      numberOf(row, 'impressions'),
      numberOf(row, 'clicks'),
      numberOf(row, 'conversions'),
    ]),
    /*
     * The spend cell is deliberately absent: its text is produced by the money contract, which
     * already decides what may be shown — and a «—» that a tooltip turned back into a number would
     * hand the reader exactly the figure the contract refused to state.
     */
    exact: rows.map((row) => [
      null,
      null,
      full(numberOf(row, 'impressions')),
      full(numberOf(row, 'clicks')),
      full(numberOf(row, 'conversions')),
    ]),
  })

  const head = (first: string) => [first, t.spend, t.impressions, t.clicks, t.results]

  const campaigns = body(payload.campaigns as Array<Record<string, unknown>>, (row) => (
    <span key="name" className="font-semibold">{(row.campaign_name as string | null) ?? '—'}</span>
  ))

  const platforms = body(payload.platforms as Array<Record<string, unknown>>, (row) => (
    <span key="name" className="font-semibold">
      {providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)}
    </span>
  ))

  return (
    <div data-testid="live-detail-tables" className="mt-3 grid gap-3 [&>*]:min-w-0">
      <Section title={t.campaigns} testid="live-detail-campaigns" empty={payload.campaigns.length === 0} none={t.none}>
        <MetricTable head={head(t.campaign)} rows={campaigns.rows} values={campaigns.values} exact={campaigns.exact} initialSort={{ column: 1, dir: 'desc' }} />
      </Section>

      <Section title={t.platforms} testid="live-detail-platforms" empty={payload.platforms.length === 0} none={t.none}>
        <MetricTable head={head(t.platform)} rows={platforms.rows} values={platforms.values} exact={platforms.exact} initialSort={{ column: 1, dir: 'desc' }} />
      </Section>
    </div>
  )
}

function Section({
  title,
  testid,
  empty,
  none,
  children,
}: {
  title: string
  testid: string
  empty: boolean
  none: string
  children: React.ReactNode
}) {
  return (
    <div data-testid={testid} className="min-w-0 overflow-hidden rounded-2xl border border-border bg-surface p-4">
      <h3 className="mb-2 font-bold text-text-primary">{title}</h3>
      {/* An empty section says so. A table with a heading and no rows reads as one that failed. */}
      {empty ? <p className="py-6 text-center text-sm text-text-muted">{none}</p> : children}
    </div>
  )
}
