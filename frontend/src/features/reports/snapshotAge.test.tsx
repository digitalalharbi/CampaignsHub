import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InteractiveReport } from './InteractiveReport'

/**
 * REPORTS-RECONCILIATION-001 — a month-old snapshot must not read as current performance.
 *
 * `generated_at`, `mode` and `attribution_window` were on the payload and in this file's type, and
 * none was rendered. The reader saw figures with no indication of their age.
 */
const base = {
  // A recommendations slide renders from `data` alone, so the fixture does not have to carry a
  // cover slide's whole meta shape. What is under test is the freshness line, not the deck.
  slides: [{ id: 's1', type: 'recommendations', title: 'Next', visible: true }],
  recommendations: [],
  totals: {},
} as never

const meta = { currency: 'SAR', locale: 'ar', platforms: [], clientName: 'Client', title: 'Report' } as never

describe('a report states how current it is', () => {
  it('says a snapshot is a snapshot, and when it was taken', () => {
    render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot', generated_at: '2026-07-01T09:00:00Z' } as never} meta={meta} />)

    const line = screen.getByTestId('report-snapshot-age')

    expect(line).toHaveTextContent('لقطة')
    expect(line).toHaveTextContent(/2026/)
    // The point of the sentence: these are not current figures.
    expect(line).toHaveTextContent('وليست أداءً حاليًا')
  })

  it('says a live report is live rather than quoting a generation time', () => {
    render(<InteractiveReport data={{ ...(base as object), mode: 'live', generated_at: '2026-07-01T09:00:00Z' } as never} meta={meta} />)

    expect(screen.getByTestId('report-snapshot-age')).toHaveTextContent('مباشر')
  })

  /** A report written before this metadata existed invents no date for itself. */
  it('claims nothing when nothing was recorded', () => {
    render(<InteractiveReport data={{ ...(base as object), mode: 'snapshot' } as never} meta={meta} />)

    expect(screen.queryByTestId('report-snapshot-age')).not.toBeInTheDocument()
  })

  /** The attribution basis rides along, because two bases are two different measurements. */
  it('names the attribution basis when the snapshot recorded one', () => {
    render(
      <InteractiveReport
        data={{ ...(base as object), mode: 'snapshot', generated_at: '2026-07-01T09:00:00Z', attribution_window: 'swipe_28d' } as never}
        meta={meta}
      />,
    )

    expect(screen.getByTestId('report-snapshot-age')).toHaveTextContent('swipe_28d')
  })
})
