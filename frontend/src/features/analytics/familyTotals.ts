/**
 * OBJECTIVE-TOTALS-001 — a rate is recomputed from the totals, never summed.
 *
 * The Objectives tab totalled every KPI with one reduce: `campaigns.reduce((t, r) => t + r[k])`.
 * That is correct for a count and meaningless for everything else, and every family's list contains
 * a rate — `frequency`, `cpm`, `ctr`, `cpc`, `engagement_rate`, `conversion_rate`, `roas`. Two sales
 * campaigns returning 3× and 5× printed «8», which is not a return anybody earned; two campaigns at
 * 1.2% CTR printed «2.4%».
 *
 * This is the rule `MetricsAggregator::withDerived()` already states on the server — «derived KPIs
 * (computed from the sums, never summed)» — applied to the one place that re-aggregated on the
 * client and did not follow it.
 *
 * Money goes through the money contract for the same reason it does everywhere else: `spend` on a
 * withheld row is the aggregator's coalesced 0, so summing it gives a confident zero. Summing the
 * rows' `spend_original` and `spend_withheld_rows` instead produces a family-level payload the
 * contract can read, which is what makes «4,803.17 USD» reachable here as well as on the strip.
 */
import type { MoneyFields } from '@/lib/money/contract'

/** A campaign row as the breakdown returns it. */
export type FamilyRow = MoneyFields & Record<string, unknown>

/** The base measurements a rate can be rebuilt from. Anything not here is a count and simply sums. */
const NUMERATOR_DENOMINATOR: Record<string, { over: string; under: string; scale?: number }> = {
  frequency: { over: 'impressions', under: 'reach' },
  ctr: { over: 'clicks', under: 'impressions' },
  cpc: { over: 'spend', under: 'clicks' },
  cpm: { over: 'spend', under: 'impressions', scale: 1000 },
  cpa: { over: 'spend', under: 'conversions' },
  cpl: { over: 'spend', under: 'leads' },
  cpi: { over: 'spend', under: 'installs' },
  cpe: { over: 'spend', under: 'engagements' },
  roas: { over: 'revenue', under: 'spend' },
  aov: { over: 'revenue', under: 'purchases' },
  conversion_rate: { over: 'conversions', under: 'clicks' },
  engagement_rate: { over: 'engagements', under: 'impressions' },
  video_completion_rate: { over: 'video_completions', under: 'video_views' },
}

/** Whether this key is a rate rather than a quantity — exported so callers can format accordingly. */
export function isDerived(key: string): boolean {
  return key in NUMERATOR_DENOMINATOR
}

const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)

/**
 * Sum one base metric across the family.
 *
 * Null when NO row reported it — «no platform sends this» is not «the platforms sent zero», which is
 * the distinction `FUNNEL-NULL-001` protects on the server and the reason this does not seed at 0.
 */
export function sumBase(rows: FamilyRow[], key: string): number | null {
  let total: number | null = null

  for (const row of rows) {
    const v = num(row[key])
    if (v === null) continue
    total = (total ?? 0) + v
  }

  return total
}

/**
 * The family's money, as a payload the money contract can read.
 *
 * Withheld rows carry their original and their count; both sum. The currency is kept only when the
 * whole family agrees on one — a ratio or a total across two currencies is not a figure.
 */
export function familyMoney(rows: FamilyRow[]): MoneyFields {
  const currencies = new Set<string>()

  for (const row of rows) {
    const c = row.money_original_currency
    if (typeof c === 'string' && c.trim() !== '') currencies.add(c)
  }

  return {
    spend: sumBase(rows, 'spend'),
    revenue: sumBase(rows, 'revenue'),
    spend_original: sumBase(rows, 'spend_original') ?? 0,
    spend_withheld_rows: sumBase(rows, 'spend_withheld_rows') ?? 0,
    revenue_original: sumBase(rows, 'revenue_original') ?? 0,
    revenue_withheld_rows: sumBase(rows, 'revenue_withheld_rows') ?? 0,
    money_original_currency: currencies.size === 1 ? [...currencies][0] : null,
    money_original_currencies: currencies.size,
  }
}

/**
 * One KPI for the family: summed when it is a count, rebuilt from the sums when it is a rate.
 *
 * Returns null when the rate's denominator is missing or zero — a division nobody can perform is not
 * a result of zero.
 */
export function familyTotal(rows: FamilyRow[], key: string): number | null {
  const rate = NUMERATOR_DENOMINATOR[key]
  if (!rate) return sumBase(rows, key)

  const money = familyMoney(rows)

  /*
   * A money base reads through the contract, so a withheld original stands in for the coalesced 0.
   * The quotient is then in the original currency — and for a RATIO like ROAS, identical to what it
   * would be after conversion, which is why it survives a missing rate at all.
   */
  const base = (name: string): number | null => {
    if (name === 'spend' || name === 'revenue') {
      const converted = money[name]
      if (typeof converted === 'number' && converted > 0) return converted

      const withheld = Number(money[`${name}_withheld_rows` as keyof MoneyFields] ?? 0)
      const original = Number(money[`${name}_original` as keyof MoneyFields] ?? 0)
      if (withheld > 0 && original > 0 && money.money_original_currencies === 1) return original

      return converted ?? null
    }

    return sumBase(rows, name)
  }

  const over = base(rate.over)
  const under = base(rate.under)

  if (over === null || under === null || under === 0) return null

  return (over / under) * (rate.scale ?? 1)
}
