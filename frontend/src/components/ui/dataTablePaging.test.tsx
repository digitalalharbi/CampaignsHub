import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { DataTable, pageNumbers, type Column, type TableState } from './DataTable'

/** TABLE-SERVER-PAGING-001 — the shared table pages 10/25/50, folds long values, keeps state, and can be server-paged. */
interface Row { id: string; name: string; n: number }
const rows: Row[] = Array.from({ length: 60 }, (_, i) => ({ id: `r${i + 1}`, name: `utm_campaign_${'x'.repeat(40)}_${i + 1}`, n: i + 1 }))
const columns: Column<Row>[] = [
  { key: 'name', header: 'Name', sortable: true, truncate: 18 },
  { key: 'n', header: 'N', sortable: true, align: 'end' },
]

describe('DataTable paging', () => {
  it('defaults to 10 rows and offers exactly 10, 25 and 50', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />)
    expect(document.querySelectorAll('tbody tr')).toHaveLength(10)
    const options = within(screen.getByTestId('table-page-size')).getAllByRole('option').map((o) => o.textContent)
    expect(options).toEqual(['10', '25', '50'])
    fireEvent.change(screen.getByTestId('table-page-size'), { target: { value: '25' } })
    expect(document.querySelectorAll('tbody tr')).toHaveLength(25)
  })

  it('shows the total, page numbers, and moves with previous and next', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />)
    expect(screen.getByTestId('table-total')).toHaveTextContent('1–10 / 60 total')
    fireEvent.click(screen.getByRole('button', { name: 'Page 2' }))
    expect(screen.getByTestId('table-total')).toHaveTextContent('11–20 / 60 total')
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(screen.getByTestId('table-total')).toHaveTextContent('21–30 / 60 total')
    fireEvent.click(screen.getByRole('button', { name: 'Previous' }))
    expect(screen.getByTestId('table-total')).toHaveTextContent('11–20 / 60 total')
    fireEvent.click(screen.getByRole('button', { name: 'Page 6' }))
    expect(screen.getByTestId('table-total')).toHaveTextContent('51–60 / 60 total')
  })

  it('builds a windowed list of page numbers with gaps', () => {
    expect(pageNumbers(1, 3)).toEqual([1, 2, 3])
    expect(pageNumbers(10, 20)).toEqual([1, null, 9, 10, 11, null, 20])
  })

  it('folds a long value and opens the full text on demand', () => {
    render(<DataTable columns={columns} rows={rows.slice(0, 1)} rowKey={(r) => r.id} searchable={false} />)
    const cell = screen.getByRole('button', { name: 'Show full value' })
    expect(cell.textContent?.endsWith('…')).toBe(true)
    expect(cell).toHaveAttribute('title', rows[0].name)
    fireEvent.click(cell)
    expect(screen.getByRole('button', { name: 'Fold value' })).toHaveTextContent(rows[0].name)
  })

  it('keeps page, size and sort across a remount when given a state key', () => {
    window.sessionStorage.clear()
    const first = render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} stateKey="t1" />)
    fireEvent.change(screen.getByTestId('table-page-size'), { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Page 2' }))
    first.unmount()
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} stateKey="t1" />)
    expect(screen.getByTestId('table-total')).toHaveTextContent('26–50 / 60 total')
    expect((screen.getByTestId('table-page-size') as HTMLSelectElement).value).toBe('25')
  })

  it('in server mode shows only the rows it was given, reports changes, and never slices or filters', () => {
    const onChange = vi.fn<(s: TableState) => void>()
    const page = rows.slice(10, 20)
    render(
      <DataTable columns={columns} rows={page} rowKey={(r) => r.id}
        server={{ total: 60, state: { page: 2, pageSize: 10, sort: null, query: '' }, onChange }} />,
    )
    expect(document.querySelectorAll('tbody tr')).toHaveLength(10)
    expect(screen.getByTestId('table-total')).toHaveTextContent('11–20 / 60 total')
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(onChange).toHaveBeenLastCalledWith({ page: 3, pageSize: 10, sort: null, query: '' })
    fireEvent.click(screen.getByRole('button', { name: 'N' }))
    expect(onChange).toHaveBeenLastCalledWith({ page: 1, pageSize: 10, sort: { key: 'n', dir: 'asc' }, query: '' })
    fireEvent.change(screen.getByLabelText('Search…'), { target: { value: 'zzz' } })
    expect(onChange).toHaveBeenLastCalledWith({ page: 1, pageSize: 10, sort: null, query: 'zzz' })
    expect(document.querySelectorAll('tbody tr')).toHaveLength(10)
  })

  describe('column show and hide', () => {
    const three: Column<Row>[] = [...columns, { key: 'extra', header: 'Extra', value: () => 'e', hideable: true, defaultHidden: true }]

    it('offers only the columns that may hide, and hiding one leaves the rows intact', () => {
      render(<DataTable columns={three} rows={rows} rowKey={(r) => r.id} columnPicker />)
      expect(screen.queryByRole('columnheader', { name: 'Extra' })).toBeNull()
      fireEvent.click(screen.getByTestId('table-columns'))
      expect(screen.queryByRole('checkbox', { name: 'N' })).toBeNull()
      fireEvent.click(screen.getByRole('checkbox', { name: 'Extra' }))
      expect(screen.getByRole('columnheader', { name: 'Extra' })).toBeInTheDocument()
      fireEvent.click(screen.getByRole('checkbox', { name: 'Extra' }))
      expect(screen.queryByRole('columnheader', { name: 'Extra' })).toBeNull()
      expect(document.querySelectorAll('tbody tr')).toHaveLength(10)
    })

    it('remembers the choice for the session under its state key', () => {
      window.sessionStorage.clear()
      const { unmount } = render(<DataTable columns={three} rows={rows} rowKey={(r) => r.id} columnPicker stateKey="cols" />)
      fireEvent.click(screen.getByTestId('table-columns'))
      fireEvent.click(screen.getByRole('checkbox', { name: 'Extra' }))
      unmount()
      render(<DataTable columns={three} rows={rows} rowKey={(r) => r.id} columnPicker stateKey="cols" />)
      expect(screen.getByRole('columnheader', { name: 'Extra' })).toBeInTheDocument()
    })
  })

  describe('row expansion', () => {
    it('opens one row in place on demand, spanning the shown columns', () => {
      render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} expandRow={(r) => <p>detail of {r.id}</p>} />)
      expect(screen.queryByText('detail of r1')).toBeNull()
      const first = screen.getAllByTestId('table-expand')[0]
      expect(first).toHaveAttribute('aria-expanded', 'false')
      fireEvent.click(first)
      expect(first).toHaveAttribute('aria-expanded', 'true')
      const cell = screen.getByText('detail of r1').closest('td')
      expect(cell).toHaveAttribute('colspan', '3')
      fireEvent.click(first)
      expect(screen.queryByText('detail of r1')).toBeNull()
    })

    it('adds no column when no expansion is asked for', () => {
      render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />)
      expect(screen.queryByTestId('table-expand')).toBeNull()
      expect(screen.getAllByRole('columnheader')).toHaveLength(2)
    })
  })
})
