import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { InflowTrend } from './InflowTrend'
import { renderWithProviders } from '@/test/utils'

const day = (i: number, count: number) => ({ date: `2026-10-${String(i).padStart(2, '0')}`, count })
const label = { ar: 'الطلبات الواردة', en: 'Requests received' }

/** UX-COMPACT-CHARTS-001 — a real trend or nothing: never a line that implies a shape the data does not have. */
describe('InflowTrend', () => {
  it('states the total and draws the days that have a shape', () => {
    renderWithProviders(<InflowTrend series={[day(1, 2), day(2, 0), day(3, 5)]} label={label} ar={false} testId="t" />, { locale: 'en' })
    expect(screen.getByTestId('t')).toHaveTextContent('Requests received · last 3 days: 7')
  })

  it('draws nothing for a flat, short or missing series', () => {
    const { rerender } = renderWithProviders(<InflowTrend series={[day(1, 0), day(2, 0), day(3, 0)]} label={label} ar={false} testId="t" />, { locale: 'en' })
    expect(screen.queryByTestId('t')).toBeNull()
    rerender(<InflowTrend series={[day(1, 3)]} label={label} ar={false} testId="t" />)
    expect(screen.queryByTestId('t')).toBeNull()
    rerender(<InflowTrend series={null} label={label} ar={false} testId="t" />)
    expect(screen.queryByTestId('t')).toBeNull()
  })
})
