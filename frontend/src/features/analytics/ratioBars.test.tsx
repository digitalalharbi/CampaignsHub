import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RatioBars } from './charts'

/**
 * VIZ-RATIO-001 — a unitless ratio against the one value that decides it.
 *
 * Budget pace is `projected / budget`: 1.0 lands on budget and anything above it overruns. A column
 * of «1.24 / 0.88 / 1.02» makes the reader do that comparison in their head on every row. A bar
 * against a drawn line at 1.0 makes «which of these is over» a glance.
 *
 * Because the ratio carries no unit, rows priced in different currencies may sit in the same chart —
 * which is the whole reason this is a ratio chart and not a money chart. What it may NOT do is
 * place a row whose ratio is unknown, so the caller passes only drawable rows and renders the count
 * of what it withheld; this follows `SpendEfficiencyScatter`'s contract rather than inventing a
 * second one.
 */
const rows = [
  { id: 'a', label: 'Over', value: 1.4 },
  { id: 'b', label: 'Under', value: 0.7 },
]

describe('RatioBars', () => {
  it('draws one bar per row, scaled against the largest value and the reference together', () => {
    render(<RatioBars testId="pace" rows={rows} reference={1} ar={false} />)

    // The scale tops out at the largest of (values, reference) — so 1.4 is the full bar.
    expect(screen.getByTestId('pace-bar-a')).toHaveStyle({ width: '100%' })
    expect(screen.getByTestId('pace-bar-b')).toHaveStyle({ width: '50%' })
  })

  it('keeps the reference visible even when every row sits below it', () => {
    render(<RatioBars testId="pace" rows={[{ id: 'b', label: 'Under', value: 0.5 }]} reference={1} ar={false} />)

    expect(screen.getByTestId('pace-bar-b')).toHaveStyle({ width: '50%' })
    expect(screen.getByTestId('pace-reference')).toHaveStyle({ insetInlineStart: '100%' })
  })

  it('names the reference so the line means something', () => {
    render(<RatioBars testId="pace" rows={rows} reference={1} referenceLabel="On budget" ar={false} />)

    expect(screen.getByTestId('pace-reference')).toHaveTextContent('On budget')
  })

  it('tones a row by which side of the reference it falls on', () => {
    render(<RatioBars testId="pace" rows={rows} reference={1} ar={false} />)

    expect(screen.getByTestId('pace-bar-a').className).toContain('danger')
    expect(screen.getByTestId('pace-bar-b').className).not.toContain('danger')
  })

  it('prints each ratio beside its bar in Latin digits', () => {
    render(<RatioBars testId="pace" rows={rows} reference={1} ar />)

    expect(screen.getByTestId('pace-row-a')).toHaveTextContent('1.4')
  })

  it('declines when there is no row it can place', () => {
    render(<RatioBars testId="pace" rows={[]} reference={1} ar={false} />)

    expect(screen.getByTestId('pace-empty')).toBeInTheDocument()
    expect(screen.queryByTestId('pace-reference')).toBeNull()
  })

  it('orders the bars by the ratio, worst first, so the row that needs opening is on top', () => {
    render(<RatioBars testId="pace" rows={[{ id: 'b', label: 'Under', value: 0.7 }, { id: 'a', label: 'Over', value: 1.4 }]} reference={1} ar={false} />)

    const drawn = screen.getAllByTestId(/^pace-row-/).map((n) => n.getAttribute('data-testid'))
    expect(drawn).toEqual(['pace-row-a', 'pace-row-b'])
  })
})

describe('RatioBars keeps its reference label on the card', () => {
  it('reads back toward the bars when the line sits at the end of the track', () => {
    // Nothing overruns, so the ceiling IS the reference and the line lands at 100%.
    render(<RatioBars testId="pace" rows={[{ id: 'b', label: 'Under', value: 0.5 }]} reference={1} referenceLabel="On budget" ar={false} />)

    expect(screen.getByTestId('pace-reference-label')).toHaveStyle({ insetInlineEnd: '0px' })
  })

  it('reads forward from the line when the line sits early in the track', () => {
    render(<RatioBars testId="pace" rows={[{ id: 'a', label: 'Way over', value: 4 }]} reference={1} referenceLabel="On budget" ar={false} />)

    expect(screen.getByTestId('pace-reference-label')).toHaveStyle({ insetInlineStart: '0px' })
  })
})
