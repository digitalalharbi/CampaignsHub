import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InteractiveReport } from './InteractiveReport'

/**
 * REPORT-SNAPSHOT-COMPARABILITY-001 — a generated report compares two windows only when both are
 * whole, and the executive summary says why when it does not.
 */
const base = {
  slides: [{ id: 's1', type: 'executive_summary', title: 'Summary', visible: true }],
  period: { from: '2026-09-11', to: '2026-10-10' },
  currency: 'SAR',
  objective: 'sales',
  kpis: { spend: 45_898.35, revenue: 504_860, roas: 11, conversions: 1_401, cpa: 32.76, ctr: 0.0188, impressions: 2_569_040, clicks: 48_181 },
  previous: { spend: 38_961.25, revenue: 416_140, roas: 10.68, conversions: 1_163, cpa: 33.5, ctr: 0.0176, impressions: 2_314_257, clicks: 40_803 },
  platforms: [], campaigns: [], funnel: [], timeseries: [], summary: [], findings: [], recommendations: [], totals: {},
} as never

const meta = { currency: 'SAR', locale: 'ar', platforms: [], clientName: 'Client', title: 'Report' } as never

describe('the executive summary and its previous period', () => {
  it('carries the change pills when the windows compare', () => {
    render(<InteractiveReport data={{ ...(base as object), delta: { spend: 0.1781, revenue: 0.2132 }, comparison: { comparable: true, window: null, contributors: [], through: null } } as never} meta={meta} />)
    expect(screen.queryByTestId('report-comparison-withheld')).not.toBeInTheDocument()
    // +21.32 % rounds to «21%» on the revenue card (the sales card set leads with the money pair).
    expect(screen.getAllByText(/21%/).length).toBeGreaterThan(0)
  })

  it('says which window stopped short, who stopped and through when, and carries no pill', () => {
    render(<InteractiveReport data={{ ...(base as object), delta: { spend: null, revenue: null }, comparison: { comparable: false, window: 'current', contributors: ['meta'], through: '2026-09-27' } } as never} meta={meta} />)
    const line = screen.getByTestId('report-comparison-withheld')
    expect(line.textContent).toContain('هذه الفترة مغطاة حتى 2026-09-27 فقط')
    expect(line.textContent).toContain('ميتا')
    expect(screen.queryByText(/21%/)).not.toBeInTheDocument()
  })
})
