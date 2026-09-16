import { formatMoneyReading, readMoney } from '@/lib/money/contract'
import { formatMetric, metricState } from './metrics'
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

  const reading = readMoney(metrics, key, currency, ar)

  return {
    text: formatMoneyReading(reading, (n, c) =>
      formatMetric({ kind: 'value', value: n ?? 0 }, key, locale, c ?? currency)),
    note: reading.note,
  }
}

/**
 * Owner defect 95 — ONE reader for a creative figure, whatever kind of figure it is.
 *
 * ## Why this exists rather than a ternary per call site
 *
 * `creativeMoney` was added for money and `metricState` kept everything else, which is the right
 * split — and it left every surface to remember which was which. Some remembered and some did not,
 * so the same creative's spend read «412.50 USD» on its card and «Not provided» on Content Analytics,
 * in the compare table, and wherever else a key list happened to contain `spend` or `revenue`.
 *
 * Two of those sites had the ternary written inline and correct; the others simply did not have it.
 * A rule enforced by remembering is a rule that holds until the next key list changes, and
 * `headline_metrics` is a key list the SERVER chooses per objective — so which surfaces are exposed
 * changes with the objective, which is the worst possible way for a bug to be distributed.
 *
 * So the question «what does this figure say» has one answer. Money goes through the contract, which
 * knows the four states FX-001 produces; a count or a ratio goes through `metricState`, which is
 * right for it and already tells a measured zero from «not sent».
 */
export function creativeFigureText(
  metrics: CreativeMetrics | null,
  key: string,
  currency: string | null,
  locale: Locale,
): string {
  return key === 'spend' || key === 'revenue'
    ? creativeMoney(metrics, key, currency, locale).text
    : formatMetric(metricState(metrics, key), key, locale, currency)
}
