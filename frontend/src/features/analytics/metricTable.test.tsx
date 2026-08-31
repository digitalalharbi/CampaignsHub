import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'

import { MetricTable } from './AnalyticsPage'

/**
 * TABLE-SORT-ALIGN-001 — asserted on fixed rows rather than on whatever the database holds.
 *
 * The first version of this was an end-to-end test. It passed locally against a table with one
 * seeded row and failed in CI, where that table has none — so it was really a test about which
 * fixtures a given database happens to carry. Sorting and alignment are properties of the component,
 * and the component can simply be given rows.
 *
 * The centring is asserted through the class both cells carry, because that is what makes them line
 * up; the geometry was measured once in a real browser and came back at 0px drift, which no
 * jsdom-based test could reproduce.
 */
const HEAD = ['Platform', 'Spend', 'ROAS']

const ROWS = [
  ['Meta', '300 SAR', '2.5×'],
  ['Snapchat', '900 SAR', '—'],
  ['TikTok', '100 SAR', '4.0×'],
]

const VALUES = [
  ['Meta', 300, 2.5],
  ['Snapchat', 900, null],
  ['TikTok', 100, 4.0],
]

const names = () => screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0].textContent)

describe('the analytics table', () => {
  it('centres numeric columns in the header and the body alike', () => {
    render(<MetricTable head={HEAD} rows={ROWS} />)

    const heads = screen.getAllByRole('columnheader')
    const cells = within(screen.getAllByRole('row')[1]).getAllByRole('cell')

    // The first column is the label and stays start-aligned; the figures are centred.
    expect(heads[0].className).toContain('text-start')
    expect(cells[0].className).toContain('text-start')

    for (const i of [1, 2]) {
      expect(heads[i].className, `header ${i} is not centred`).toContain('text-center')
      expect(cells[i].className, `cell ${i} is not centred`).toContain('text-center')
      // `tnum` is what keeps digits the same width; centring only moves where that block sits.
      expect(cells[i].className).toContain('tnum')
    }
  })

  it('offers no sort control when the caller passed no figures to sort by', () => {
    render(<MetricTable head={HEAD} rows={ROWS} />)

    expect(screen.queryByTestId('sort-1')).not.toBeInTheDocument()
  })

  it('sorts by a column, reverses on a second click, and says so to assistive technology', () => {
    render(<MetricTable head={HEAD} rows={ROWS} values={VALUES} initialSort={{ column: 1, dir: 'desc' }} />)

    expect(names()).toEqual(['Snapchat', 'Meta', 'TikTok'])
    expect(screen.getAllByRole('columnheader')[1]).toHaveAttribute('aria-sort', 'descending')

    fireEvent.click(screen.getByTestId('sort-1'))

    expect(names()).toEqual(['TikTok', 'Meta', 'Snapchat'])
    expect(screen.getAllByRole('columnheader')[1]).toHaveAttribute('aria-sort', 'ascending')
  })

  it('releases the previous column, so the row never shows two live sort states', () => {
    render(<MetricTable head={HEAD} rows={ROWS} values={VALUES} initialSort={{ column: 1, dir: 'desc' }} />)

    fireEvent.click(screen.getByTestId('sort-2'))

    expect(screen.getAllByRole('columnheader')[1]).not.toHaveAttribute('aria-sort')
    expect(screen.getAllByRole('columnheader')[2]).toHaveAttribute('aria-sort', 'descending')
  })

  /** «Snapchat does not report ROAS» is not the worst ROAS, and it is not the best either. */
  it('keeps an absent figure last whichever way the column points', () => {
    render(<MetricTable head={HEAD} rows={ROWS} values={VALUES} initialSort={{ column: 2, dir: 'desc' }} />)
    expect(names().at(-1)).toBe('Snapchat')

    fireEvent.click(screen.getByTestId('sort-2'))
    expect(names().at(-1)).toBe('Snapchat')
  })

  it('shows the same rows after sorting, never a different set', () => {
    render(<MetricTable head={HEAD} rows={ROWS} values={VALUES} />)

    fireEvent.click(screen.getByTestId('sort-1'))
    expect([...names()].sort()).toEqual(['Meta', 'Snapchat', 'TikTok'])
  })
})
