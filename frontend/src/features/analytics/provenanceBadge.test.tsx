import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProvenanceBadge } from './components'

/**
 * ANALYTICS-PROVENANCE-001 — «Demo» is a fact about the rows, not a constant.
 *
 * The dashboard, campaigns and analytics all rendered `<DemoBadge />` unconditionally, so a project
 * syncing real Snapchat spend was labelled «بيانات تجريبية · Demo» beside its own money.
 */
describe('the provenance badge', () => {
  it('says nothing for a live project', () => {
    render(<ProvenanceBadge provenance={{ source: 'live', live_rows: 1572, demo_rows: 0 }} />)

    // The absence of a warning IS the signal for real data. A «LIVE» chip on every screen would be
    // the same noise in the other direction.
    expect(screen.queryByText(/Demo|تجريبية/)).not.toBeInTheDocument()
    expect(document.body.textContent).toBe('')
  })

  it('labels a genuinely seeded project', () => {
    render(<ProvenanceBadge provenance={{ source: 'demo', live_rows: 0, demo_rows: 400 }} />)

    expect(screen.getByText(/Demo|تجريبية/)).toBeInTheDocument()
  })

  /** Both is a real state, and naming it is what stops demo rows hiding inside a live total. */
  it('names a mixed project rather than picking one label', () => {
    render(<ProvenanceBadge provenance={{ source: 'mixed', live_rows: 1572, demo_rows: 400 }} />)

    expect(screen.getByText(/Mixed|مختلطة/)).toBeInTheDocument()
  })

  it('says nothing when there are no rows to characterise', () => {
    render(<ProvenanceBadge provenance={{ source: 'none', live_rows: 0, demo_rows: 0 }} />)

    expect(document.body.textContent).toBe('')
  })

  /** While the summary is still loading there is no claim to make — and «Demo» is a claim. */
  it('says nothing before the data arrives', () => {
    render(<ProvenanceBadge provenance={undefined} />)

    expect(document.body.textContent).toBe('')
  })
})
