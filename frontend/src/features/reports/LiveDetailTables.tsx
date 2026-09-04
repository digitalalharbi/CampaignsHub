import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { compact, money, moneyFromTotals } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, type MoneyTotals } from '@/lib/money/contract'
import type { LivePayload } from './api'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-PRODUCT-MODEL-001 §D — what makes a LIVE report detailed rather than a dashboard with a
 * longer label.
 *
 * ## The defect
 *
 * A live link whose form is `detailed` rendered the dashboard: a spend chart, a platform donut and
 * a top-EIGHT bar. Above it the page printed a sentence promising the whole window. A client who
 * counted the bars found eight rows where the sentence promised all of them, and no way to see the
 * ninth. The label was not wrong about what the product can do; it was wrong about what that page
 * was.
 *
 * So the detailed form gets what the sentence says: the whole window, as tables, ordered by whatever
 * column the reader picks.
 *
 * ## What «detailed» means, after CLIENT-REPORT-ENTITY-BOUNDARY-001
 *
 * It used to mean a campaign table and an ad-set table — the agency's own campaign names, and below
 * them the targeting plan («توسيع الجمهور», «إعادة الاستهداف») one rung down. That is not depth about
 * the client's advertising; it is the internal arrangement of it, and the owner asked for it out:
 * «اسم واختيار الحملة احذفه من التقارير».
 *
 * Detail is now depth on the axes a client reads: every PLATFORM, and every OBJECTIVE with the cost
 * per result each one is actually judged on. A reader learns more from «التحويل والمبيعات: 42,000
 * SAR, 180 طلبًا, 233 SAR للطلب» than from a list of campaign names, and it is theirs to act on.
 * The operator's own drill-down keeps Campaign → Ad Set → Ad → Content, untouched.
 *
 * ## No invented figure
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
    platforms: ar ? 'كل المنصات' : 'Every platform',
    objectives: ar ? 'كل هدف' : 'Every objective',
    platform: ar ? 'المنصة' : 'Platform',
    objective: ar ? 'الهدف' : 'Objective',
    spend: ar ? 'الإنفاق' : 'Spend',
    impressions: ar ? 'الظهور' : 'Impressions',
    clicks: ar ? 'النقرات' : 'Clicks',
    results: ar ? 'النتائج' : 'Results',
    costPerResult: ar ? 'تكلفة النتيجة' : 'Cost per result',
    none: ar ? 'لا توجد صفوف في هذه الفترة.' : 'No rows in this period.',
    noObjectives: ar
      ? 'لم يُنفَق على أي هدف في هذه الفترة.'
      : 'Nothing was spent on any objective in this window.',
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
      String(row.provider ?? row.path ?? ''),
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

  const platforms = body(payload.platforms as Array<Record<string, unknown>>, (row) => (
    <span key="name" className="font-semibold">
      {providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)}
    </span>
  ))

  /* Only the paths money was actually spent on: a path at zero is not a finding, it is an absence. */
  const objectiveRows = (payload.objective_performance?.paths ?? []).filter((p) => p.spend > 0)

  const objectives = (() => {
    /*
     * A path's result is its ORDERS — the payload names it that, and `body()` reads `conversions`.
     * Mapping it here rather than teaching `body()` a second key keeps one shape flowing through it.
     */
    const asRows = objectiveRows.map((p) => ({ ...p, conversions: p.orders }))
    const base = body(asRows as unknown as Array<Record<string, unknown>>, (row) => (
      <span key="name" className="font-semibold">{ar ? String(row.label_ar) : String(row.label_en)}</span>
    ))

    /*
     * The sixth column, appended rather than folded into `body()`: a platform has no cost per result
     * to state, because a platform is not bought for one thing. A path is — and only a path that was
     * bought for a result at all, which `result_metrics_apply` is the payload's own flag for. «—» in
     * this cell is the report declining to rank an awareness path on a sales metric, not a gap.
     */
    const costPer = (row: (typeof objectiveRows)[number]) =>
      row.result_metrics_apply
        ? formatMoneyReading(readCostPer(row as unknown as MoneyTotals, 'cpa', 'orders', currency, ar), money)
        : '\u2014'

    return {
      rows: base.rows.map((cells, i) => [
        ...cells,
        <span key="cost" dir="ltr">{costPer(objectiveRows[i])}</span>,
      ]),
      values: base.values.map((v, i): SortValues => [
        ...v,
        objectiveRows[i].result_metrics_apply ? objectiveRows[i].cpa : null,
      ]),
      exact: base.exact.map((e) => [...e, null]),
    }
  })()

  return (
    <div data-testid="live-detail-tables" className="mt-3 grid gap-3 [&>*]:min-w-0">
      <Section title={t.platforms} testid="live-detail-platforms" empty={payload.platforms.length === 0} none={t.none}>
        <MetricTable head={head(t.platform)} rows={platforms.rows} values={platforms.values} exact={platforms.exact} initialSort={{ column: 1, dir: 'desc' }} />
      </Section>

      {/*
        REPORT-OBJECTIVE-003/004 in the detailed form — the axis a client's money is actually judged on.

        A platform table answers «where did it go». This answers «what was it bought for, and what did
        that cost» — and the cost per result is the PATH's own, so an awareness path is never given a
        cost per order it was never asked to produce. `result_metrics_apply` is the payload's own flag
        for that, and a «—» here is the report declining to rank a brand campaign on a sales metric.
      */}
      <Section
        title={t.objectives}
        testid="live-detail-objectives"
        empty={objectiveRows.length === 0}
        none={t.noObjectives}
      >
        <MetricTable
          head={[t.objective, t.spend, t.impressions, t.clicks, t.results, t.costPerResult]}
          rows={objectives.rows}
          values={objectives.values}
          exact={objectives.exact}
          initialSort={{ column: 1, dir: 'desc' }}
        />
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
