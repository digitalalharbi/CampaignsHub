import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ReportPlatformSummary } from './ReportPlatformSummary'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-DETAIL-PARITY-001 — one platform's own figures, and what this strip may claim about them.
 *
 * The comparison table makes platforms comparable; this answers the question a DETAILED report asks
 * about each one in turn — what it reached, what that cost, what came back, and which way it is
 * going. What is pinned here is the part that quietly goes wrong: a metric nobody reported printed
 * as a measurement, a withheld amount printed under this report's currency, and a movement invented
 * for a platform that was not running last month.
 */
const platform = (over: Record<string, unknown> = {}) => ({
  provider: 'meta',
  spend: 1000,
  conversions: 40,
  cpa: 25,
  impressions: 100_000,
  clicks: 2_000,
  ctr: 0.02,
  reach: 0,
  revenue: 5_000,
  roas: 5,
  spend_share: 0.35,
  ...over,
})

const render = (over: Record<string, unknown> = {}, currency = 'SAR') =>
  renderWithProviders(
    <ReportPlatformSummary platform={platform(over)} currency={currency} locale="en" />,
    { locale: 'en' },
  )

describe('a platform’s summary in the detailed report', () => {
  it('carries what the platform reported', () => {
    render()

    const strip = within(screen.getByTestId('report-platform-summary'))
    expect(strip.getByTestId('report-platform-summary-spend')).toHaveTextContent('1K SAR')
    expect(strip.getByTestId('report-platform-summary-cpa')).toHaveTextContent('25')
    expect(strip.getByTestId('report-platform-summary-roas')).toHaveTextContent('5.00\u00d7')
    expect(strip.getByTestId('report-platform-summary-spend_share')).toHaveTextContent('35.0%')
  })

  /**
   * The aggregator coalesces a missing sum to 0, so an unreported reach and a reach of nobody arrive
   * identically. Production showed which one it really is: «Reach 0» beside 1.26M impressions, and a
   * platform cannot show a million impressions to nobody.
   */
  it('does not print an unreported metric as a measurement', () => {
    render()

    expect(screen.queryByTestId('report-platform-summary-reach')).toBeNull()
  })

  /** But a platform that spent nothing says so: there, zero IS the finding. */
  it('keeps spend and results even at zero', () => {
    render({ spend: 0, conversions: 0, cpa: null, revenue: 0, roas: null })

    expect(screen.getByTestId('report-platform-summary-spend')).toBeInTheDocument()
    expect(screen.getByTestId('report-platform-summary-conversions')).toBeInTheDocument()
  })

  /**
   * FX-001 — a withheld spend is never restated in this report's currency.
   */
  it('states a withheld spend in the currency it was recorded in', () => {
    render({
      spend: null,
      spend_original: 4_128.93,
      money_original_currency: 'USD',
      money_original_currencies: 1,
      spend_withheld_rows: 2,
    })

    const cell = screen.getByTestId('report-platform-summary-spend')
    expect(cell).toHaveTextContent('USD')
    expect(cell).not.toHaveTextContent('SAR')
  })

  /**
   * No movement where there was nothing to compare against.
   *
   * `LiveReportService` leaves `movement` empty for a platform with no previous window, and a 0%
   * pill would say a platform that started running this month stood still.
   */
  it('shows no movement for a platform that was not running before', () => {
    render({ movement: {} })

    /*
     * A movement is a pill beside the figure, and `TrendPill` carries no testid — so the assertion is
     * on what the reader sees: the strip states the figures and adds no «0%» anywhere.
     *
     * The percentages that ARE there are metrics — CTR and the share of spend — so the check is for
     * a ZERO movement specifically, which is the thing a missing comparison must never become.
     */
    const strip = screen.getByTestId('report-platform-summary')

    /*
     * A movement is a PILL — a rounded span with an arrow — and the assertion is that the strip drew
     * none. Asserting on the text was the first attempt and it matched «CTR 2.00%», because «0%» is
     * a substring of «2.00%»: a check that could pass or fail for reasons unrelated to its name.
     */
    expect(strip.querySelectorAll('span.rounded-full')).toHaveLength(0)
    expect(within(strip).getByTestId('report-platform-summary-spend')).toHaveTextContent('1K SAR')
  })

  it('shows the movement a platform does have', () => {
    render({ movement: { spend: 0.26, conversions: -0.1 } })

    expect(screen.getByTestId('report-platform-summary-spend')).toHaveTextContent('26')
  })
})
