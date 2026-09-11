import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody } from './InteractiveReport'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the budget ring contradicted the table printed beneath it.
 *
 * `BudgetSlide` computed the headline from `(data.budget ?? []).slice(0, 5)` and coerced a null
 * spend to zero. On a page a CLIENT reads, that meant an account running six platforms had a
 * consumption figure computed from five, and a spend the money contract refused to state counted as
 * «nothing was spent there». The pacing table directly below already refuses both, with a dash and a
 * stated reason — so one slide disagreed with itself.
 */
const meta = { reportName: 'R', platforms: [] }
const slide = { id: 's', type: 'budget', order: 1, visible: true } as never

const platform = (over: Record<string, unknown>) => ({
  provider: 'meta', budget: 1000, budget_currency: 'SAR', spent: 400, spent_currency: 'SAR',
  spend_withheld: false, remaining: 600, consumed_pct: 0.4, pace: 0.8,
  projected_spend: 800, pacing_basis: 'comparable', refusal: null,
  ...over,
})

const data = (budget: unknown[]) => ({ currency: 'SAR', budget } as never)

describe('the client report’s budget slide', () => {
  /** Six platforms are six platforms — a headline may not be computed from five of them. */
  it('counts every platform in the ring, not the first five', () => {
    const six = Array.from({ length: 6 }, (_, i) => platform({ provider: `p${i}`, budget: 1000, spent: 500 }))

    render(<SlideBody slide={slide} data={data(six)} meta={meta} />)

    /*
     * The ring's own sublabel: 3,000 spent of 6,000 planned. A five-row slice reads 2.5K / 5K, so
     * this is the one string that distinguishes the two — asserted rather than the bare numbers,
     * which appear all over the slide.
     */
    expect(screen.getByText(/3(\.0)?K \/ 6(\.0)?K/)).toBeTruthy()
  })

  /**
   * A platform whose spend has no single figure is excluded and COUNTED, never called zero.
   *
   * Counted as zero it reads as «nothing was spent there» and drags the ring down — the opposite of
   * the truth, on the page a client forms their view from.
   */
  it('says how many platforms it left out', () => {
    render(<SlideBody slide={slide} data={data([
      platform({}),
      platform({ provider: 'snapchat', spent: null, pacing_basis: 'mixed_currency' }),
    ])} meta={meta} />)

    expect(screen.getByTestId('report-budget-excluded')).toHaveTextContent('1')
  })

  /** A total that cannot be stated says so — a ring at 0% would be read as «nothing spent». */
  it('refuses a total it cannot compute rather than drawing zero', () => {
    render(<SlideBody slide={slide} data={data([
      platform({ budget_currency: 'SAR' }),
      platform({ provider: 'snapchat', budget_currency: 'USD' }),
    ])} meta={meta} />)

    expect(screen.getByTestId('report-budget-unavailable')).toHaveTextContent(/عملات/)
  })
})
