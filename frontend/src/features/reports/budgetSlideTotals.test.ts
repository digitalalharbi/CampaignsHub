import { describe, expect, it } from 'vitest'
import { portfolioBudget } from '@/lib/money/portfolioBudget'
import type { BudgetRow } from '@/features/analytics/api'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the client report's budget ring contradicted its own table.
 *
 * `BudgetSlide` computed «budget consumption» by hand:
 *
 *   const rows = (data.budget ?? []).slice(0, 5)
 *   const totalBudget = rows.reduce((a, b) => a + Number(b.budget ?? 0), 0)
 *   const totalSpent  = rows.reduce((a, b) => a + Number(b.spent ?? 0), 0)
 *
 * Two defects, on a page a CLIENT reads:
 *
 *   - the totals came from a five-row SLICE, so an account running six platforms had a headline
 *     consumption figure computed from five of them, with nothing saying so;
 *   - `Number(null) ?? 0` turned a spend the money contract refused to state — withheld, partial,
 *     two currencies — into a measured ZERO, which reads as «we have spent nothing on that platform»
 *     and pulls the ring down. The pacing table printed directly beneath it already refuses exactly
 *     that, with a dash and a stated reason, so the two halves of one slide disagreed.
 *
 * The rule now comes from `portfolioBudget`, the same one the campaigns overview and the client
 * rollup use. These cases pin the properties the slide depends on.
 */
const row = (over: Partial<BudgetRow>): BudgetRow => ({
  campaign_id: 'p', campaign_name: 'p', status: 'active',
  budget: 1000, budget_currency: 'SAR', spent: 400, spent_currency: 'SAR',
  spend_withheld: false, remaining: 600, consumed_pct: 0.4, pace: 0.8,
  projected_spend: 800, pacing_basis: 'comparable',
  ...over,
} as BudgetRow)

describe('the report’s budget total', () => {
  /** Six platforms are six platforms — a headline may not be computed from five of them. */
  it('counts every platform, not the first five', () => {
    const six = Array.from({ length: 6 }, (_, i) => row({ campaign_id: `p${i}`, budget: 1000, spent: 100 }))

    expect(portfolioBudget(six).budget).toBe(6000)
  })

  /**
   * A spend the contract refused to state is not zero.
   *
   * Counted as zero it reads as «nothing was spent there» and drags the consumption ring down —
   * the opposite of the truth, on the page a client forms their view from.
   */
  it('refuses a platform whose spend has no single figure rather than calling it zero', () => {
    const mixed = [
      row({ budget: 1000, spent: 400 }),
      row({ campaign_id: 'x', budget: 1000, spent: null, pacing_basis: 'mixed_currency' }),
    ]

    const b = portfolioBudget(mixed)

    // The comparable platform alone, and the other one counted out loud.
    expect(b.budget).toBe(1000)
    expect(b.spent).toBe(400)
    expect(b.excluded).toBe(1)
  })

  /** Two currencies refuse the total outright rather than adding riyals to dollars. */
  it('refuses to add two currencies', () => {
    const b = portfolioBudget([row({}), row({ campaign_id: 'u', budget_currency: 'USD' })])

    expect(b.budget).toBeNull()
    expect(b.currencies).toBe(2)
  })
})
