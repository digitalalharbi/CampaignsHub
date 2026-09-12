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

    /*
      `closest('[title]')` rather than the text node's own element: the digits are wrapped in a bidi
      isolate now (KPI-ALIGNMENT-002), and the title belongs on the value, not on the isolate.
    */
    expect(screen.getByText('4.85M SAR').closest('[title]')).toHaveAttribute('title', '4,850,321 SAR')
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

    expect(screen.getByText('940 SAR').closest('span[class*="text-start"]')).not.toHaveAttribute('title')
  })
})

/**
 * KPI-ALIGNMENT-002 — the figure sits under its own label, in every language.
 *
 * ## What this case used to assert, and why it was the bug
 *
 * It required the value element to carry BOTH `dir="ltr"` and `text-start`, on the reasoning that
 * the first keeps «56.3K SAR» in digit order and the second stops it drifting. The reasoning is
 * wrong in its second half: `text-align: start` inside a `dir="ltr"` box means LEFT. Measured in a
 * real browser on an Arabic page, that pairing puts the text run 72px from where the label ends —
 * and this test demanded it. The owner reported the misalignment twice while it passed.
 *
 * ## The rule now
 *
 * `dir` is an INLINE concern: only the digits are isolated, and the block keeps the page's
 * direction. jsdom lays nothing out, so what is asserted here is the STRUCTURE — the isolate is
 * inside, and the block does not override its own direction. The geometry is measured in
 * `e2e/metric-alignment.spec.ts`, in three browsers, which is where this defect was ever visible.
 */
describe('the value is placed by the reader’s direction, not by its own', () => {
  it('isolates the digits without re-basing the block', () => {
    render(
      <MetricStrip
        id="t"
        ar
        primary={[item({ key: 'spend', label: 'الإنفاق', reading: { kind: 'value', text: '56.3K SAR' } })]}
      />,
    )

    const isolate = screen.getByText('56.3K SAR')
    expect(isolate.tagName.toLowerCase(), 'the digits are not in a bidi isolate').toBe('bdi')
    expect(isolate).toHaveAttribute('dir', 'ltr')

    /* And nothing between the isolate and the card re-bases the alignment. */
    const block = isolate.closest('span[class*="text-start"]')
    expect(block, 'the value has no aligned block around it').not.toBeNull()
    expect(block).not.toHaveAttribute('dir')
  })
})

/**
 * UX-KPI-PRESENTATION-001 — «توسيط وتوازي»: the cards in a row are one height.
 *
 * They were, at 1440, and only there. At 390 the same ten cards measured 83, 122, 129 and 145
 * pixels: a label wrapping to two lines made its card taller, an absence rendered six pixels taller
 * than a figure, and a metric with no sparkline had nothing filling the space its neighbours used.
 *
 * jsdom lays nothing out, so these assert the STRUCTURE that produces the alignment rather than the
 * pixels — the three reserved rows, in a card that fills its grid cell. The measurement itself was
 * taken in a real browser at 390 and 1440, where every card now reports one height.
 */
describe('every card in a row is the same height', () => {
  const cardFor = (over: Partial<MetricItem>) => {
    const { container } = render(<MetricStrip id="s" ar={false} primary={[item(over)]} />)

    return container.querySelector(`[data-testid="metric-${over.key ?? 'spend'}"]`)!
  }

  it('fills its cell rather than sizing to its own content', () => {
    expect(cardFor({}).className).toContain('h-full')
  })

  /** A label that wraps is a taller card, and it takes its whole grid row with it. */
  it('reserves two lines for the label and draws no more', () => {
    const card = cardFor({ label: 'Landing page views on the destination site' })

    expect(card.firstElementChild?.className).toMatch(/min-h-\[2\.75rem\]/)
    expect(card.querySelector('.line-clamp-2')).not.toBeNull()
  })

  /**
   * The figure row is the same height whether it holds a figure or an absence.
   *
   * «Not provided» rendered taller than «48.4K SAR», so a card with an absence in it stood above its
   * neighbours — the absence was visible in the LAYOUT before it was read, which is the opposite of
   * what UX-METRICS-001 asks of it.
   */
  it('gives a figure and an absence the same row', () => {
    for (const reading of [
      { kind: 'value', text: '48.4K SAR' } as const,
      { kind: 'not_provided' } as const,
      { kind: 'no_data' } as const,
    ]) {
      const rows = [...cardFor({ reading }).children]

      expect(rows.some((r) => /min-h-\[1\.75rem\]/.test(r.className)), reading.kind).toBe(true)
    }
  })

  /**
   * The chart row is reserved whether or not there is a line to draw.
   *
   * Otherwise a metric the platform never sent has no sparkline and sits shorter than the ones that
   * do — and `mt-auto` is what keeps the value on one baseline across the row rather than floating
   * up inside the cards that have no chart.
   */
  it('reserves the chart row even with nothing to plot', () => {
    for (const spark of [undefined, [1, 5, 3]]) {
      const rows = [...cardFor({ spark }).children]
      const chart = rows.at(-1)!

      expect(chart.className).toContain('mt-auto')
      expect(chart.className).toContain('h-8')
    }
  })
})
