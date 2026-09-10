import type { BudgetRow } from '@/features/analytics/api'

/**
 * BUDGET-GOVERNANCE-001 — the portfolio's budget, from the rows the server already vouched for.
 *
 * The Campaigns overview showed a budget and what had been spent. The three figures an operator
 * opens the page for — what is LEFT, where the period ENDS if nothing changes, and whether that is
 * over or under — were computed per campaign by `MetricsAggregator::budgetPacing()` and rendered
 * nowhere.
 *
 * ## The definitions are the server's, not new ones
 *
 * `projected_spend` is `spent / elapsedFraction`: a straight-line forecast of the whole period.
 * `pace` is `projected / budget`, so 1.0 lands exactly on budget and 1.2 overruns by a fifth. This
 * carries both meanings up to the portfolio unchanged rather than inventing a second arithmetic that
 * would drift from the per-campaign figures on the same screen.
 *
 * ## Ratios are recomputed from totals, never averaged
 *
 * The average of ten paces is not the portfolio's pace: a 100 budget running at 3× beside a 10,000
 * budget running at 0.9× averages to «about 2×» while the account is comfortably fine. Totals are
 * summed, and the ratio is taken once at the end.
 *
 * ## What cannot be compared is excluded and COUNTED
 *
 * `pacing_basis` is the server's own verdict on whether a row's figures may be compared at all. A
 * total that quietly drops a currency-mismatched campaign is worse than one that says it did, so the
 * count travels with the numbers and the interface states it.
 */
export interface PortfolioBudget {
  /** Null when nothing comparable was found — «we cannot say», never zero. */
  budget: number | null
  spent: number | null
  remaining: number | null
  /** Where the period ends at the current rate, or null when any comparable row lacks a projection. */
  projected: number | null
  /** `projected / budget`. 1.0 lands on budget; above it overruns. */
  pace: number | null
  currency: string | null
  /** How many comparable rows disagreed about currency — a total is refused above one. */
  currencies: number
  /** Rows the server refused to compare, or that carry no budget. Stated, never dropped in silence. */
  excluded: number
}

const EMPTY: PortfolioBudget = {
  budget: null, spent: null, remaining: null, projected: null,
  pace: null, currency: null, currencies: 0, excluded: 0,
}

export function portfolioBudget(rows: BudgetRow[]): PortfolioBudget {
  /*
   * `comparable` is the only basis that may be added. The other four each name a reason the row's
   * own figures are not a like-for-like quantity — a currency mismatch, a partial spend, a budget
   * nobody set — and adding any of them produces a number that looks like money and is not.
   */
  const usable = rows.filter((r) => r.pacing_basis === 'comparable' && r.budget > 0)
  const excluded = rows.length - usable.length

  if (usable.length === 0) return { ...EMPTY, excluded }

  const currencies = new Set(
    usable.map((r) => (r.budget_currency ?? '').toUpperCase()).filter((c) => c !== ''),
  )

  if (currencies.size > 1) {
    return { ...EMPTY, currencies: currencies.size, excluded }
  }

  const budget = usable.reduce((a, r) => a + r.budget, 0)
  const spent = usable.reduce((a, r) => a + (r.spent ?? 0), 0)

  /*
   * A forecast of the sum needs every part forecast. One row the server could not project makes the
   * portfolio projection unknowable — but it says nothing about the budget and spend beside it,
   * which are still true and are still reported.
   */
  const projectable = usable.every((r) => typeof r.projected_spend === 'number')
  const projected = projectable ? usable.reduce((a, r) => a + (r.projected_spend ?? 0), 0) : null

  return {
    budget: round(budget),
    spent: round(spent),
    remaining: round(budget - spent),
    projected: projected === null ? null : round(projected),
    pace: projected === null || budget <= 0 ? null : projected / budget,
    currency: [...currencies][0] ?? null,
    currencies: currencies.size,
    excluded,
  }
}

const round = (n: number): number => Math.round(n * 100) / 100
