import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PathTrends } from './PathTrends'
import type { PathTrend } from './api'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — one chart per path, and the days nobody reported.
 *
 * Recharts renders nothing measurable in jsdom, so what this holds is the part that is not the
 * drawing: that each path gets its OWN chart rather than sharing a line with a path it cannot be
 * compared to, that the coverage of the window is stated, and that the line is not told to connect
 * across a day nobody reported — which would turn a pause into a slope.
 */
const path = (over: Partial<PathTrend> = {}): PathTrend => ({
  path: 'awareness',
  label_ar: 'الوعي',
  label_en: 'Awareness',
  headline_metrics: ['spend', 'impressions'],
  days: [
    { date: '2026-08-10', reported: true, spend: 300, results: null, revenue: null, cost_per_result: null, cpm: 5 },
    { date: '2026-08-11', reported: false, spend: null, results: null, revenue: null, cost_per_result: null, cpm: null },
  ],
  days_reported: 1,
  days_in_window: 2,
  ...over,
})

describe('the per-path trends', () => {
  it('gives each path its own chart', () => {
    render(
      <PathTrends
        paths={[path(), path({ path: 'conversion', label_en: 'Conversion', label_ar: 'التحويل' })]}
        locale="en"
      />,
    )

    expect(screen.getByTestId('path-trend-awareness')).toBeInTheDocument()
    expect(screen.getByTestId('path-trend-conversion')).toBeInTheDocument()
  })

  /** «1 of 2 days reported» is the difference between a trend and two points with a line between. */
  it('says how much of the window the line actually covers', () => {
    render(<PathTrends paths={[path()]} locale="en" />)

    expect(screen.getByTestId('path-trend-awareness')).toHaveTextContent('1 of 2 days reported')
  })

  it('says nothing at all when no path ran', () => {
    render(<PathTrends paths={[]} locale="en" />)

    expect(screen.queryByTestId('path-trend-awareness')).not.toBeInTheDocument()
  })
})

/**
 * The one drawing decision worth pinning without a canvas: the line must NOT be connected across a
 * day nobody reported. Recharts joins nulls unless told otherwise, and a line drawn across a gap
 * turns a pause into a slope — which is the defect the server's `reported: false` exists to prevent.
 */
describe('the gaps in a line', () => {
  it('never tells the chart to connect across an unreported day', async () => {
    const source: string = (await import('./PathTrends.tsx?raw')).default

    expect(source).toContain('connectNulls={false}')
    expect(source).not.toContain('connectNulls={true}')
    expect(source).not.toMatch(/connectNulls\s*\/>/)
  })
})
