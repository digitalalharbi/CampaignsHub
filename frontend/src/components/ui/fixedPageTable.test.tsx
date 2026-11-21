import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { FIXED_ROW_HEIGHT, FixedPageTable, fixedTableHeight, type FixedColumn } from './FixedPageTable'

/**
 * TABLE-FIXED-PAGE-001 — moving to another page never changes the table's box.
 *
 * jsdom lays nothing out, so the box is held by what DETERMINES it: the stated height of the
 * container, the number of rows and each row's stated height. The browser gate measures the pixels.
 */
interface R { name: string; n: number | null }
const columns: FixedColumn<R>[] = [
  { key: 'name', header: 'Name', width: '60%', value: (r) => r.name, title: (r) => r.name, render: (r) => r.name },
  { key: 'n', header: 'N', width: '40%', align: 'end', value: (r) => r.n, render: (r) => (r.n === null ? '—' : String(r.n)) },
]
const rows: R[] = Array.from({ length: 19 }, (_, i) => ({ name: `row-${String(i + 1).padStart(2, '0')}`, n: i === 4 ? null : (i + 1) * 10 }))

const shape = () => {
  const box = screen.getByTestId('t-box')
  const body = box.querySelectorAll('tbody tr')

  return {
    height: box.style.height,
    rows: body.length,
    rowHeights: new Set(Array.from(body).map((tr) => (tr as HTMLElement).style.height)),
    filled: box.querySelectorAll('tbody tr[data-row="filled"]').length,
    columnWidths: Array.from(box.querySelectorAll('col')).map((c) => (c as HTMLElement).style.width).join(','),
    layout: (box.querySelector('table') as HTMLElement).style.tableLayout,
    pager: screen.getByTestId('t-pager').style.height,
  }
}

describe('a fixed-page table', () => {
  it('keeps the same height, row count, row height and column widths on the first and the last page', () => {
    render(<FixedPageTable testId="t" columns={columns} rows={rows} rowKey={(r) => r.name} pageSize={8} />)

    const first = shape()
    expect(first).toMatchObject({ height: `${fixedTableHeight(8) + 2}px`, rows: 8, filled: 8, layout: 'fixed', columnWidths: '60%,40%' })
    expect(first.rowHeights).toEqual(new Set([`${FIXED_ROW_HEIGHT}px`]))
    expect(screen.getByTestId('t-page')).toHaveTextContent('1 / 3')
    expect(screen.getByTestId('t-range')).toHaveTextContent('1–8 / 19')

    fireEvent.click(screen.getByTestId('t-next'))
    fireEvent.click(screen.getByTestId('t-next'))

    const last = shape()
    expect(screen.getByTestId('t-page')).toHaveTextContent('3 / 3')
    expect(screen.getByTestId('t-range')).toHaveTextContent('17–19 / 19')
    // Three real rows and five padded ones: the page is short, the box is not.
    expect(last.filled).toBe(3)
    expect({ ...last, filled: 0 }).toEqual({ ...first, filled: 0 })
    expect(screen.getByTestId('t-next')).toBeDisabled()
  })

  it('pads a table shorter than one page, and still draws the pager so two tables stand the same height', () => {
    render(<FixedPageTable testId="t" columns={columns} rows={rows.slice(0, 2)} rowKey={(r) => r.name} pageSize={8} />)

    expect(shape()).toMatchObject({ height: `${fixedTableHeight(8) + 2}px`, rows: 8, filled: 2 })
    expect(screen.getByTestId('t-page')).toHaveTextContent('1 / 1')
    expect(screen.getByTestId('t-prev')).toBeDisabled()
    expect(screen.getByTestId('t-next')).toBeDisabled()
  })

  it('cuts a long value on one line and keeps it whole in the title', () => {
    const long = 'utm_campaign=a-very-long-campaign-name-that-would-wrap-a-row-onto-three-lines'
    render(<FixedPageTable testId="t" columns={columns} rows={[{ name: long, n: 1 }]} rowKey={(r) => r.name} />)

    const cell = within(screen.getByTestId('t-box')).getByText(long)
    expect(cell).toHaveAttribute('title', long)
    expect(cell.className).toContain('whitespace-nowrap')
    expect(cell.className).toContain('text-ellipsis')
  })

  it('sorts and searches without changing the box, and puts an absent figure last in both directions', () => {
    render(<FixedPageTable testId="t" columns={columns} rows={rows} rowKey={(r) => r.name} pageSize={8} searchable />)
    const before = shape()
    const names = () => Array.from(screen.getByTestId('t-box').querySelectorAll('tbody tr[data-row="filled"] td:first-child')).map((td) => td.textContent)

    fireEvent.click(screen.getByTestId('t-sort-n'))
    expect(names()[0]).toBe('row-19')
    fireEvent.click(screen.getByTestId('t-sort-n'))
    expect(names()[0]).toBe('row-01')
    fireEvent.click(screen.getByTestId('t-next'))
    fireEvent.click(screen.getByTestId('t-next'))
    expect(names().at(-1)).toBe('row-05')

    fireEvent.change(screen.getByTestId('t-search'), { target: { value: 'row-1' } })
    expect(screen.getByTestId('t-range')).toHaveTextContent('1–8 / 10')
    expect({ ...shape(), filled: 0 }).toEqual({ ...before, filled: 0 })

    fireEvent.change(screen.getByTestId('t-search'), { target: { value: 'no such row' } })
    expect(shape()).toMatchObject({ rows: 8, filled: 0, height: before.height })
    expect(screen.getByTestId('t-range')).toHaveTextContent('0–0 / 0')
  })

  it('is one page with no pager on a printed sheet', () => {
    render(<FixedPageTable testId="t" columns={columns} rows={rows.slice(0, 5)} rowKey={(r) => r.name} pageSize={8} pager={false} />)

    expect(screen.queryByTestId('t-pager')).toBeNull()
    expect(screen.getByTestId('t-box').querySelectorAll('tbody tr')).toHaveLength(8)
  })
})
