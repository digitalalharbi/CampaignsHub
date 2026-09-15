import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SpendEfficiencyScatter } from './charts'

/**
 * VISUAL-DECISION-001 — a point needs both coordinates, and a missing one is not a zero.
 *
 * The chart answers «where is money going that is not working», which no single ranking can: a
 * campaign high on spend AND high on cost per result is the one to open first, and it can sit
 * mid-table on both lists.
 *
 * The risk it carries is the reason for this test. Placing a campaign whose spend is withheld, or
 * whose cost per result the platform never sent, at the origin would put the campaigns we know least
 * about in the corner that reads «cheap and efficient» — the most confident position on the chart,
 * given to the least evidence. The caller excludes them; this asserts the empty case is stated
 * rather than drawn.
 */
describe('the spend against efficiency chart', () => {
  it('says so when no campaign has both coordinates', () => {
    render(<SpendEfficiencyScatter points={[]} currency="SAR" ar={false} />)

    expect(screen.getByText(/No campaign has both a spend and a cost per result/)).toBeInTheDocument()
  })

  /** And says it in the reader's language — the empty state is a sentence like any other. */
  it('states the empty case in Arabic for an Arabic reader', () => {
    render(<SpendEfficiencyScatter points={[]} currency="SAR" ar />)

    expect(screen.getByText(/لا حملة تحمل إنفاقًا وتكلفة نتيجة معًا/)).toBeInTheDocument()
  })

  /** With points, it renders a chart rather than the sentence. */
  it('draws the chart when campaigns can be placed', () => {
    render(
      <SpendEfficiencyScatter
        points={[
          { id: 'a', name: 'Spring', spend: 1000, costPer: 25 },
          { id: 'b', name: 'Summer', spend: 4000, costPer: 90 },
        ]}
        currency="SAR"
        ar={false}
      />,
    )

    expect(screen.queryByText(/No campaign has both/)).toBeNull()
  })
})
