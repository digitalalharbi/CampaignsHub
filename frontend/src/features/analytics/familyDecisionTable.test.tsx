import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { FamilyDecisionTable } from './FamilyDecisionTable'
import type { FamilyRow } from './familyTotals'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — the objective tab's decision surface.
 *
 * The family cards say how an objective is doing and the bar says where its money sits. Neither
 * settles what the tab is opened to settle: of these eight sales campaigns, which one is worth more
 * money next week. That is a comparison between rows on a column the reader chooses.
 *
 * The rule that makes it safe is structural rather than asserted: one table PER family and never one
 * across them. An awareness campaign and a sales campaign share no metric meaning the same thing in
 * both, so a single sortable list of all of them would rank by whichever objective happens to
 * produce the larger number. There is no such list here to sort.
 */
const SPECS = {
  spend: { label: { ar: 'الإنفاق', en: 'Spend' }, format: (n: number) => n.toLocaleString('en-US') },
  conversions: { label: { ar: 'النتائج', en: 'Results' }, format: (n: number) => n.toLocaleString('en-US') },
  roas: { label: { ar: 'العائد', en: 'ROAS' }, format: (n: number) => `${n}×` },
}

const converted = (name: string, spend: number, conversions: number, revenue = 0): FamilyRow => ({
  campaign_name: name,
  spend,
  revenue,
  conversions,
  spend_original: 0,
  spend_withheld_rows: 0,
  revenue_original: 0,
  revenue_withheld_rows: 0,
  money_original_currencies: 0,
} as unknown as FamilyRow)

/** A campaign the platform reported in another currency, with no rate to convert it. */
const withheld = (name: string, original: number, conversions: number): FamilyRow => ({
  campaign_name: name,
  spend: null,
  revenue: null,
  conversions,
  spend_original: original,
  spend_withheld_rows: 3,
  revenue_original: 0,
  revenue_withheld_rows: 0,
  money_original_currency: 'USD',
  money_original_currencies: 1,
} as unknown as FamilyRow)

function table(campaigns: FamilyRow[], kpis = ['spend', 'conversions']) {
  return render(
    <FamilyDecisionTable
      family="sales"
      campaigns={campaigns}
      kpis={kpis}
      currency="SAR"
      locale="en"
      specs={SPECS}
    />,
  )
}

describe('the decision table inside one objective family', () => {
  it('lists the family’s campaigns under the family’s own columns', () => {
    table([converted('Eid', 3_000, 40), converted('Always-on', 1_000, 5)])

    const grid = within(screen.getByTestId('objective-decision-sales'))

    expect(grid.getByText('Eid')).toBeInTheDocument()
    expect(grid.getByText('Always-on')).toBeInTheDocument()
    expect(grid.getByText('Spend')).toBeInTheDocument()
    expect(grid.getByText('Results')).toBeInTheDocument()
  })

  /** Sortable, because choosing the column IS the decision this surface exists for. */
  it('offers a sort on every column', () => {
    table([converted('Eid', 3_000, 40), converted('Always-on', 1_000, 5)])

    const grid = within(screen.getByTestId('objective-decision-sales'))

    expect(grid.getByTestId('sort-0')).toBeInTheDocument()
    expect(grid.getByTestId('sort-1')).toBeInTheDocument()
    expect(grid.getByTestId('sort-2')).toBeInTheDocument()
  })

  /**
   * A figure the table refused to print may not order it either.
   *
   * A campaign whose spend is awaiting an exchange rate is not the cheapest campaign, and a sort
   * that said so would be the money contract undone by one click. It reads «—» and sorts last.
   */
  it('does not order the table by a figure it would not state', () => {
    table([withheld('Awaiting a rate', 9_999, 10), converted('Eid', 3_000, 40)])

    const rows = within(screen.getByTestId('objective-decision-sales')).getAllByRole('row').slice(1)

    // Descending by spend: the one that can be stated first, the incomparable one last.
    expect(rows[0]).toHaveTextContent('Eid')
    expect(rows[1]).toHaveTextContent('Awaiting a rate')
    expect(rows[1]).toHaveTextContent('—')
  })

  /**
   * One campaign is not a comparison.
   *
   * A single-row sortable table invites a reader to rank something against nothing, and its header
   * controls promise an answer the data cannot give. The family card above already states the
   * totals, so the honest output is nothing.
   */
  it('draws nothing for a family with one campaign', () => {
    const { container } = table([converted('Only one', 3_000, 40)])

    expect(container).toBeEmptyDOMElement()
  })

  /** The columns follow the family, so an awareness family is never given a ROAS column. */
  it('shows only the metrics this family is judged by', () => {
    table([converted('A', 1, 1), converted('B', 2, 2)], ['spend', 'conversions'])

    const grid = within(screen.getByTestId('objective-decision-sales'))

    expect(grid.queryByText('ROAS')).not.toBeInTheDocument()
  })
})
