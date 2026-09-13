import type { MoneyProvenance } from './api'

/**
 * AGGREGATION-TRUTH-001 — the one reader that says what a row's money actually is.
 *
 * The aggregator sends three columns per money metric, not one: the converted `spend`, the
 * `spend_original` it held back, and `spend_withheld_rows` — the count that says the hold was real
 * rather than a sum of nothing. FX-001 withholds a conversion when no rate exists instead of
 * inventing one, so on an account whose rates are unavailable the converted column is legitimately
 * 0 while the money is entirely real.
 *
 * That is not an edge case in this product: `MetricsAggregator` says in its own comments that
 * production's rows are «entirely withheld and entirely USD». A reader that consults the converted
 * column alone therefore reports zero spend for a live account — which is how impressions survive
 * on a surface while Spend disappears from it.
 *
 * This was solved three times in three places before it was named once: `readMoney` for content,
 * `displaySpend` for the dashboard's platform rows, and the provenance fields on the campaign
 * command centre. Those are correct and they are not interchangeable with a fourth. This module is
 * where the rule lives now, for the case the other three never covered: money that drives a
 * DECISION — a threshold, a filter, a ranking, an alert — rather than money being printed.
 */

/** The amount this row honestly represents, whatever column it survived in. */
export function spendOf(row: Partial<MoneyProvenance> & { spend?: number | null }): number {
  const converted = typeof row.spend === 'number' ? row.spend : 0
  if (converted > 0) return converted

  /*
   * Withheld means the sync HELD a real amount and refused to convert it. An original with no
   * withheld rows behind it makes no such claim — zero is what a sum of nothing produces — so both
   * have to be true before the original stands in for the figure.
   */
  const heldRows = row.spend_withheld_rows ?? 0
  const held = row.spend_original ?? 0

  return heldRows > 0 && held > 0 ? Number(held) : converted
}

/**
 * Did this row's money reach us at all?
 *
 * The distinction a decision needs: a campaign that truly spent nothing must not be treated the
 * same as one whose spend could not be converted. The first is a fact about the campaign; the
 * second is a fact about our exchange rates.
 */
export function spendIsWithheld(row: Partial<MoneyProvenance> & { spend?: number | null }): boolean {
  return (row.spend_withheld_rows ?? 0) > 0 && (row.spend_original ?? 0) > 0 && !(typeof row.spend === 'number' && row.spend > 0)
}
