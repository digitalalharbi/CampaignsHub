import type { PlatformRow } from '@/features/analytics/api'

/**
 * DASH-PLATFORM-MONEY-001 — what a platform row can honestly show as its spend.
 *
 * The summary card read «4,768.84 USD» and the platform comparison directly beneath it read
 * «0 SAR», with an empty spend donut and a flat spend/revenue chart. One project, one window, two
 * answers — and no way for the reader to know which to believe.
 *
 * The cause was a view model that read `p.spend` — the coalesced 0 — while `PlatformRow` has always
 * extended `MoneyProvenance` and the aggregator has always sent `spend_original`,
 * `spend_withheld_rows` and `money_original_currency` per provider. The provenance was on the wire
 * and thrown away at the last step.
 *
 * FX-001 withholds a converted figure when no rate exists rather than inventing one, so `spend` is
 * legitimately 0 on this account. What was not legitimate was printing that 0 under a currency the
 * account does not report in.
 */
export function displaySpend(row: Pick<PlatformRow, 'spend' | 'spend_original' | 'spend_withheld_rows'>): number {
  if (typeof row.spend === 'number' && row.spend > 0) return row.spend

  // Withheld means the sync HELD a real amount and refused to convert it. A summed original with no
  // withheld rows behind it makes no such claim, and zero is what a sum of nothing produces.
  const withheld = (row.spend_withheld_rows ?? 0) > 0 && (row.spend_original ?? 0) > 0

  return withheld ? Number(row.spend_original) : (row.spend ?? 0)
}

/**
 * The currency the comparison is actually denominated in, or null when there is no single answer.
 *
 * When every withheld row shares one original currency, that currency IS the figure's currency —
 * and it is what the summary card above already prints. A mixture has no single name, so the caller
 * keeps the project's own currency rather than summing several under a label that fits none.
 */
export function withheldCurrencyOf(
  rows: ReadonlyArray<Pick<PlatformRow, 'spend_withheld_rows' | 'money_original_currency'>>,
): string | null {
  const names = new Set(
    rows
      .filter((r) => (r.spend_withheld_rows ?? 0) > 0 && typeof r.money_original_currency === 'string')
      .map((r) => r.money_original_currency as string),
  )

  return names.size === 1 ? [...names][0] : null
}
