import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { ChangeDiagnosis } from './ChangeDiagnosis'
import { renderWithProviders } from '@/test/utils'
import type { Decomposition, DriversPayload, TimelinePoint } from './api'

/**
 * DASH-ANOMALY-001 — the days that departed from the period's behaviour, as a compact table a reader can filter and sort.
 */
const decomposition = (): Decomposition => ({
  metric: 'spend', current: 1000, previous: 900, change: 100, change_pct: 0.11, by: 'platform', drivers: [], comparable: true,
} as unknown as Decomposition)

const pt = (date: string, metric: string, value: number, baseline: number, deviation: number): TimelinePoint => ({
  date, metric, value, baseline, deviation, direction: value >= baseline ? 'up' : 'down',
} as TimelinePoint)

const payload = (points: TimelinePoint[]): DriversPayload => ({
  decomposition: [decomposition()],
  timeline: { points, reason: null, days: 30 },
} as unknown as DriversPayload)

const render = (points: TimelinePoint[], locale: 'ar' | 'en' = 'en') =>
  renderWithProviders(<ChangeDiagnosis data={payload(points)} currency="SAR" />, { locale })

const rowsText = () => [...screen.getByTestId('change-timeline').querySelectorAll('tbody tr')].map((r) => r.textContent ?? '')

describe('the anomaly table', () => {
  it('shows the actual, the reference, the change in percent and a severity named from the same units', () => {
    render([pt('2026-08-14', 'spend', 9_000, 1_500, 7.2), pt('2026-08-10', 'clicks', 300, 600, -4)])

    const row = screen.getByTestId('anomaly-row-2026-08-14-spend')
    expect(row).toHaveTextContent('9K SAR')
    expect(row).toHaveTextContent('1.5K SAR')
    expect(row).toHaveTextContent('+500%')
    expect(row).toHaveTextContent('Strong')
    expect(screen.getByTestId('anomaly-row-2026-08-10-clicks')).toHaveTextContent('-50%')
    expect(screen.getByTestId('anomaly-row-2026-08-10-clicks')).toHaveTextContent('Notable')
    // It does not claim to know which campaign moved the day: the timeline is measured on the project's total.
    expect(row).toHaveTextContent('Project')
  })

  it('sorts newest first, and by how far the day moved on request', () => {
    render([pt('2026-08-01', 'spend', 5000, 1000, 9), pt('2026-08-20', 'spend', 1500, 1000, 3.6), pt('2026-08-10', 'spend', 3000, 1000, 5.5)])

    expect(rowsText().map((t) => t.slice(0, 10))).toEqual(['2026-08-20', '2026-08-10', '2026-08-01'])
    fireEvent.click(screen.getByTestId('anomaly-sort-deviation'))
    expect(rowsText().map((t) => t.slice(0, 10))).toEqual(['2026-08-01', '2026-08-10', '2026-08-20'])
  })

  it('filters the table to one metric', () => {
    render([pt('2026-08-14', 'spend', 9000, 1500, 7.2), pt('2026-08-10', 'clicks', 300, 600, -4)])

    fireEvent.click(screen.getByTestId('anomaly-metric-clicks'))
    expect(rowsText()).toHaveLength(1)
    expect(rowsText()[0]).toContain('2026-08-10')
    expect(screen.getByTestId('anomaly-metric-clicks').getAttribute('aria-pressed')).toBe('true')
  })

  it('shows ten days and offers the rest', () => {
    render(Array.from({ length: 12 }, (_, i) => pt(`2026-08-${String(10 + i).padStart(2, '0')}`, 'spend', 2000 + i, 1000, 4 + i / 10)))

    expect(rowsText()).toHaveLength(10)
    fireEvent.click(screen.getByTestId('anomaly-show-all'))
    expect(rowsText()).toHaveLength(12)
  })

  it('reads in Arabic', () => {
    render([pt('2026-08-14', 'spend', 9000, 1500, 7.2)], 'ar')

    const table = screen.getByTestId('change-timeline')
    expect(within(table).getByText('المرجع')).toBeTruthy()
    expect(within(table).getByText('قوي')).toBeTruthy()
  })
})
