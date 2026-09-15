import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CampaignSecondaryStrip } from './CampaignSecondaryStrip'
import type { MoneyTotals } from '@/lib/money/contract'

/**
 * VISUAL-DECISION-001 — the guarantees moved with the figures, and are proved where they landed.
 *
 * Budget, remaining, forecast, cost per result and ROAS were five oversized KPI cards, each
 * carrying a money-contract promise under test. The cards became one compact row; none of the
 * promises did. Every one of them is asserted here, on the component that now makes it.
 */
const budget = {
  total: 5000,
  spent: 1000,
  remaining: 4000,
  projected: 1200,
  currency: 'SAR',
  spentCurrency: 'SAR',
  currencyCount: 1,
  known: true,
}

const totals = (over: Partial<MoneyTotals> = {}): MoneyTotals => ({ spend: 1000, revenue: 4000, conversions: 50, ...over } as MoneyTotals)

describe('the campaigns secondary strip', () => {
  it('states the budget, what is left and where the period is heading', () => {
    render(<CampaignSecondaryStrip totals={totals()} budget={budget} currency="SAR" paused={0} ar={false} />)

    const text = screen.getByTestId('campaigns-secondary-strip').textContent ?? ''

    expect(text).toContain('5K SAR')
    expect(text).toContain('4K SAR')
    expect(text).toContain('1.2K SAR')
  })

  /**
   * A spend in a currency nobody reports in keeps its own — never assumed into the project's.
   *
   * This was the budget card's promise: the platform said 500 USD, and the figure a reader checks
   * against the platform has to be 500 USD.
   */
  it('keeps the platform figure in the currency it was spent in', () => {
    render(
      <CampaignSecondaryStrip
        totals={totals()}
        budget={{ ...budget, spent: 500, spentCurrency: 'USD' }}
        currency="SAR"
        paused={0}
        ar={false}
      />,
    )

    expect(screen.getByTestId('campaigns-secondary-strip').textContent ?? '').toContain('500 USD')
  })

  /** Budgets in two currencies do not add, and the strip says so rather than showing a total. */
  it('refuses a budget total across currencies and gives the reason', () => {
    render(
      <CampaignSecondaryStrip
        totals={totals()}
        budget={{ ...budget, currencyCount: 2 }}
        currency="SAR"
        paused={0}
        ar={false}
      />,
    )

    const text = screen.getByTestId('campaigns-budget-total').textContent ?? ''

    expect(text).toContain('2 currencies')
    expect(text).toContain('not summed')
  })

  /** A spend that cannot be formed is «—» with its reason, never a zero. */
  it('says why there is no single spend figure instead of printing one', () => {
    render(
      <CampaignSecondaryStrip
        totals={totals()}
        budget={{ ...budget, spent: null }}
        currency="SAR"
        paused={0}
        ar={false}
      />,
    )

    const text = screen.getByTestId('campaigns-budget-total').textContent ?? ''

    expect(text).toContain('Spend unavailable')
    expect(text).not.toMatch(/\b0\b/)
  })

  /**
   * An unreported result is «—», and a reported zero is `0`. The two are different claims and the
   * strip must not collapse them — a cost per nothing is not a cost of nothing.
   */
  it('tells an unreported figure from a reported zero', () => {
    const { rerender } = render(
      <CampaignSecondaryStrip totals={totals({ conversions: null } as Partial<MoneyTotals>)} budget={budget} currency="SAR" paused={0} ar={false} />,
    )

    expect(screen.getByTestId('campaigns-cost-per-result').textContent ?? '').toContain('—')

    /*
     * `roas` is a field the row carries, not something derived here — the contract reads what the
     * platform reported rather than dividing two figures that may not be comparable. So a REPORTED
     * zero is `roas: 0`, and the absence of the key is «not reported», which is the distinction.
     */
    rerender(<CampaignSecondaryStrip totals={totals({ roas: 0 } as Partial<MoneyTotals>)} budget={budget} currency="SAR" paused={0} ar={false} />)

    expect(screen.getByTestId('campaigns-roas').textContent ?? '').toContain('0.00×')
  })

  /** «Need a look» under a zero asserted both that nothing needed reviewing and that it did. */
  it('does not say paused campaigns need a look when none are paused', () => {
    const { rerender } = render(<CampaignSecondaryStrip totals={totals()} budget={budget} currency="SAR" paused={0} ar={false} />)

    expect(screen.getByTestId('campaigns-paused').textContent ?? '').not.toContain('Need a look')

    rerender(<CampaignSecondaryStrip totals={totals()} budget={budget} currency="SAR" paused={3} ar={false} />)

    expect(screen.getByTestId('campaigns-paused').textContent ?? '').toContain('Need a look')
  })
})
