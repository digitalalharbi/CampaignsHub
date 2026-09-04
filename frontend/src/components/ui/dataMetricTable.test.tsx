import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { DataMetricTable, type Column } from './MetricTable'

/**
 * TABLE-NUMERIC-ALIGNMENT-001 — the primitive owns the formatting, not only the layout.
 *
 * Every analytical surface used to format its own cells, so the same figure was compact on one
 * screen and exact on the next, a missing value was «—» here and «0» there, and a currency was
 * appended by whichever page remembered. Owning the layout and not the content is owning the half a
 * reader does not see.
 *
 * These tests are about what a COLUMN means, because that is what a page can no longer get wrong.
 */
const columns: Column[] = [
  { key: 'name', label: 'Campaign', kind: 'text' },
  { key: 'spend', label: 'Spend', kind: 'money', currency: 'USD' },
  { key: 'results', label: 'Results', kind: 'number' },
  { key: 'ctr', label: 'CTR', kind: 'percent' },
  { key: 'roas', label: 'ROAS', kind: 'ratio' },
]

/**
 * The row's cells INCLUDING its header cell, in column order.
 *
 * The first column is a `<th scope="row">`: it labels the row, and a screen reader moving down a
 * column needs to be told which row a figure belongs to. That makes it a `rowheader` rather than a
 * `cell`, so a query for cells alone silently drops it and every index after it shifts by one —
 * which is exactly what happened when the primitive gained the header, and why this helper exists
 * rather than each test reaching for one role.
 */
const cellsOf = (rowIndex: number): string[] => {
  const row = within(screen.getAllByRole('row')[rowIndex + 1])

  return [...row.queryAllByRole('rowheader'), ...row.getAllByRole('cell')].map((c) => c.textContent ?? '')
}

describe('a table that owns its own formatting', () => {
  it('prints each kind the one way the product prints it', () => {
    render(
      <DataMetricTable
        columns={columns}
        rows={[{ name: 'Eid', spend: 12_400, results: 1_234_567, ctr: 0.0432, roas: 3.2 }]}
      />,
    )

    const [name, spend, results, ctr, roas] = cellsOf(0)

    expect(name).toBe('Eid')
    expect(spend).toContain('USD')
    expect(results).toMatch(/1\.23M|1,234,567/)
    expect(ctr).toContain('%')
    expect(roas).toContain('×')
  })

  /**
   * A missing figure is «—» in every column of every table.
   *
   * Not «0», which is a measurement, and not blank, which reads as a rendering fault. The dash is
   * the only one of the three that says «nobody reported this», and one meaning per glyph is the
   * whole point of a shared primitive.
   */
  it('shows a missing figure as a dash, never as zero and never as nothing', () => {
    render(
      <DataMetricTable
        columns={columns}
        rows={[{ name: 'Quiet', spend: null, results: undefined, ctr: null, roas: null }]}
      />,
    )

    const [, spend, results, ctr, roas] = cellsOf(0)

    for (const cell of [spend, results, ctr, roas]) {
      expect(cell).toBe('—')
    }
  })

  /** Zero is a measurement and keeps its own reading — “spent nothing” is not “nobody said”. */
  it('keeps a real zero as a zero', () => {
    render(<DataMetricTable columns={columns} rows={[{ name: 'Paused', spend: 0, results: 0 }]} />)

    const [, spend, results] = cellsOf(0)

    expect(spend).not.toBe('—')
    expect(results).toBe('0')
  })

  /**
   * The money contract reaches the tables: no currency means the figure goes out BARE.
   *
   * «18.05 SAR» on a USD account is a different number wearing a label, and a table is exactly the
   * surface where a reader would never think to check.
   */
  it('prints money with no currency bare rather than under a guessed one', () => {
    render(
      <DataMetricTable
        columns={[{ key: 'spend', label: 'Spend', kind: 'money', currency: null }]}
        rows={[{ spend: 1200 }]}
      />,
    )

    expect(cellsOf(0)[0]).not.toMatch(/[A-Z]{3}/)
  })

  /** The exact figure travels with the abbreviation — and only where the two differ. */
  it('reveals the exact figure behind an abbreviation, and adds no tooltip where there is none', () => {
    render(
      <DataMetricTable
        columns={[{ key: 'n', label: 'Results', kind: 'number' }]}
        rows={[{ n: 1_234_567 }, { n: 42 }]}
      />,
    )

    const big = screen.getAllByRole('row')[1].querySelector('td')
    const small = screen.getAllByRole('row')[2].querySelector('td')

    expect(big).toHaveAttribute('title', '1,234,567')
    expect(small).not.toHaveAttribute('title')
  })

  /**
   * Numbers are centred and text starts — the alignment the owner has reported five times.
   *
   * Under `dir="rtl"`, `text-end` is the LEFT edge of the cell, so a figure sits as far from its
   * Arabic heading as the column is wide and reads as belonging to no column at all. Centring makes
   * header and body share one alignment in both directions.
   */
  it('centres every numeric column and starts the text one', () => {
    render(<DataMetricTable columns={columns} rows={[{ name: 'Eid', spend: 1, results: 1, ctr: 0.1, roas: 1 }]} />)

    // `th, td` in document order: the first column is the row's header cell — see `cellsOf`.
    const cells = screen.getAllByRole('row')[1].querySelectorAll('th, td')

    expect(cells[0].className).toContain('text-start')
    for (const i of [1, 2, 3, 4]) {
      expect(cells[i].className, `column ${i} is numeric and must be centred`).toContain('text-center')
      expect(cells[i].className).toContain('tnum')
    }
  })

  /** A cell that is genuinely a control is passed through, not abbreviated. */
  it('passes a node through untouched', () => {
    render(
      <DataMetricTable
        columns={[{ key: 'state', label: 'State', kind: 'text' }]}
        rows={[{ state: <span data-testid="pill">Active</span> }]}
      />,
    )

    expect(screen.getByTestId('pill')).toBeInTheDocument()
  })

  /** A missing figure sorts LAST in both directions: an absence is not the cheapest cost. */
  it('never lets an absent figure win a sort', () => {
    render(
      <DataMetricTable
        columns={[{ key: 'name', label: 'Name', kind: 'text' }, { key: 'cpm', label: 'CPM', kind: 'money', currency: 'USD' }]}
        rows={[{ name: 'A', cpm: null }, { name: 'B', cpm: 9 }, { name: 'C', cpm: 4 }]}
        initialSort={{ column: 1, dir: 'asc' }}
      />,
    )

    const first = within(screen.getAllByRole('row')[1]).getByRole('rowheader').textContent

    expect(first, 'the row with no CPM was sorted as the cheapest').toBe('C')
  })

  /**
   * The defect the owner has reported five times, pinned in the direction it happens.
   *
   * Under `dir="rtl"` a `text-end` cell puts its digits against the LEFT edge — as far from its
   * Arabic heading as the column is wide — and a `text-start` cell puts them against the right,
   * which is why the same product had numbers drifting in both directions on different screens. A
   * centred header over a centred cell is the only pairing that reads as one column in both
   * writing directions, so the assertion is that HEADER AND CELL SHARE an alignment, not that
   * either has a particular one.
   */
  it('gives a numeric header and its cells the same alignment, in both writing directions', () => {
    for (const dir of ['rtl', 'ltr'] as const) {
      document.documentElement.dir = dir

      const { unmount } = render(
        <DataMetricTable columns={columns} rows={[{ name: 'Eid', spend: 10, results: 5, ctr: 0.1, roas: 2 }]} />,
      )

      const heads = screen.getAllByRole('columnheader')
      const cells = screen.getAllByRole('row')[1].querySelectorAll('th, td')

      for (let i = 1; i < columns.length; i++) {
        const headAlign = heads[i].className.match(/text-(start|center|end)/)?.[1]
        const cellAlign = cells[i].className.match(/text-(start|center|end)/)?.[1]

        expect(
          cellAlign,
          `column ${i} (${columns[i].label}) drifts from its header under ${dir}`,
        ).toBe(headAlign)
        expect(cellAlign, 'a numeric column must be centred so it reads the same in both directions').toBe('center')
      }

      unmount()
    }

    document.documentElement.dir = 'ltr'
  })

  /** The scroller clips instead of pushing the page sideways — the phone case the gate caught before. */
  it('scrolls inside its own container rather than widening the page', () => {
    const { container } = render(<DataMetricTable columns={columns} rows={[{ name: 'Eid', spend: 1 }]} />)
    const scroller = container.querySelector('div')

    expect(scroller?.className).toContain('overflow-x-auto')
    expect(scroller?.className, 'a grid item defaults to min-width:auto and grows instead of clipping').toContain('min-w-0')
  })
})
