import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MetricStrip, reading, type MetricItem } from './MetricStrip'

const item = (over: Partial<MetricItem> = {}): MetricItem => ({
  key: 'spend',
  label: 'Spend',
  reading: { kind: 'value', text: '1,200 SAR' },
  ...over,
})

describe('MetricStrip', () => {
  /**
   * The rule the whole file exists for: **an unreported metric is not a zero.**
   *
   * A card reading «0» beside forty thousand impressions says the campaign failed. «لم ترسله
   * المنصة» says we were never told. They are different sentences and only one of them is true.
   */
  it('says the platform did not report it, and never prints a zero for it', () => {
    render(
      <MetricStrip
        id="t"
        ar={false}
        primary={[item({ key: 'video_views', label: 'Video views', reading: { kind: 'not_provided' } })]}
      />,
    )

    const card = screen.getByTestId('metric-video_views')
    expect(card).toHaveTextContent('Not provided')
    expect(card).not.toHaveTextContent('0')
    expect(card).toHaveAttribute('data-state', 'not_provided')
  })

  /** «Nothing arrived» and «the platform does not send it» are also two different sentences. */
  it('distinguishes no data from not provided', () => {
    render(
      <MetricStrip id="t" ar={false} primary={[item({ key: 'roas', label: 'ROAS', reading: { kind: 'no_data' } })]} />,
    )

    const card = screen.getByTestId('metric-roas')
    expect(card).toHaveTextContent('No data')
    expect(card).toHaveAttribute('data-state', 'no_data')
  })

  /** A real, measured zero is still a zero — the rule protects absence, not the digit. */
  it('prints a measured zero', () => {
    render(
      <MetricStrip
        id="t"
        ar={false}
        primary={[item({ key: 'purchases', label: 'Purchases', reading: { kind: 'value', text: '0' } })]}
      />,
    )

    const card = screen.getByTestId('metric-purchases')
    expect(card).toHaveTextContent('0')
    expect(card).toHaveAttribute('data-state', 'value')
  })

  /** A change against an absence is a comparison of two nothings, printed as a fact. */
  it('shows no delta beside a metric that has no figure', () => {
    render(
      <MetricStrip
        id="t"
        ar={false}
        primary={[item({ key: 'leads', label: 'Leads', reading: { kind: 'not_provided' }, delta: 0.4 })]}
      />,
    )

    expect(screen.getByTestId('metric-leads')).not.toHaveTextContent('40%')
  })

  /**
   * Priority: the secondary metrics are folded, and the control that unfolds them is ON the page.
   *
   * The point of the split is that four cards answer «how is this going» — putting the other ten
   * behind a dialog would trade one wall of cards for one hidden function.
   */
  it('folds the secondary metrics behind a visible on-page toggle', () => {
    render(
      <MetricStrip
        id="t"
        ar={false}
        primary={[item()]}
        secondary={[item({ key: 'cpm', label: 'CPM' }), item({ key: 'cpc', label: 'CPC' })]}
      />,
    )

    expect(screen.queryByTestId('metric-cpm')).not.toBeInTheDocument()

    const toggle = screen.getByTestId('t-metrics-toggle')
    expect(toggle).toHaveTextContent('2')

    fireEvent.click(toggle)
    expect(screen.getByTestId('metric-cpm')).toBeInTheDocument()
    // Unfolded in place — not into a dialog the reader has to dismiss to see the page again.
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  /** The comparison window is stated once, not repeated inside fourteen cards. */
  it('states the comparison window once, above the row', () => {
    render(
      <MetricStrip
        id="t"
        ar={false}
        primary={[item(), item({ key: 'clicks', label: 'Clicks' })]}
        comparisonLabel="the previous 30 days"
      />,
    )

    expect(screen.getAllByText(/the previous 30 days/)).toHaveLength(1)
  })
})

describe('reading', () => {
  /**
   * The formatter is never handed a null.
   *
   * This is the guard against the defect in a single keystroke: `format(value ?? 0)` would print a
   * measured zero for an absence, and every caller would inherit it.
   */
  it('does not call the formatter for an absent value', () => {
    const calls: number[] = []
    const format = (n: number) => {
      calls.push(n)
      return String(n)
    }

    expect(reading(null, format)).toEqual({ kind: 'no_data' })
    expect(reading(undefined, format, 'not_provided')).toEqual({ kind: 'not_provided' })
    expect(calls).toEqual([])

    expect(reading(0, format)).toEqual({ kind: 'value', text: '0' })
    expect(calls).toEqual([0])
  })

  it('shows a withheld figure at full weight with the reason, never as zero or as absent', () => {
    // FX-WITHHELD-UI-001. The platform reported 3,465.33 USD and no rate exists to convert it.
    // Before this variant the withheld null fell through to «no data» and the screen read 0.
    render(
      <MetricStrip
        id="t"
        ar
        primary={[item({ key: 'spend', label: 'الإنفاق', reading: { kind: 'withheld', original: '3,465.33 USD' } })]}
      />,
    )

    const card = screen.getByTestId('metric-spend')

    // The real figure is present — the whole point of the variant.
    expect(card).toHaveTextContent('3,465.33 USD')

    // And the reader is told why it is not in their currency.
    expect(card).toHaveTextContent(/التحويل إلى عملة المشروع غير متاح/)

    // It must NOT be described as something the platform failed to send.
    expect(card).not.toHaveTextContent('لم ترسله المنصة')
    expect(card).not.toHaveTextContent('لا توجد بيانات')

    // The card is a real reading, not a muted absence.
    expect(card).toHaveAttribute('data-state', 'withheld')
  })

})

/**
 * NUMBER-PRESENTATION-001 — the card shows the compact figure and holds the exact one.
 *
 * A `title` and not a custom tooltip: it is the one hover that also reaches a screen reader, and it
 * survives being inside a chart card, a table cell or a printed page.
 */
describe('the compact value keeps the exact one within reach', () => {
  it('hangs the full figure on the value it abbreviated', () => {
    render(
      <MetricStrip
        id="compact-exact"
        ar={false}
        primary={[{ key: 'spend', label: 'Spend', reading: { kind: 'value', text: '4.85M SAR', exact: '4,850,321 SAR' } }]}
        secondary={[]}
      />,
    )

    expect(screen.getByText('4.85M SAR')).toHaveAttribute('title', '4,850,321 SAR')
  })

  it('attaches no title when nothing was abbreviated', () => {
    render(
      <MetricStrip
        id="compact-none"
        ar={false}
        primary={[{ key: 'spend', label: 'Spend', reading: { kind: 'value', text: '940 SAR' } }]}
        secondary={[]}
      />,
    )

    expect(screen.getByText('940 SAR')).not.toHaveAttribute('title')
  })
})
