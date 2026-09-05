import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ChangeDiagnosis } from './ChangeDiagnosis'
import { renderWithProviders } from '@/test/utils'

/**
 * VISUAL-FIRST-001 / clause D — «ANOMALIES → a timeline with markers ON the actual metric trend».
 *
 * «2026-08-15 · spend · 2.89K · usual before it 2.56K» is four figures a reader assembles into a
 * shape themselves. The same day drawn on the metric's own curve IS the shape — and whether a day is
 * a spike or the start of a level change are different findings that lead to different actions.
 *
 * What matters here is when the chart must NOT be drawn. A trend invented from its own exceptions,
 * or a curve of two points, would be decoration answering nothing — which is the one thing the
 * requirement rules out by name.
 */
const day = (date: string, spend: number) => ({ date, spend } as never)

const payload = (over: Record<string, unknown> = {}) => ({
  window: { from: '2026-08-01', to: '2026-08-07', days: 7 },
  previous: { from: '2026-07-25', to: '2026-07-31' },
  drivers: {
    metric: 'spend', by: 'provider', decomposable: true, reason: null,
    current: 100, previous: 80, change: 20, change_pct: 0.25, drivers: [], unquantifiable: [],
  },
  also: [],
  timeline: {
    reason: null,
    days: 7,
    points: [{ date: '2026-08-04', metric: 'spend', value: 900, baseline: 200, direction: 'up' }],
  },
  ...over,
}) as never

const series = [
  day('2026-08-01', 200), day('2026-08-02', 210), day('2026-08-03', 190),
  day('2026-08-04', 900), day('2026-08-05', 205), day('2026-08-06', 215),
]

describe('anomalies drawn on the metric’s own curve', () => {
  it('draws the metric’s trend when there is a series to draw', () => {
    renderWithProviders(
      <ChangeDiagnosis data={payload()} currency="SAR" loading={false} error={false} series={series} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('anomaly-trend-spend')).toBeInTheDocument()
  })

  /** The list stays. The chart says WHERE; the list says exactly what, and both are wanted. */
  it('keeps the dated list beneath it', () => {
    renderWithProviders(
      <ChangeDiagnosis data={payload()} currency="SAR" loading={false} error={false} series={series} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('change-timeline')).toHaveTextContent('2026-08-04')
  })

  /**
   * With no series there is nothing to mark. Drawing the anomalies alone would be a «trend» made of
   * its own exceptions — a chart that answers no question, which the requirement forbids by name.
   */
  it('draws no chart when the page has no series', () => {
    renderWithProviders(
      <ChangeDiagnosis data={payload()} currency="SAR" loading={false} error={false} />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('anomaly-charts')).not.toBeInTheDocument()
    // And the finding is still reachable — the list is what must never disappear.
    expect(screen.getByTestId('change-timeline')).toHaveTextContent('2026-08-04')
  })

  /** Two points is a line between two points, not a trend. */
  it('draws no chart from a series too short to have a shape', () => {
    renderWithProviders(
      <ChangeDiagnosis
        data={payload()} currency="SAR" loading={false} error={false}
        series={[day('2026-08-01', 200), day('2026-08-02', 900)]}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('anomaly-trend-spend')).not.toBeInTheDocument()
  })

  /**
   * A series long enough to draw, for a metric reported on only TWO of its days.
   *
   * The outer guard counts DAYS and would let this through; the inner one counts days that actually
   * carry the metric. Without it, a metric the platform reported twice in a month would be drawn as
   * a confident line between two points — verified by injecting the inner threshold, which the
   * shorter-series case above cannot catch because the outer guard stops it first.
   */
  it('draws no chart for a metric reported on too few of the days', () => {
    renderWithProviders(
      <ChangeDiagnosis
        data={payload()} currency="SAR" loading={false} error={false}
        series={[
          day('2026-08-01', 200),
          { date: '2026-08-02' } as never,
          { date: '2026-08-03' } as never,
          day('2026-08-04', 900),
          { date: '2026-08-05' } as never,
        ]}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('anomaly-trend-spend')).not.toBeInTheDocument()
  })

  /** A metric the series does not carry cannot be drawn, and is not drawn flat at zero. */
  it('draws nothing for a metric the series never reported', () => {
    renderWithProviders(
      <ChangeDiagnosis
        data={payload({ timeline: { reason: null, days: 7, points: [{ date: '2026-08-04', metric: 'leads', value: 9, baseline: 2, direction: 'up' }] } })}
        currency="SAR" loading={false} error={false} series={series}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('anomaly-trend-leads')).not.toBeInTheDocument()
  })
})
