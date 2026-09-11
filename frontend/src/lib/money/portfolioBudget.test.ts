import { describe, expect, it } from 'vitest'
import { portfolioBudget } from './portfolioBudget'
import type { BudgetRow } from '@/features/analytics/api'

/**
 * CAMPAIGN-INTELLIGENCE-HUB · BUDGET-GOVERNANCE-001 — the portfolio's budget, honestly.
 *
 * The Campaigns overview showed a budget and what had been spent, and stopped there. The three
 * figures an operator actually opens the page for — what is LEFT, where the period ENDS if nothing
 * changes, and whether that is over or under — were computed per campaign by the server and rendered
 * nowhere.
 *
 * ## Ratios are recomputed from totals, never averaged
 *
 * `pace` is `projected / budget` per row. The average of ten paces is not the portfolio's pace: a
 * campaign with a 100 budget running at 3× and one with a 10,000 budget running at 0.9× average to
 * «about 2×» and the account is fine. So the totals are summed and the ratio is taken once.
 *
 * ## What cannot be added is not added, and is counted out loud
 *
 * `pacing_basis` is the server's own verdict on whether a row's figures may be compared at all —
 * currency mismatch, partial spend, no budget. Those rows are excluded from every total and reported
 * as a count, because a budget total that quietly drops a campaign is worse than one that says it.
 */
const row = (over: Partial<BudgetRow>): BudgetRow => ({
  campaign_id: 'c', campaign_name: 'C', status: 'active',
  budget: 0, budget_currency: 'SAR', spent: 0, spent_currency: 'SAR',
  spend_withheld: false, remaining: null, consumed_pct: null, pace: null,
  projected_spend: null, pacing_basis: 'comparable',
  ...over,
} as BudgetRow)

describe('the portfolio budget', () => {
  it('adds what may be added and recomputes the ratio once', () => {
    const b = portfolioBudget([
      row({ budget: 1000, spent: 400, projected_spend: 800 }),
      row({ budget: 3000, spent: 1500, projected_spend: 3600 }),
    ])

    expect(b.budget).toBe(4000)
    expect(b.spent).toBe(1900)
    expect(b.remaining).toBe(2100)
    expect(b.projected).toBe(4400)
    // 4400 / 4000 — NOT the average of 0.8 and 1.2.
    expect(b.pace).toBeCloseTo(1.1, 5)
    expect(b.currency).toBe('SAR')
    expect(b.excluded).toBe(0)
  })

  /** The averaging trap, written as a case so the rule cannot be quietly relaxed. */
  it('does not average the paces', () => {
    const b = portfolioBudget([
      row({ budget: 100, spent: 90, projected_spend: 300, pace: 3 }),
      row({ budget: 10_000, spent: 3000, projected_spend: 9000, pace: 0.9 }),
    ])

    // Averaging would say 1.95 and call the account over. The portfolio ends at 9,300 of 10,100.
    expect(b.pace).toBeCloseTo(9300 / 10100, 5)
    expect(b.pace! < 1).toBe(true)
  })

  /**
   * A row the server refused to compare is refused here too, and counted.
   *
   * `pacing_basis` is that verdict. Adding a currency-mismatched row would produce a number that
   * looks like money and is not, which is the failure the money contract exists to prevent.
   */
  it('excludes what cannot be compared and says how many', () => {
    const b = portfolioBudget([
      row({ budget: 1000, spent: 400, projected_spend: 800 }),
      row({ budget: 500, spent: 200, projected_spend: 400, pacing_basis: 'currency_mismatch' }),
      row({ budget: 700, spent: 100, projected_spend: 200, pacing_basis: 'partial' }),
    ])

    expect(b.budget).toBe(1000)
    expect(b.excluded).toBe(2)
  })

  /** Two currencies among the comparable rows is still not one total. */
  it('refuses a total that would add two currencies', () => {
    const b = portfolioBudget([
      row({ budget: 1000, spent: 400, projected_spend: 800, budget_currency: 'SAR' }),
      row({ budget: 500, spent: 200, projected_spend: 400, budget_currency: 'USD' }),
    ])

    expect(b.budget).toBeNull()
    expect(b.pace).toBeNull()
    expect(b.currencies).toBe(2)
  })

  /**
   * No budget is «we cannot say», not «nothing is planned».
   *
   * An empty scope must not render as a portfolio pacing at 0% — the reading an operator would take
   * as «we have spent nothing», when the truth is that nobody has set a budget to spend against.
   */
  it('says nothing rather than zero when no budget is set', () => {
    const b = portfolioBudget([row({ budget: 0, spent: 400, projected_spend: 800, pacing_basis: 'no_budget' })])

    expect(b.budget).toBeNull()
    expect(b.pace).toBeNull()
    expect(b.remaining).toBeNull()
    expect(b.excluded).toBe(1)
  })

  it('is empty rather than zero for no rows at all', () => {
    const b = portfolioBudget([])

    expect(b.budget).toBeNull()
    expect(b.pace).toBeNull()
  })

  /**
   * A forecast needs a forecast. A row the server could not project is excluded from the projection
   * without poisoning the budget and spend totals, which are still true.
   */
  it('keeps the totals when a row carries no projection', () => {
    const b = portfolioBudget([
      row({ budget: 1000, spent: 400, projected_spend: 800 }),
      row({ budget: 2000, spent: 500, projected_spend: null }),
    ])

    expect(b.budget).toBe(3000)
    expect(b.spent).toBe(900)
    expect(b.projected).toBeNull()
    expect(b.pace).toBeNull()
  })

  /**
   * A stored snapshot from before `pacing_basis` existed still totals.
   *
   * Reading an absent verdict as «not comparable» would empty an old client report of findings it
   * used to make — a silent regression on documents already sent. The refusals are enumerated; an
   * unstated basis on a row with a budget and a spend is two numbers in one currency.
   */
  it('adds rows from a payload that predates the comparability verdict', () => {
    const older = [
      { budget: 1000, spent: 400, projected_spend: 800, budget_currency: 'SAR' },
      { budget: 2000, spent: 500, projected_spend: 1000, budget_currency: 'SAR' },
    ]

    expect(portfolioBudget(older).budget).toBe(3000)
    expect(portfolioBudget(older).excluded).toBe(0)
  })
})
