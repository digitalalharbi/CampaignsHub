import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BudgetPacingRow } from './CampaignsPage'
import type { BudgetRow } from '@/features/analytics/api'

/**
 * BUDGET-GOVERNANCE-001 — the reading, beside the figures.
 *
 * `portfolioBudget` owns the arithmetic and has its own tests; this holds what the operator is
 * actually told. The sentence names the overrun in MONEY: «will exceed the budget by 400 SAR» is
 * something to act on, and «1.1×» is a ratio somebody has to convert first.
 *
 * Rendered directly rather than through `CampaignsPage`, which needs a chosen project, a portal and
 * a live metrics client to reach its overview at all — three things that would make this a test of
 * the page's plumbing rather than of what the row says.
 */
const row = (over: Partial<BudgetRow>): BudgetRow => ({
  campaign_id: 'c', campaign_name: 'C', status: 'active',
  budget: 1000, budget_currency: 'SAR', spent: 400, spent_currency: 'SAR',
  spend_withheld: false, remaining: 600, consumed_pct: 0.4, pace: 0.8,
  projected_spend: 800, pacing_basis: 'comparable',
  ...over,
} as BudgetRow)

describe('the portfolio budget row', () => {
  it('shows what is left and where the period ends', () => {
    render(<BudgetPacingRow ar={false} rows={[row({}), row({ campaign_id: 'c2', budget: 3000, spent: 1500, projected_spend: 3600 })]} />)

    const pacing = screen.getByTestId('budget-pacing')
    expect(pacing).toHaveTextContent('Remaining')
    expect(pacing).toHaveTextContent('Forecast')
    /* 4,400 forecast against 4,000 budget — named in money, not as a ratio. */
    expect(screen.getByTestId('budget-pacing-reading')).toHaveTextContent(/exceed the budget by/i)
    expect(screen.getByTestId('budget-pacing-reading')).toHaveTextContent(/400/)
  })

  it('says it is within budget when the forecast lands under', () => {
    render(<BudgetPacingRow ar={false} rows={[row({})]} />)

    expect(screen.getByTestId('budget-pacing-reading')).toHaveTextContent(/within budget/i)
  })

  /**
   * A scope nothing can be added across says so, and never reads as a budget of zero.
   *
   * «0%» here is what an operator takes as «we have spent nothing», when the truth is that no
   * campaign in view carries a comparable budget at all.
   */
  it('refuses a total it cannot compute, and says why', () => {
    render(<BudgetPacingRow ar={false} rows={[row({ pacing_basis: 'currency_mismatch' }), row({ campaign_id: 'c2', pacing_basis: 'partial' })]} />)

    const note = screen.getByTestId('budget-pacing-unavailable')
    expect(note).toHaveTextContent(/no comparable budget/i)
    expect(note).toHaveTextContent('2')
    expect(screen.queryByTestId('budget-pacing')).toBeNull()
  })

  /** Two currencies is a different refusal from no budget, and says which. */
  it('names a currency clash rather than calling it missing', () => {
    render(<BudgetPacingRow ar={false} rows={[row({}), row({ campaign_id: 'c2', budget_currency: 'USD' })]} />)

    expect(screen.getByTestId('budget-pacing-unavailable')).toHaveTextContent(/different currencies/i)
  })

  /** A campaign left out of the total is counted where the total is read — no silent caps. */
  it('counts the campaigns it left out', () => {
    render(<BudgetPacingRow ar={false} rows={[row({}), row({ campaign_id: 'c2', pacing_basis: 'no_budget', budget: 0 })]} />)

    /* «1 campaign», not «1 campaigns» — the noun agrees through . */
    expect(screen.getByTestId('budget-pacing-excluded')).toHaveTextContent(/1 campaign excluded/i)
  })

  /** Nothing at all renders nothing — an empty project is not a budget of zero. */
  it('renders nothing when there is no budget data at all', () => {
    const { container } = render(<BudgetPacingRow ar={false} rows={[]} />)

    expect(container).toBeEmptyDOMElement()
  })
})
