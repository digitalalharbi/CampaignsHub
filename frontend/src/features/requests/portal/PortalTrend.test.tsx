import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { PortalTrend } from './PortalTrend'
import { renderWithProviders } from '@/test/utils'

const trend = [
  { date: '2026-10-10', impressions: 1000, clicks: 50, conversions: 5 },
  { date: '2026-10-11', impressions: 2000, clicks: 70, conversions: null },
  { date: '2026-10-12', impressions: 3000, clicks: 90, conversions: 9 },
]

describe('PortalTrend', () => {
  it('draws the first measure that has at least two days, and offers the others', () => {
    renderWithProviders(<PortalTrend trend={trend} days={30} />, { locale: 'en' })

    expect(screen.getByTestId('portal-trend')).toHaveTextContent('Over time')
    expect(screen.getByTestId('trend-conversions')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('trend-clicks')).toBeInTheDocument()
    // Two real days for results (the unreported one is not a zero), three for clicks.
    expect(screen.getByRole('img', { name: 'Results — 2 days' })).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('trend-clicks'))
    expect(screen.getByRole('img', { name: 'Clicks — 3 days' })).toBeInTheDocument()
    expect(screen.getByTestId('trend-text')).toHaveTextContent('2026-10-12: 90')
  })

  it('draws nothing from a single point, which is a number and not a trend', () => {
    const { container } = renderWithProviders(<PortalTrend trend={[{ date: '2026-10-10', impressions: 10, clicks: 1, conversions: 1 }]} days={30} />, { locale: 'en' })
    expect(container.querySelector('[data-testid="portal-trend"]')).toBeNull()
  })

  it('speaks Arabic', () => {
    renderWithProviders(<PortalTrend trend={trend} days={30} />, { locale: 'ar' })
    expect(screen.getByTestId('portal-trend')).toHaveTextContent('الأداء عبر الوقت')
    expect(screen.getByTestId('trend-conversions')).toHaveTextContent('النتائج')
  })
})
