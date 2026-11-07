import { useMemo, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Search } from 'lucide-react'

/**
 * TABLE-FIXED-PAGE-001 — a paged table whose box never changes between pages.
 *
 * The Owner's rule: moving to another page of a table must not change the dimensions of the preview
 * or the screen. Three things make a table's box move, and each is pinned here:
 *
 *   - the ROW COUNT — the last page is padded with empty rows up to the page size;
 *   - the ROW HEIGHT — every row is one fixed-height line; a long value is cut with an ellipsis and
 *     kept whole in its `title`, never wrapped;
 *   - the COLUMN WIDTHS — `table-layout: fixed` with stated widths, so a page of long names cannot
 *     widen a column the page before it kept narrow.
 *
 * The pager is one fixed-height line under the table and is drawn even for a single page, so a table
 * of three rows and a table of three hundred stand the same height side by side. Sorting and search
 * act on the rows the owner handed over; they never change the box either (no match is a full page
 * of empty rows with a dash).
 */
export interface FixedColumn<T> {
  key: string
  header: string
  /** A CSS width; the widths of a table should add up to 100%. */
  width: string
  align?: 'start' | 'end'
  render: (row: T) => ReactNode
  /** What the column sorts and searches by. A column without it is not sortable. */
  value?: (row: T) => string | number | null
  /** The whole value, shown on hover where the cell cuts it. */
  title?: (row: T) => string
}

export const FIXED_ROW_HEIGHT = 44
export const FIXED_HEAD_HEIGHT = 40
export const FIXED_PAGER_HEIGHT = 36

/** The table's own height for a page size: the number a test can hold a page change against. */
export function fixedTableHeight(pageSize: number): number {
  return FIXED_HEAD_HEIGHT + pageSize * FIXED_ROW_HEIGHT
}

export function FixedPageTable<T>({
  columns,
  rows,
  rowKey,
  pageSize = 8,
  testId,
  ar = false,
  searchable = false,
  minWidth = 520,
  initialSort,
  pager = true,
}: {
  columns: FixedColumn<T>[]
  rows: T[]
  rowKey: (row: T, index: number) => string
  pageSize?: number
  testId: string
  ar?: boolean
  searchable?: boolean
  /** Below this the table scrolls sideways inside its own box instead of squeezing its columns. */
  minWidth?: number
  initialSort?: { key: string; dir: 'asc' | 'desc' }
  /** Off on a printed page, which is one page by construction: the rows are the page. */
  pager?: boolean
}) {
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(initialSort ?? null)
  const [query, setQuery] = useState('')

  const shown = useMemo(() => {
    let out = rows
    const q = query.trim().toLowerCase()
    if (q !== '') {
      out = out.filter((row) => columns.some((c) => typeof c.value?.(row) === 'string' && String(c.value(row)).toLowerCase().includes(q)))
    }
    const col = sort ? columns.find((c) => c.key === sort.key) : undefined
    if (sort && col?.value) {
      const read = col.value
      out = [...out].sort((a, b) => {
        const av = read(a)
        const bv = read(b)
        // Absent sorts last in both directions: a missing figure is not the smallest figure.
        if (av === null || bv === null) return av === bv ? 0 : av === null ? 1 : -1
        const cmp = av < bv ? -1 : av > bv ? 1 : 0

        return sort.dir === 'asc' ? cmp : -cmp
      })
    }

    return out
  }, [rows, query, sort, columns])

  const pages = Math.max(1, Math.ceil(shown.length / pageSize))
  const current = Math.min(page, pages)
  const pageRows = shown.slice((current - 1) * pageSize, current * pageSize)
  const fillers = pageSize - pageRows.length
  const from = shown.length === 0 ? 0 : (current - 1) * pageSize + 1
  const to = Math.min(current * pageSize, shown.length)
  const align = (a?: 'start' | 'end') => (a === 'end' ? 'text-end' : 'text-start')
  const Prev = ar ? ChevronRight : ChevronLeft
  const Next = ar ? ChevronLeft : ChevronRight

  return (
    <div data-testid={testId} className="min-w-0 max-w-full">
      {searchable && (
        <div className="relative mb-2 h-9 max-w-[14rem]">
          <Search size={14} className="pointer-events-none absolute inset-y-0 start-2.5 my-auto text-text-muted" />
          <input
            value={query}
            onChange={(e) => { setQuery(e.target.value); setPage(1) }}
            aria-label={ar ? 'بحث' : 'Search'}
            placeholder={ar ? 'بحث' : 'Search'}
            data-testid={`${testId}-search`}
            className="h-9 w-full rounded-[10px] border border-border bg-surface ps-8 pe-2 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
          />
        </div>
      )}

      <div
        data-testid={`${testId}-box`}
        data-page={current}
        className="max-w-full overflow-x-auto overflow-y-hidden rounded-xl border border-border bg-surface"
        style={{ height: fixedTableHeight(pageSize) + 2 }}
      >
        <table className="w-full border-collapse text-sm" style={{ tableLayout: 'fixed', minWidth }}>
          <colgroup>
            {columns.map((c) => <col key={c.key} style={{ width: c.width }} />)}
          </colgroup>
          <thead>
            <tr className="border-b border-border bg-surface-secondary" style={{ height: FIXED_HEAD_HEIGHT }}>
              {columns.map((c) => (
                <th key={c.key} scope="col" aria-sort={sort?.key === c.key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                  className={`overflow-hidden whitespace-nowrap px-3 text-xs font-semibold text-text-secondary ${align(c.align)}`}>
                  {c.value ? (
                    <button type="button" data-testid={`${testId}-sort-${c.key}`} className="inline-flex max-w-full items-center gap-1 hover:text-text-primary"
                      onClick={() => { setSort(sort?.key === c.key ? { key: c.key, dir: sort.dir === 'asc' ? 'desc' : 'asc' } : { key: c.key, dir: 'desc' }); setPage(1) }}>
                      <span className="truncate">{c.header}</span>
                      {sort?.key === c.key && (sort.dir === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)}
                    </button>
                  ) : c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pageRows.map((row, i) => (
              <tr key={rowKey(row, i)} data-row="filled" className="border-b border-border last:border-0 hover:bg-surface-secondary" style={{ height: FIXED_ROW_HEIGHT }}>
                {columns.map((c) => (
                  <td key={c.key} title={c.title?.(row)} className={`overflow-hidden text-ellipsis whitespace-nowrap px-3 text-text-primary ${align(c.align)}`}>
                    {c.render(row)}
                  </td>
                ))}
              </tr>
            ))}
            {Array.from({ length: fillers }).map((_, i) => (
              <tr key={`filler-${i}`} data-row="filler" aria-hidden="true" className="border-b border-border last:border-0" style={{ height: FIXED_ROW_HEIGHT }}>
                <td colSpan={columns.length} className="px-3 text-center text-text-muted">{shown.length === 0 && i === 0 ? '—' : ' '}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {pager && <div data-testid={`${testId}-pager`} className="mt-2 flex items-center justify-between gap-2 text-xs text-text-secondary" style={{ height: FIXED_PAGER_HEIGHT }}>
        <span className="tnum whitespace-nowrap" dir="ltr" data-testid={`${testId}-range`}>{from}–{to} / {shown.length}</span>
        <nav className="flex items-center gap-1" aria-label={ar ? 'الصفحات' : 'Pages'}>
          <button type="button" data-testid={`${testId}-prev`} disabled={current <= 1} onClick={() => setPage(current - 1)} aria-label={ar ? 'السابق' : 'Previous'}
            className="grid h-8 w-8 place-items-center rounded-[10px] border border-border text-text-primary disabled:opacity-40">
            <Prev size={15} />
          </button>
          <span className="tnum min-w-[3.5rem] text-center font-semibold text-text-primary" dir="ltr" data-testid={`${testId}-page`}>{current} / {pages}</span>
          <button type="button" data-testid={`${testId}-next`} disabled={current >= pages} onClick={() => setPage(current + 1)} aria-label={ar ? 'التالي' : 'Next'}
            className="grid h-8 w-8 place-items-center rounded-[10px] border border-border text-text-primary disabled:opacity-40">
            <Next size={15} />
          </button>
        </nav>
      </div>}
    </div>
  )
}
