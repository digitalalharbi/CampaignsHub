import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody } from './InteractiveReport'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — a withheld spend was drawn as a bar of length zero.
 *
 * The «plan vs spend» chart coerced `r.spent ?? 0`, so a platform whose spend the money contract
 * refuses to state — withheld, partial, a second currency — got a full-height budget bar beside an
 * empty spend bar. A reader takes that pairing as «budgeted, spent nothing», which is the same claim
 * the ring above it stopped making. A row the chart cannot draw truthfully is left out and COUNTED.
 */
const slide = (budget: unknown[]) =>
  render(<SlideBody slide={{ type: 'budget' } as never} data={{ budget, currency: 'SAR' } as never} meta={{ reportName: 'R', platforms: [] } as never} />)

describe('the plan-vs-spend chart', () => {
  it('leaves out a platform whose spend is withheld, and says how many', () => {
    slide([
      { provider: 'meta', budget: 10000, spent: 6000, projected_spend: 9000, pacing_basis: 'comparable', budget_currency: 'SAR' },
      { provider: 'tiktok', budget: 5000, spent: null, projected_spend: null, pacing_basis: 'partial', budget_currency: 'SAR' },
    ])

    expect(screen.getByTestId('report-bars-excluded').textContent).toMatch(/1/)
  })

  it('says nothing about exclusions when every platform can be drawn', () => {
    slide([
      { provider: 'meta', budget: 10000, spent: 6000, projected_spend: 9000, pacing_basis: 'comparable', budget_currency: 'SAR' },
    ])

    expect(screen.queryByTestId('report-bars-excluded')).toBeNull()
  })
})
