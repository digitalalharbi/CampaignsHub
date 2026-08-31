import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { BudgetReading, type BudgetExplanationPayload } from './BudgetReading'
import { renderWithProviders } from '@/test/utils'

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the reading, and the silences that are part of it.
 *
 * The table under this component is the signal alone. What this adds is the four steps that make it
 * a reading — and, more importantly, what it does when a step does not exist: an action offered
 * without evidence is the product spending somebody's afternoon on its own guess.
 */
const reading = (over: Partial<BudgetExplanationPayload> = {}): BudgetExplanationPayload => ({
  signal: {
    metric: 'pace',
    fastest: { campaign: 'Eid push', value: 1.62 },
    slowest: { campaign: 'Always-on', value: 0.41 },
  },
  context: { scope: 'budget', lines: 4, from: '2026-08-01', to: '2026-08-30' },
  explanation: { ar: 'الإيقاع محسوب على ما مضى', en: 'Pace is measured against the part of the window that has elapsed' },
  evidence: ['budget', 'spent', 'pace'],
  action: { ar: 'قارن', en: 'Check «Eid push» against its plan' },
  silent_reason: null,
  unmeasured_lines: 0,
  ...over,
})

describe('the budget reading', () => {
  it('names both ends of the range and what it was read from', () => {
    renderWithProviders(<BudgetReading reading={reading()} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('budget-reading')).toHaveTextContent('Eid push')
    expect(screen.getByTestId('budget-reading')).toHaveTextContent('1.62×')
    expect(screen.getByTestId('budget-reading')).toHaveTextContent('Always-on')
    expect(screen.getByTestId('budget-reading')).toHaveTextContent(/Read from/)
    expect(screen.getByTestId('budget-reading-action')).toHaveTextContent(/Check «Eid push»/)
  })

  /** No signal, no action — the reason takes its place rather than the product inventing one. */
  it('offers no action where there is no range, and says which silence it is', () => {
    renderWithProviders(
      <BudgetReading reading={reading({ signal: null, action: null, silent_reason: 'only_one_line_has_a_pace' })} locale="en" />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('budget-reading-action')).not.toBeInTheDocument()
    expect(screen.getByTestId('budget-reading-silent')).toHaveTextContent(/Only one line could be paced/)
  })

  /** «Nothing could be paced» is a different sentence from «nobody set a budget». */
  it('separates a withheld window from an unbudgeted one', () => {
    const { unmount } = renderWithProviders(
      <BudgetReading reading={reading({ signal: null, silent_reason: 'no_line_could_be_paced' })} locale="en" />,
      { locale: 'en' },
    )
    expect(screen.getByTestId('budget-reading-silent')).toHaveTextContent(/withheld, or it is in a different currency/)
    unmount()

    renderWithProviders(
      <BudgetReading reading={reading({ signal: null, silent_reason: 'no_budgets_set' })} locale="en" />,
      { locale: 'en' },
    )
    expect(screen.getByTestId('budget-reading-silent')).toHaveTextContent(/has a budget set/)
  })

  /** A reading over four of nine lines is exactly that, and says so even when it has a range. */
  it('states how much of the page it did not cover', () => {
    renderWithProviders(<BudgetReading reading={reading({ unmeasured_lines: 5 })} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('budget-reading-unmeasured')).toHaveTextContent(/5 line\(s\) could not be paced/)
  })
})
