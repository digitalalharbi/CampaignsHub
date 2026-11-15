import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
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
  /*
   * ONE-BUDGET-001 — «الميزانية / المتبقي / المتوقع» are the APPROVED project budget's; the campaign budgets set inside
   * the platforms are one informational cell, and their remaining and forecast are never stated as the budget's.
   */
  it('states the approved budget, what is left and where its period is heading', () => {
    render(
      <CampaignSecondaryStrip
        totals={totals()}
        budget={budget}
        currency="SAR"
        paused={0}
        ar={false}
        approved={{
          has_budget: true, project_id: 'p', currency: 'SAR', payment_mode: 'direct', payment_method: 'prepaid', status: 'ok',
          approved: 5069, deposits: 5069, settlements: 0, spent: 4404, remaining: 665, used_ratio: 0.8688, pace_per_day: 100,
          cover_days: 6, run_out_date: null, projected: 4904, period_start: null, period_end: null, thresholds: [50, 75, 90, 100],
          crossed_threshold: 75, complete: true, last_spend_day: null,
        }}
      />,
    )

    expect(screen.getByTestId('campaigns-project-budget').textContent).toContain('5,069')
    expect(screen.getByTestId('campaigns-project-remaining').textContent).toContain('665')
    expect(screen.getByTestId('campaigns-project-forecast').textContent).toContain('4,904')
    expect(screen.getByTestId('campaigns-spent-against-budget').textContent).toContain('4,404')
    // The platforms' campaign budgets: their own labelled cell, and their remaining (4K) is not stated anywhere.
    expect(screen.getByTestId('campaigns-budget-total').textContent).toContain('Campaign budgets in the platforms')
    expect(screen.getByTestId('campaigns-secondary-strip').textContent).not.toContain('4K ر.س')
  })

  it('says «no budget» rather than show a platform figure in its place', () => {
    render(<MemoryRouter><CampaignSecondaryStrip totals={totals()} budget={budget} currency="SAR" paused={0} ar approved={{ has_budget: false }} /></MemoryRouter>)

    expect(screen.getByTestId('campaigns-project-budget').textContent).toContain('بدون ميزانية')
    expect(screen.queryByTestId('campaigns-spent-against-budget')).toBeNull()
  })

  /**
   * A spend in a currency nobody reports in keeps its own — never assumed into the project's.
   *
   * This was the budget card's promise: the platform said 500 $, and the figure a reader checks
   * against the platform has to be 500 $.
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

    expect(screen.getByTestId('campaigns-secondary-strip').textContent ?? '').toContain('500 $')
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
   * CAMPAIGN-BUDGET-TRUTH-001 — the refusal names its actual cause. Seven campaigns with nothing
   * measured in the window are an absence, and «partial or multi-currency» described rows that were
   * not there.
   */
  it('names unmeasured campaigns as the reason, not partial or mixed money', () => {
    render(
      <CampaignSecondaryStrip
        totals={totals()}
        budget={{ ...budget, spent: null, unmeasured: 7, unreadable: 0 }}
        currency="SAR"
        paused={0}
        ar={false}
      />,
    )

    const text = screen.getByTestId('campaigns-budget-total').textContent ?? ''

    expect(text).toContain('7 campaigns with nothing measured')
    expect(text).not.toContain('multi-currency')
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
