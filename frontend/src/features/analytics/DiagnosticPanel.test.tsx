import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

import { DiagnosticPanel } from './DiagnosticPanel'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — the four answers, and the one that must never wear
 * another's clothes.
 *
 * `diagnose()` already refuses to claim a cause it cannot evidence; that is tested next door. What is
 * tested HERE is the render, where the failure is cheaper to commit: «could not be examined», «nothing
 * to examine» and «examined and healthy» are all an empty findings list, and drawing the same
 * reassurance over all three is the whole defect this requirement exists to prevent.
 */
const FULL = {
  spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000,
}
const ALL_REPORTED = {
  spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true,
}

const render = (props: Partial<React.ComponentProps<typeof DiagnosticPanel>> = {}) =>
  renderWithProviders(
    <DiagnosticPanel objective="sales" totals={FULL} reported={ALL_REPORTED} rowsInScope ar={false} {...props} />,
  )

describe('what the diagnostic panel tells the reader', () => {
  it('states a weakness with the evidence it was read from', () => {
    render({ totals: { ...FULL, impressions: 0 } })

    expect(screen.getByTestId('diagnostic-finding-not_delivering')).toBeInTheDocument()
    expect(screen.getByTestId('diagnostic-finding-not_delivering')).toHaveTextContent(/From:/)
  })

  /** A ratio suggests a cause; it does not observe one. The reader has to be able to tell. */
  it('marks an inference as inferred and a measurement as measured', () => {
    render({ totals: { ...FULL, clicks: 10 } })

    expect(screen.getByTestId('diagnostic-confidence-probable')).toHaveTextContent('Inferred')
  })

  /**
   * The defect this file exists for.
   *
   * Nothing reported means nothing could be examined. An empty findings list rendered as reassurance
   * would tell somebody their account is healthy on the strength of no measurements at all.
   */
  it('never renders «could not be examined» as «nothing found»', () => {
    render({ reported: { spend: true } })

    expect(screen.getByTestId('diagnostic-not-diagnosable')).toBeInTheDocument()
    expect(screen.queryByTestId('diagnostic-healthy')).not.toBeInTheDocument()
  })

  /** Having examined and found nothing is a real answer, and a different one. */
  it('gives a clean bill of health only after something was actually examined', () => {
    render()

    expect(screen.getByTestId('diagnostic-healthy')).toBeInTheDocument()
    expect(screen.queryByTestId('diagnostic-not-diagnosable')).not.toBeInTheDocument()
  })

  /** What went unread is part of the answer — silence would be taken for a healthy stage. */
  it('names the stages it could not examine', () => {
    render({
      totals: { ...FULL, landing_page_views: 0 },
      reported: { ...ALL_REPORTED, landing_page_views: false },
    })

    expect(screen.getByTestId('diagnostic-missing')).toHaveTextContent(/A gap is not a zero/)
  })

  /**
   * An empty scope has no standing to say anything about a platform. Every metric reads unreported
   * over no rows, which diagnoses as «not delivering» — a claim about a connector, derived from a
   * filter.
   */
  it('blames the filter, not the platform, when the scope holds no rows', () => {
    render({ rowsInScope: false, reported: {}, totals: { spend: 0 } })

    expect(screen.getByTestId('diagnostic-empty-scope')).toBeInTheDocument()
    expect(screen.queryByTestId('diagnostic-not-diagnosable')).not.toBeInTheDocument()
    expect(screen.queryByTestId('diagnostic-findings')).not.toBeInTheDocument()
  })

  /** A panel that could not read the totals knows nothing, and may not draw reassurance over that. */
  it('lets a failed read outrank every other answer', () => {
    render({ error: new Error('boom'), rowsInScope: false })

    expect(screen.queryByTestId('diagnostic-healthy')).not.toBeInTheDocument()
    expect(screen.queryByTestId('diagnostic-empty-scope')).not.toBeInTheDocument()
  })

  it('reads in Arabic without switching the digits', () => {
    render({ ar: true, totals: { ...FULL, impressions: 0 } })

    expect(screen.getByTestId('diagnostic-finding-not_delivering')).toHaveTextContent('لا تُعرض')
  })
})
