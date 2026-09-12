import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ContentSummary } from './ContentSummary'
import type { FormatRow } from './api'

/**
 * CONTENT-SUMMARY-RESERVE-002 — the mix region exists in every state, because its height moves the
 * page under it.
 *
 * ## Why this is a structural test and not a measurement
 *
 * The defect is 77px of layout shift, and jsdom has no layout — it cannot measure pixels, so a test
 * here asserting a height would be asserting the numbers in the class names. `creative-analysis`
 * owns the measurement, in a real browser, and it is what caught all three breaks.
 *
 * What this owns is the INVARIANT that makes the measurement pass: the region is present whatever
 * the data is doing. A region that renders in two of its three states has a height that depends on
 * which state it is in, and the browser guard then fires for whichever state CI happens to hit —
 * which is exactly how this block broke on webkit and not on chromium.
 *
 * ## The three states, and the one that was missing
 *
 * `loading` is the FIGURES' request. The mix is drawn from a different one, and nothing connected
 * them: with the figures resolved and the formats still in flight, the block drew its final layout
 * with no mix at all and then grew when the formats landed. `formatsPending` is that missing state.
 */
const FORMATS: FormatRow[] = [
  { format: 'video', value: 900, spend: 900, creatives: 8 },
  { format: 'image', value: 100, spend: 100, creatives: 4 },
]

const FIGURES = [
  { key: 'spend', label: 'Spend', value: '1,000 SAR' },
  { key: 'impressions', label: 'Impressions', value: '2,000' },
]

function renderSummary(props: Partial<Parameters<typeof ContentSummary>[0]>) {
  return render(
    <ContentSummary
      figures={FIGURES}
      formats={FORMATS}
      creativesRead={12}
      currency="SAR"
      locale="en"
      {...props}
    />,
  )
}

describe('the content summary holds its shape in every state', () => {
  it('reserves the mix while the whole block is loading', () => {
    renderSummary({ loading: true })

    expect(screen.getByTestId('content-summary-mix')).toHaveAttribute('data-state', 'loading')
  })

  /* The state that did not exist: figures in, formats still coming. */
  it('reserves the mix when the figures have landed and the formats have not', () => {
    renderSummary({ formats: undefined, formatsPending: true })

    expect(screen.getByTestId('content-summary-figures')).toBeInTheDocument()
    expect(screen.getByTestId('content-summary-mix')).toHaveAttribute('data-state', 'loading')
  })

  /**
   * And when the formats came back with nothing priceable, it SAYS so rather than vanishing.
   *
   * Collapsing here would move the toolbar by the same height, for every account whose spend the
   * platforms did not attribute to a shape — and a heading with nothing under it reads as a chart
   * that failed to draw.
   */
  it('answers rather than collapsing when no spend can be attributed to a format', () => {
    renderSummary({ formats: [] })

    const mix = screen.getByTestId('content-summary-mix')
    expect(mix).toHaveAttribute('data-state', 'none')
    expect(mix).toHaveTextContent(/No spend could be attributed to a content type/i)
  })

  it('draws the bar once the formats are known', () => {
    renderSummary({})

    expect(screen.getByTestId('content-summary-mix')).toHaveAttribute('data-state', 'ready')
    expect(screen.getByTestId('content-summary-slice-video')).toBeInTheDocument()
    expect(screen.getByTestId('content-summary-slice-image')).toBeInTheDocument()
  })

  /**
   * A single format still draws the bar — the break before this one.
   *
   * Drawing only for two or more was defensible («one format is not a mix») and made the height a
   * function of the data, which the browser guard measured as 73px.
   */
  it('draws the bar for a single format too', () => {
    renderSummary({ formats: [FORMATS[0]] })

    expect(screen.getByTestId('content-summary-mix')).toHaveAttribute('data-state', 'ready')
    expect(screen.getByTestId('content-summary-slice-video')).toBeInTheDocument()
  })

  /**
   * With nothing to summarise at all it is absent — deliberately, and only once it KNOWS.
   *
   * `EmptyHeadlineState` and the grid beneath already say which filter came back empty, and a
   * second empty shell above them would be the wall of boxes this block replaced. But that
   * judgement needs the formats to have arrived: returning null while they are still in flight is
   * the 77px again, with the whole block rather than the mix.
   */
  it('is absent when there is nothing to summarise, and not before', () => {
    const { container, unmount } = renderSummary({ figures: [], formats: undefined, formatsPending: true })
    expect(screen.getByTestId('content-summary')).toBeInTheDocument()
    unmount()

    renderSummary({ figures: [], formats: [] })
    expect(container.querySelector('[data-testid="content-summary"]')).toBeNull()
  })
})
