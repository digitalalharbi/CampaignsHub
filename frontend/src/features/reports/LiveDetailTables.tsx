import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { compact, moneyExact, moneyFromTotals, percent } from '@/features/analytics/format'
import { formatMoneyReading, moneyState, readCostPer, type MoneyTotals } from '@/lib/money/contract'
import { mixedResultsNote } from './reportMetrics'
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
/**
 * The money-reading helpers both tables share.
 *
 * They were declared inside the component, which is why promoting the platform table to a section of
 * its own meant either duplicating them or moving them here. Duplicating them is how two tables of
 * the same figures come to disagree about what a withheld amount reads as.
 */
function moneyHelpers(ar: boolean, currency: string) {
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

  return { numberOf, spendOf, spendValue, full }
}

/**
 * LIVE-CROSS-PLATFORM-001 — every selected platform on one row, and what its money bought.
 *
 * ## What was missing
 *
 * The live page drew spend over time and a share-of-spend donut. Neither answers «which platform did
 * better»: a share of spend says how much went somewhere, not what it bought. The one table that
 * came close lived in `LiveDetailTables` and carried spend, impressions, clicks and results — the
 * four columns that let a reader rank by volume and none that let them rank by price.
 *
 * ## Why it sits here and not in the detailed form
 *
 * It was the detailed form's table, so a client on a dashboard link never saw it, and the owner's
 * contract for this page asks for exactly this comparison as part of the concise view. Both forms
 * carry it now; the detailed form adds the objective decomposition below.
 *
 * ## The two derived columns are the SERVER's
 *
 * `cpa` and `ctr` arrive on every platform row from `MetricsAggregator::withDerived()`. Recomputing
 * them here from spend and conversions was the first attempt and it was a second arithmetic for one
 * figure — the way a page comes to disagree with the export of itself. It also skipped the money
 * contract: a browser-side division reads a WITHHELD spend's null as zero and prints «0», a held
 * amount rendered as free, and over zero results it prints «Infinity» on a client's report.
 *
 * `readCostPer` answers each of those as the contract does, which is not one answer: a spend that is
 * complete but merely unconverted yields a real cost stated in the currency it was RECORDED in, and
 * only a numerator that is partial or spans currencies is refused outright. A link that hides spend
 * never reaches either branch — `ShareService` nulls `cpa` before this sees it.
 *
 * A platform's cost per result averages every objective bought on it — a lead programme's price with
 * a sale's. The sentence under the table says so, because the table cannot say which.
 */
/*
 * Both cost-per columns print the EXACT figure, not the compact one.
 *
 * `money()` runs through `compact()`, which rounds below a thousand: a cost per result of 31.5 came
 * out «32 SAR». On a total that is noise; on a cost-per it is the figure, and `moneyExact`'s own note
 * says so — it exists because a CPM of 29.71 printing «30 SAR» is a different answer, not a rounding.
 *
 * The KPI cards on this same page had already made that call: every cost-per card formats through
 * `moneyExact`. Only these two table cells still compacted, so a client reading «تكلفة النتيجة»
 * twice on one page could be shown two different numbers for it.
 */
export function LivePlatformComparison({
  payload,
  currency,
  locale,
  onOpenPlatform,
}: {
  payload: LivePayload
  currency: string
  locale: Locale
  /**
   * REPORT-DRILLDOWN-001 — opens one platform's drill-down. Absent where the link does not offer it,
   * and never offered on a platform that reported nothing: there is nothing below that row to open.
   */
  onOpenPlatform?: (provider: string) => void
}) {
  const ar = locale === 'ar'
  const t = {
    title: ar ? 'مقارنة أداء المنصات' : 'Platform performance',
    platform: ar ? 'المنصة' : 'Platform',
    none: ar ? 'لا توجد صفوف في هذه الفترة.' : 'No rows in this period.',
    /*
     * The caveat does not promise a table below it.
     *
     * It said «the objective table breaks that apart», and that table is the DETAILED form's — on a
     * dashboard link the sentence pointed at a section the reader does not have.
     */
    blend: ar
      ? 'تكلفة النتيجة لكل منصة هي متوسط كل الأهداف المُشتراة عليها.'
      : 'A platform’s cost per result averages every objective bought on it.',
  }
  const head = [
    t.platform,
    ar ? 'الإنفاق' : 'Spend',
    ar ? 'النتائج' : 'Results',
    ar ? 'تكلفة النتيجة' : 'Cost per result',
    ar ? 'الظهور' : 'Impressions',
    ar ? 'النقرات' : 'Clicks',
    ar ? 'نسبة النقر' : 'CTR',
  ]

  const { numberOf, spendOf, spendValue, full } = moneyHelpers(ar, currency)

  /*
   * Every SELECTED platform gets a row, including the ones that reported nothing.
   *
   * `payload.platforms` carries only the platforms with figures in the window, so a link selecting
   * six published four rows — and the table silently answered a different question: «the platforms
   * that had data», not «the platforms this report covers». A reader cannot tell those apart, and
   * the difference is the whole point of a comparison: a platform that spent nothing is a finding,
   * and one that is simply missing from a list is not even visible as a question.
   *
   * The comment above this section claimed the rows were kept before the code did it. It was written
   * from the intent rather than from the page, and opening the page is what showed the gap.
   *
   * The selected set is what the READER is looking at: the link's own providers, narrowed by any
   * filter they applied. A platform they filtered out is not silent, it is excluded.
   */
  const reported = payload.platforms as Array<Record<string, unknown>>
  const selected = (payload.applied?.providers?.length ?? 0) > 0
    ? (payload.applied?.providers ?? [])
    : (payload.available?.providers ?? [])
  const silent = selected
    .filter((provider) => !reported.some((row) => String(row.provider ?? '') === provider))
    .map((provider) => ({ provider, __silent: true }) as Record<string, unknown>)
  const rows = [...reported, ...silent]

  /*
   * The cost per result, through the contract rather than through a division.
   *
   * `readCostPer` is handed the row's own `conversions` as the denominator so a withheld spend can
   * still be stated in its ORIGINAL currency — the same courtesy the spend column gets — instead of
   * collapsing to «—» the moment a rate is missing.
   */
  const costPer = (row: Record<string, unknown>) =>
    readCostPer(row as MoneyTotals, 'cpa', 'conversions', currency, ar)

  const built = {
    rows: rows.map((row) => [
      <span key="name" className="font-semibold">
        {onOpenPlatform && row.__silent !== true
          ? (
            <button
              type="button"
              data-testid={`live-platform-open-${canonicalPlatform(String(row.provider ?? ''))}`}
              onClick={() => onOpenPlatform(String(row.provider ?? ''))}
              aria-label={ar ? `تفاصيل ${providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)}` : `${providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)} details`}
              className="rounded font-semibold text-brand-600 underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500"
            >
              {providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)}
            </button>
          )
          : providerLabel(canonicalPlatform(String(row.provider ?? '')), locale)}
        {/*
          Said in words, not left to six dashes.
          
          A row of «—» reads as «the report failed to load this» as easily as «this platform reported
          nothing», and those are opposite facts. The marker is what makes it the second one.
        */}
        {row.__silent === true && (
          <span className="ms-1 text-xs font-normal text-text-muted">
            {ar ? '· لم تُبلِّغ عن بيانات' : '· reported nothing'}
          </span>
        )}
      </span>,
      <span key="spend" dir="ltr">{spendOf(row).text}</span>,
      <span key="results" dir="ltr">{compact(numberOf(row, 'conversions'))}</span>,
      <span key="cost" dir="ltr">{formatMoneyReading(costPer(row), moneyExact)}</span>,
      <span key="impressions" dir="ltr">{compact(numberOf(row, 'impressions'))}</span>,
      <span key="clicks" dir="ltr">{compact(numberOf(row, 'clicks'))}</span>,
      <span key="ctr" dir="ltr">{percent(numberOf(row, 'ctr'), 2)}</span>,
    ]),
    values: rows.map((row): SortValues => [
      String(row.provider ?? ''),
      spendValue(row),
      numberOf(row, 'conversions'),
      costPer(row).amount,
      numberOf(row, 'impressions'),
      numberOf(row, 'clicks'),
      numberOf(row, 'ctr'),
    ]),
    exact: rows.map((row) => [
      null,
      spendOf(row).exact,
      full(numberOf(row, 'conversions')),
      null,
      full(numberOf(row, 'impressions')),
      full(numberOf(row, 'clicks')),
      null,
    ]),
  }

  return (
    <div className="mt-3 min-w-0">
      <Section title={t.title} testid="live-platform-comparison" empty={rows.length === 0} none={t.none}>
        <MetricTable
          head={head}
          rows={built.rows}
          values={built.values}
          exact={built.exact}
          initialSort={{ column: 1, dir: 'desc' }}
        />
        <p className="mt-2 text-xs text-text-muted">{t.blend}</p>
      </Section>
    </div>
  )
}

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

  const { numberOf, spendOf, spendValue, full } = moneyHelpers(ar, currency)

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
     * The spend cell reveals its exact amount ONLY when the contract printed one.
     *
     * This was a blanket `null`, and its reason was right about half the cases: a «—» that a tooltip
     * turned back into a number would hand the reader exactly the figure the money contract refused
     * to state. But it protected the withheld case by breaking the shown one. Production printed
     * «10.7K USD» over a real 10,696.54 with nothing to reach it — on the one surface whose reader
     * is the client whose money it is, and beside impressions and clicks that both revealed theirs.
     *
     * The reading decides, as it does for sorting: an amount it stated reveals itself in full, and
     * one it refused stays refused. `exact` is null for a «—», and null when the exact form is the
     * text already on screen.
     */
    exact: rows.map((row) => [
      null,
      spendOf(row).exact,
      full(numberOf(row, 'impressions')),
      full(numberOf(row, 'clicks')),
      full(numberOf(row, 'conversions')),
    ]),
  })

  /*
   * Only the paths money was actually spent on: a path at zero is not a finding, it is an absence.
   *
   * «Spent» reads the money that EXISTS, not the column it survived in. `spend` is the converted
   * figure, and FX-001 leaves it at 0 when no rate existed — so on an account whose money was never
   * converted this filter removed every path and the objective decomposition disappeared from the
   * report entirely. `spendOf` keeps the distinction that matters here: a path nobody ran is still
   * dropped, a path whose money we hold is not.
   */
  const objectiveRows = (payload.objective_performance?.paths ?? []).filter((p) => {
    const { state } = moneyState(p as unknown as MoneyTotals, 'spend')

    // «Nothing reported» and «measured as nothing» are both absences and stay out. Money we HOLD
    // and cannot state is not an absence, and that is the row this filter used to delete.
    return state !== 'absent' && state !== 'zero'
  })

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
        ? formatMoneyReading(readCostPer(row as unknown as MoneyTotals, 'cpa', 'orders', currency, ar), moneyExact)
        : '\u2014'

    /*
     * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — the cost per result above can be an average of prices.
     *
     * Leads, app installs, add to cart, sales, conversions and purchases share the conversion path,
     * so this column could show a lead programme's cost averaged with a sale's — always downwards,
     * because leads are many and cheap. The marker is visible and the sentence is the reason, on the
     * same pattern the funnel already uses for a step that exceeds the one above it: a `title` alone
     * is invisible in print and on a touch screen, and a marker alone explains nothing.
     */
    const blend = (row: (typeof objectiveRows)[number]) =>
      row.cpa_mixes_result_types === true ? mixedResultsNote(row.result_composition, ar) : null

    return {
      rows: base.rows.map((cells, i) => [
        ...cells,
        <span key="cost" dir="ltr">
          {costPer(objectiveRows[i])}
          {blend(objectiveRows[i]) !== null && (
            <span
              data-testid={`objective-${objectiveRows[i].path}-mixed-results`}
              className="ms-1 text-warning"
              title={blend(objectiveRows[i])!.note}
            >
              {ar ? '· مزيج' : '· mixed'}
            </span>
          )}
        </span>,
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
