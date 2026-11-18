import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BudgetVisualDeck, readSpends } from './TabVisualDecks'
import type { BudgetRow, PlatformBudgetRow } from './api'

/** ANALYTICS-VISUAL-DECKS-001 — the decks draw the tab's own rows and never invent a figure. */
describe('tab visual decks', () => {
  it('a row whose spend cannot be stated in the report currency is counted as declined, never summed', () => {
    const rows = [
      { spend: 100 },
      { spend: null, spend_withheld_rows: 3, spend_original: 50, money_original_currency: 'EGP', money_original_currencies: 1 },
    ]
    const { declined, read } = readSpends(rows, (r) => r as never, 'SAR', true)
    expect(declined).toBe(1)
    expect(read[1].amount).toBeNull()
  })

  it('the pace chart draws only campaigns with a measured pace and counts the rest', () => {
    const platforms = [{ provider: 'meta', budget: 1000, budget_currency: 'SAR', spent: 500, consumed_pct: 0.5, pace: 1.3 }] as unknown as PlatformBudgetRow[]
    const campaigns = [
      { campaign_id: 'c1', campaign_name: 'Fast', pace: 1.4 },
      { campaign_id: 'c2', campaign_name: 'Unmeasured', pace: null },
    ] as unknown as BudgetRow[]
    render(<BudgetVisualDeck platforms={platforms} campaigns={campaigns} ar={false} />)
    expect(screen.getByTestId('deck-budget-ring-meta')).toBeInTheDocument()
    expect(screen.getByTestId('deck-budget-pace-row-c1')).toBeInTheDocument()
    expect(screen.queryByTestId('deck-budget-pace-row-c2')).not.toBeInTheDocument()
    expect(screen.getByTestId('deck-budget-unpaced')).toHaveTextContent('1 left off the chart')
  })
})
