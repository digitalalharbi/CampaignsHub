import { formatMoneyReading, readMoney, type MoneyTotals } from '@/lib/money/contract'
import { formatMetric } from './metrics'
import type { CreativeMetrics } from './api'
import type { Locale } from '@/stores/ui'

/**
 * CONTENT-MONEY-VISIBLE-001 — a creative's money, read the way every other surface reads it.
 *
 * ## The defect
 *
 * `creative_daily_metrics` withholds an unconvertible figure exactly as the money contract requires:
 * `spend` is null, `spend_original` holds the real amount and `money_original_currency` names it.
 * On production every Snapchat row is in that state — a USD account with no USD→SAR rate.
 *
 * Content rendered spend through `metricState()` → `formatMetric()`, and that path sees only the
 * CONVERTED column. A null there means «nothing reported», so real, measured spend of 412.50 USD
 * displayed as «No data» or «Not provided».
 *
 * That is the same class of failure as printing «0 SAR», and arguably worse: a zero at least shows
 * that the row exists. «No data» tells the operator their creative never ran.
 *
 * ## What this does
 *
 * Money goes through `readMoney`, the one canonical reader, so a withheld figure prints
 * «412.50 USD» and a converted one prints in the reporting currency. Everything that is NOT money
 * keeps going through `metricState`, which is right for it: that path already distinguishes a
 * measured zero from «not provided» for counts and ratios.
 */
export function creativeMoney(
  metrics: CreativeMetrics | null,
  key: 'spend' | 'revenue',
  currency: string | null,
  locale: Locale,
): { text: string; note: string | null } {
  const ar = locale === 'ar'

  /*
   * A creative with no metrics row at all is a different statement from one whose money was
   * withheld, and `readMoney` cannot tell them apart from an empty object — it would call an absent
   * figure «absent», which is true but loses «this creative has no data at all». So the absence is
   * answered here, by the reader that already knows how to say it.
   */
  if (metrics === null) {
    return { text: formatMetric({ kind: 'no_data' }, key, locale, currency), note: null }
  }

  const reading = readMoney(metrics as MoneyTotals, key, currency, ar)

  return {
    text: formatMoneyReading(reading, (n, c) =>
      formatMetric({ kind: 'value', value: n ?? 0 }, key, locale, c ?? currency)),
    note: reading.note,
  }
}
