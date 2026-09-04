import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { TransposedMetricTable } from './MetricTable'

/**
 * TABLE-NUMERIC-ALIGNMENT-001 — the transposed shape obeys the SAME law as the upright one.
 *
 * Three surfaces read metrics-down-the-side and all three hand-rolled it, each making its own
 * alignment decision: one centres, one ends, one starts with a hard `dir="ltr"`. The point of moving
 * them into the primitive is not that they share code — it is that they stop answering the same
 * question three ways.
 */
const row = (over: Partial<Parameters<typeof TransposedMetricTable>[0]['rows'][number]> = {}) => ({
  key: 'spend',
  label: 'Spend',
  kind: 'money' as const,
  currency: 'SAR',
  values: [1000, 2000],
  ...over,
})

const columns = [
  { key: 'image', header: 'Image' },
  { key: 'video', header: 'Video' },
]

describe('the transposed table', () => {
  /** The whole claim, and the one that is invisible in a single direction. */
  it('centres a header over its own figures', () => {
    render(<TransposedMetricTable columns={columns} rows={[row()]} />)

    const heads = [...screen.getByRole('table').querySelectorAll('thead th')].slice(1)
    const cells = [...screen.getByRole('table').querySelectorAll('tbody td')]

    for (const [i, head] of heads.entries()) {
      expect(head.className, 'a value header that is not centred drifts off its column under RTL')
        .toContain('text-center')
      expect(cells[i].className).toContain('text-center')
    }
  })

  /** The metric's NAME is prose, and prose is read from the start in whichever direction. */
  it('starts the row label, because it is read rather than compared', () => {
    render(<TransposedMetricTable columns={columns} rows={[row()]} />)

    expect(screen.getByRole('rowheader').className).toContain('text-start')
  })

  it('formats by the row’s kind, not the column’s', () => {
    render(
      <TransposedMetricTable
        columns={columns}
        rows={[row(), row({ key: 'ctr', label: 'CTR', kind: 'percent', currency: null, values: [0.0812, 0.1] })]}
      />,
    )

    const cells = screen.getAllByRole('cell')

    expect(cells[0]).toHaveTextContent('SAR')
    expect(cells[2]).toHaveTextContent('%')
  })

  /** The same dash as everywhere else — a missing figure is not a zero and not a blank. */
  it('prints a missing figure as the product’s one dash', () => {
    render(<TransposedMetricTable columns={columns} rows={[row({ values: [null, 0] })]} />)

    const cells = screen.getAllByRole('cell')

    expect(cells[0]).toHaveTextContent('—')
    expect(cells[1], 'a real zero is a measurement').not.toHaveTextContent('—')
  })

  /** The surface decides what «best» means; the primitive only renders that it is. */
  it('emphasises the cell the surface named best', () => {
    render(<TransposedMetricTable columns={columns} rows={[row({ emphasis: [false, true] })]} />)

    const cells = screen.getAllByRole('cell')

    expect(cells[1].className).toContain('font-semibold')
    expect(cells[0].className).not.toContain('font-semibold')
  })

  /** «12 per lead» — the sub-label rides under the figure rather than beside it. */
  it('carries a per-cell note under the figure', () => {
    render(<TransposedMetricTable columns={columns} rows={[row({ notes: ['per lead', 'per lead'] })]} />)

    expect(screen.getAllByText('per lead')).toHaveLength(2)
  })

  /** Wide content scrolls inside its own container so the page never does. */
  it('scrolls inside itself', () => {
    const { container } = render(<TransposedMetricTable columns={columns} rows={[row()]} />)

    expect(container.firstElementChild?.className).toContain('overflow-x-auto')
    expect(container.firstElementChild?.className).toContain('min-w-0')
  })
})
