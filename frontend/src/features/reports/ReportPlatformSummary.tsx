import { compact, moneyExact, moneyFromTotals, percent, ratio } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, readMoney, readRoas, type MoneyTotals } from '@/lib/money/contract'
import { TrendPill } from '@/features/analytics/components'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-DETAIL-PARITY-001 — one platform's own summary, in the detailed report.
 *
 * ## What was missing
 *
 * The comparison table gives every platform one row of seven columns, which is what makes platforms
 * comparable and is deliberately not depth. A detailed report is asked a different question about
 * each platform in turn — what it reached, what that cost, what came back, and whether it is going up
 * or down — and the report had nowhere to answer it. A client reading «Snapchat» in a table had the
 * price of a result and no idea whether last month's was higher.
 *
 * ## Only what the platform reported
 *
 * The metrics are not padded out for symmetry. A platform that reported no revenue has no ROAS row,
 * rather than a «—» in a slot kept open so all four blocks look alike: a dash means «this exists and
 * is unavailable», and printing it where the metric does not apply teaches a reader to read past the
 * ones that matter. Spend and results are always shown, because a platform that spent nothing or
 * produced nothing is a finding and its absence would read as an omission.
 *
 * ## Money through the contract, movement as a ratio
 *
 * Every figure here is a reading, not an arithmetic: a withheld spend is stated in the currency it
 * was recorded in or refused, never converted into this report's, and the cost per result comes from
 * the server's own `cpa` rather than a division that would read a null as zero.
 *
 * `movement` is the platform's own period-over-period ratio, empty where there was no previous
 * window to compare against — a platform that was not running last month did not shrink to nothing.
 */
export function ReportPlatformSummary({
  platform,
  currency,
  locale,
}: {
  platform: Record<string, unknown>
  currency: string
  locale: Locale
}) {
  const ar = locale === 'ar'
  const movement = (platform.movement ?? {}) as Record<string, number | null>

  const num = (key: string): number | null => {
    const v = platform[key]

    return typeof v === 'number' && Number.isFinite(v) ? v : null
  }

  const totals = platform as MoneyTotals
  const spend = moneyFromTotals(totals, 'spend', ar, currency)
  /*
   * Two readings of revenue, and the difference is the point.
   *
   * `moneyFromTotals` is the DISPLAY reading — the text and the figure behind it — and it has no
   * amount to test, because a withheld figure deliberately reads «—». `readMoney` is the contract's
   * own reading and carries the amount, which is what answers «did this platform report revenue at
   * all». Deciding that from the display text would make a withheld revenue look like no revenue.
   */
  const revenue = moneyFromTotals(totals, 'revenue', ar, currency)
  const revenueReading = readMoney(totals, 'revenue', currency, ar)
  const cost = readCostPer(totals, 'cpa', 'conversions', currency, ar)
  const roas = readRoas(totals, ar)

  type Cell = { key: string; label: string; value: string; delta?: number | null; invertGood?: boolean }

  const cells: Cell[] = [
    { key: 'spend', label: ar ? 'الإنفاق' : 'Spend', value: spend.text, delta: movement.spend ?? null },
    { key: 'conversions', label: ar ? 'النتائج' : 'Results', value: compact(num('conversions')), delta: movement.conversions ?? null },
    {
      key: 'cpa',
      label: ar ? 'تكلفة النتيجة' : 'Cost per result',
      value: formatMoneyReading(cost, moneyExact),
      delta: movement.cpa ?? null,
      invertGood: true,
    },
  ]

  /*
   * Reported or not — and a ZERO is not reported, for these rows.
   *
   * `MetricsAggregator::withDerived()` coalesces a missing sum to 0, so an unreported reach and a
   * reach of nobody arrive here as the same number. Production shows which one it really is: this
   * strip printed «الوصول 0» beside 1.26M impressions, and a platform cannot show a million
   * impressions to nobody. The zero was the aggregator's, not Meta's.
   *
   * Dropping the row is the honest reading for an OPTIONAL metric — «measured as nothing» and «never
   * reported» are both absences, which is the rule the objective table already applies to a path
   * nobody spent on. Spend and results are never dropped: there, zero IS the finding, and a missing
   * row would read as an omission rather than as «this platform bought nothing».
   */
  const add = (key: string, label: string, value: string, opts: { delta?: number | null; invertGood?: boolean } = {}) => {
    const v = num(key)
    if (v === null || v === 0) return
    cells.push({ key, label, value, delta: opts.delta ?? null, invertGood: opts.invertGood })
  }

  add('impressions', ar ? 'الظهور' : 'Impressions', compact(num('impressions')), { delta: movement.impressions })
  add('reach', ar ? 'الوصول' : 'Reach', compact(num('reach')), { delta: movement.reach })
  add('clicks', ar ? 'النقرات' : 'Clicks', compact(num('clicks')), { delta: movement.clicks })
  add('ctr', ar ? 'نسبة النقر' : 'CTR', percent(num('ctr'), 2), { delta: movement.ctr })

  /*
   * Revenue and return ride on the REVENUE reading, not on a reported number.
   *
   * `readRoas` refuses a ratio whose two sides sit in different currencies, and a revenue the
   * contract withheld is stated in its own — so «did this platform report revenue» is answered by
   * the reading rather than by whether a key happens to be numeric.
   */
  if (revenueReading.amount !== null && revenueReading.amount > 0) {
    cells.push({ key: 'revenue', label: ar ? 'الإيرادات' : 'Revenue', value: revenue.text, delta: movement.revenue ?? null })

    if (roas.value !== null) {
      cells.push({
        key: 'roas',
        label: ar ? 'العائد على الإنفاق' : 'ROAS',
        /*
         * Through `ratio()`, because a multiplier is «×» and comes from one function.
         *
         * This interpolated the figure and appended a Latin letter for the sign — a second place
         * deciding how a ratio is spelled. `formatGlyphs.test.ts` caught it by sweeping the source
         * tree for exactly that shape, which is the guard doing what it was written to do; the shape
         * is not repeated in this note, because the sweep would find it here too and be right.
         */
        value: ratio(roas.value),
        delta: movement.roas ?? null,
      })
    }
  }

  const share = num('spend_share')
  if (share !== null) {
    // No movement on a share: a share of a different total is not the same quantity twice.
    cells.push({ key: 'spend_share', label: ar ? 'من إجمالي الإنفاق' : 'Share of spend', value: percent(share, 1) })
  }

  return (
    <dl
      data-testid="report-platform-summary"
      className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 lg:grid-cols-4"
    >
      {cells.map((cell) => (
        <div key={cell.key} data-testid={`report-platform-summary-${cell.key}`} className="min-w-0">
          <dt className="text-[11px] text-text-muted">{cell.label}</dt>
          <dd className="flex flex-wrap items-baseline gap-1">
            <span dir="ltr" className="font-semibold tabular-nums text-text-primary">{cell.value}</span>
            {/*
              A movement is shown only where one exists.
              
              `TrendPill` renders nothing for null, and the distinction being preserved is between
              «did not change» and «there was nothing to compare against» — a platform that started
              running this month has no movement, and a 0% pill would say it stood still.
            */}
            {cell.delta !== null && cell.delta !== undefined && (
              <TrendPill delta={cell.delta} invertGood={cell.invertGood} />
            )}
          </dd>
        </div>
      ))}
    </dl>
  )
}
