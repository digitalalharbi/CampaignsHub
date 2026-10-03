import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders } from '@/test/utils'

/**
 * DASHBOARD-COMMAND-BAR-001 — which surface gets which bar, decided here rather than per control.
 *
 * The height is measured in a browser; what a unit test can hold is the DECISION, and the decision
 * is the thing that would silently flip if somebody passed `density` at a call site or dropped the
 * prop while editing the page.
 */
describe('the dashboard filter bar', () => {
  it('is dense on the dashboard and comfortable on the analysis surface', async () => {
    const dashboard = renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    expect((await screen.findByTestId('dashboard-filters')).getAttribute('data-density')).toBe('dense')

    dashboard.unmount()

    renderWithProviders(<AnalyticsPage />, { locale: 'ar' })

    expect((await screen.findByTestId('analytics-filters')).getAttribute('data-density')).toBe('comfortable')
  })

  /**
   * **Scope first.**
   *
   * «Whose figures am I looking at» is the question a reader answers before any other, and a bar
   * that opens with the period asks them to read past it.
   */
  it('asks for the scope before the period', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'ar' })

    const controls = await screen.findByTestId('dashboard-filters-controls')
    const order = [...controls.children]
      .map((c) => (c.textContent ?? '').trim().slice(0, 8))
      .filter(Boolean)

    expect(order[0]).toContain('المشروع')
    expect(order[1]).toContain('الفترة')
  })
})
