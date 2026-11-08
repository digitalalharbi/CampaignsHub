import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from '../api'
import { DailyMeasureChart } from './DailyMeasureChart'
import { useLiveMetricReader } from './liveMetrics'

/**
 * REPORT-WEBSITE-TAB-001 — the daily values table became an interactive chart: a metric switcher, a toggle per platform,
 * a platform-share donut whose centre is the total, a keyboard readout, and nothing a link hides.
 */
const days = ['2026-10-01', '2026-10-02', '2026-10-03']
const row = (date: string, i: number, k: number) => ({ date, impressions: 1000 * k * (i + 1), clicks: 10 * k * (i + 1), spend: 5 * k * (i + 1), conversions: k * (i + 1), revenue: 50 * k * (i + 1) })
const series = (k: number) => days.map((d, i) => row(d, i, k))
const total = days.map((d, i) => row(d, i, 3))

function payload(over: Record<string, unknown> = {}): LivePayload {
  return {
    currency: 'USD',
    totals: { spend: 90, revenue: 900, impressions: 18000 },
    timeseries: total,
    platform_series: { meta: series(1), snapchat: series(2) },
    ...over,
  } as unknown as LivePayload
}

function Harness({ p }: { p: LivePayload }) {
  const reader = useLiveMetricReader('USD', false)

  return <DailyMeasureChart payload={p} reader={reader} ar={false} />
}

describe('the interactive daily chart', () => {
  it('offers the metrics the link carries and switches between them', () => {
    renderWithProviders(<Harness p={payload()} />, { locale: 'en' })

    const chips = within(screen.getByTestId('daily-metric-chips'))
    for (const key of ['impressions', 'clicks', 'spend', 'conversions', 'revenue']) expect(chips.getByTestId(`daily-metric-${key}`)).toBeInTheDocument()
    expect(screen.getByTestId('daily-chart')).toHaveAttribute('data-metric', 'impressions')

    fireEvent.click(screen.getByTestId('daily-metric-clicks'))
    expect(screen.getByTestId('daily-chart')).toHaveAttribute('data-metric', 'clicks')
    expect(screen.getByTestId('daily-metric-clicks')).toHaveAttribute('aria-pressed', 'true')
  })

  it('draws the donut for the chosen metric with the total in its centre, and a platform toggle moves both', () => {
    renderWithProviders(<Harness p={payload()} />, { locale: 'en' })

    // clicks: meta 10+20+30 = 60, snapchat 120 → 180.
    fireEvent.click(screen.getByTestId('daily-metric-clicks'))
    expect(screen.getByTestId('daily-donut')).toHaveAttribute('data-total', '180')
    expect(screen.getByTestId('daily-donut')).toHaveTextContent('180')

    fireEvent.click(screen.getByTestId('daily-platform-snapchat'))
    expect(screen.getByTestId('daily-platform-snapchat')).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByTestId('daily-donut')).toHaveAttribute('data-total', '60')
    expect(screen.getByTestId('stacked-area')).toHaveAttribute('data-series', '1')
  })

  it('never offers spend, nor draws a money figure, when the link hides spend', () => {
    const hidden = payload({
      totals: { impressions: 18000 },
      timeseries: total.map(({ spend: _s, ...rest }) => rest),
      platform_series: { meta: series(1).map(({ spend: _s, ...rest }) => rest), snapchat: series(2).map(({ spend: _s, ...rest }) => rest) },
    })
    renderWithProviders(<Harness p={hidden} />, { locale: 'en' })

    expect(screen.queryByTestId('daily-metric-spend')).toBeNull()
    expect(screen.getByTestId('daily-metric-chips')).not.toHaveTextContent(/spend/i)
  })

  it('withholds revenue when the link hides it', () => {
    renderWithProviders(<Harness p={payload({ totals: { spend: 90, revenue: null } })} />, { locale: 'en' })

    expect(screen.queryByTestId('daily-metric-revenue')).toBeNull()
  })

  it('is keyboard accessible: the arrow keys walk the days and a readout states each value', () => {
    renderWithProviders(<Harness p={payload()} />, { locale: 'en' })

    const group = screen.getByRole('group', { name: /Daily measurement/ })
    expect(group).toHaveAttribute('tabindex', '0')
    fireEvent.keyDown(group, { key: 'ArrowRight' })
    expect(screen.getByTestId('stacked-area-readout')).toHaveTextContent('2026-10-01')
    fireEvent.keyDown(group, { key: 'End' })
    expect(screen.getByTestId('stacked-area-readout')).toHaveTextContent('2026-10-03')
    expect(screen.getByTestId('stacked-area-readout')).toHaveTextContent('Meta')
  })

  it('has a fixed height whatever is toggled, and replaces the table', () => {
    renderWithProviders(<Harness p={payload()} />, { locale: 'en' })

    expect(screen.queryByTestId('tables-days')).toBeNull()
    expect(screen.getByRole('group', { name: /Daily measurement/ })).toHaveStyle({ height: '280px' })
    fireEvent.click(screen.getByTestId('daily-metric-conversions'))
    expect(screen.getByRole('group', { name: /Daily measurement/ })).toHaveStyle({ height: '280px' })
  })
})
