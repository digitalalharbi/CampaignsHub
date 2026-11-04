import { Fragment, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, Columns3, Search } from 'lucide-react'
import { Input } from './Input'
import { EmptyState, ErrorState, Skeleton } from './States'

export interface Column<T> {
  key: string
  header: string
  align?: 'start' | 'end' | 'center'
  sortable?: boolean
  /** Custom cell renderer; falls back to `row[key]`. */
  render?: (row: T) => ReactNode
  /** Value used for sorting/searching; falls back to `row[key]`. */
  value?: (row: T) => string | number
  /**
   * Long values (UTM strings, campaign names) are cut to this many characters with the full text on
   * demand: tap the cell to open it, hover for the title. The value is never lost, only folded.
   */
  truncate?: number
  /** The reader may hide this column (`columnPicker` on the table offers it). A key column stays. */
  hideable?: boolean
  /** Starts hidden until the reader asks for it. */
  defaultHidden?: boolean
}

/** TABLE-SERVER-PAGING-001 — the state a server-paged table reports back to its owner. */
export interface TableState {
  page: number
  pageSize: number
  sort: { key: string; dir: 'asc' | 'desc' } | null
  query: string
}

/**
 * When present the table is SERVER-paged: `rows` is the current page only, `total` is the whole
 * count, and sort / search / page changes are reported through `onChange` instead of being applied
 * to `rows` here. The owner reads the next page and scopes it; this component never filters rows it
 * was not given.
 */
export interface ServerPaging {
  total: number
  state: TableState
  onChange: (next: TableState) => void
}

export interface TableLabels {
  search: string
  rowsPerPage: string
  previous: string
  next: string
  total: string
  showFull: string
  hideFull: string
  page: string
  columns: string
  expandRow: string
  collapseRow: string
}

const EN: TableLabels = { search: 'Search…', rowsPerPage: 'Rows per page', previous: 'Previous', next: 'Next', total: 'total', showFull: 'Show full value', hideFull: 'Fold value', page: 'Page', columns: 'Columns', expandRow: 'Show details', collapseRow: 'Hide details' }

export const PAGE_SIZE_OPTIONS = [10, 25, 50] as const

/** The page numbers to draw: first, last, and a window around the current one, with gaps as null. */
export function pageNumbers(current: number, pages: number): Array<number | null> {
  const keep = new Set<number>([1, pages, current - 1, current, current + 1])
  const list = [...keep].filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b)
  const out: Array<number | null> = []
  list.forEach((n, i) => {
    if (i > 0 && n - list[i - 1] > 1) out.push(null)
    out.push(n)
  })
  return out
}

function readSaved(key: string | undefined): Partial<TableState> | null {
  if (!key) return null
  try {
    const raw = window.sessionStorage.getItem(`table:${key}`)
    return raw ? (JSON.parse(raw) as Partial<TableState>) : null
  } catch {
    return null
  }
}

function writeSaved(key: string | undefined, state: TableState): void {
  if (!key) return
  try {
    window.sessionStorage.setItem(`table:${key}`, JSON.stringify(state))
  } catch {
    /* storage blocked: the table works, it just does not remember */
  }
}

function readColumns(key: string | undefined): Record<string, boolean> {
  if (!key) return {}
  try {
    const raw = window.sessionStorage.getItem(`table-columns:${key}`)
    return raw ? (JSON.parse(raw) as Record<string, boolean>) : {}
  } catch {
    return {}
  }
}

function FoldedCell({ text, max, labels }: { text: string; max: number; labels: TableLabels }) {
  const [open, setOpen] = useState(false)
  if (text.length <= max) return <>{text}</>
  return (
    <button
      type="button"
      title={text}
      aria-expanded={open}
      aria-label={open ? labels.hideFull : labels.showFull}
      onClick={() => setOpen((o) => !o)}
      data-folded={open ? 'open' : 'closed'}
      className="max-w-full cursor-pointer break-all text-start hover:text-brand-600"
      dir="auto"
    >
      {open ? text : `${text.slice(0, max)}…`}
    </button>
  )
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string
  loading?: boolean
  error?: boolean
  onRetry?: () => void
  searchable?: boolean
  pageSize?: number
  pageSizeOptions?: readonly number[]
  emptyTitle?: string
  /** Server-side paging, sort and search. Omit for the original in-memory behaviour. */
  server?: ServerPaging
  /** Keeps page, size, sort and search for this table across navigation and return (session only). */
  stateKey?: string
  labels?: Partial<TableLabels>
  /** Scroll inside the table with a sticky header once it is taller than this (CSS length). */
  maxHeight?: string
  /** Offers the `hideable` columns to show or hide; the choice is kept for the session under `stateKey`. */
  columnPicker?: boolean
  /** More about one row, opened in place on demand rather than widening the table. */
  expandRow?: (row: T) => ReactNode
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  onRetry,
  searchable = true,
  pageSize: initialSize = 10,
  pageSizeOptions = PAGE_SIZE_OPTIONS,
  emptyTitle = 'No records yet',
  server,
  stateKey,
  labels: given,
  maxHeight,
  columnPicker,
  expandRow,
}: DataTableProps<T>) {
  const labels = { ...EN, ...given }
  const saved = useRef(readSaved(stateKey)).current
  const [localQuery, setQuery] = useState(saved?.query ?? '')
  const [localSort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(saved?.sort ?? null)
  const [localPage, setPage] = useState(saved?.page ?? 1)
  const [localSize, setSize] = useState(saved?.pageSize && pageSizeOptions.includes(saved.pageSize) ? saved.pageSize : initialSize)

  const [columnChoice, setColumnChoice] = useState<Record<string, boolean>>(() => readColumns(stateKey))
  const [pickerOpen, setPickerOpen] = useState(false)
  const [expanded, setExpanded] = useState<string | null>(null)
  const shownColumns = columns.filter((c) => !c.hideable || (columnChoice[c.key] ?? !c.defaultHidden))
  const toggleColumn = (key: string, on: boolean) => {
    const next = { ...columnChoice, [key]: on }
    setColumnChoice(next)
    if (stateKey) {
      try {
        window.sessionStorage.setItem(`table-columns:${stateKey}`, JSON.stringify(next))
      } catch {
        /* storage blocked: the choice lasts for this view */
      }
    }
  }

  const query = server ? server.state.query : localQuery
  const sort = server ? server.state.sort : localSort
  const page = server ? server.state.page : localPage
  const pageSize = server ? server.state.pageSize : localSize

  useEffect(() => {
    writeSaved(stateKey, { page, pageSize, sort, query })
  }, [stateKey, page, pageSize, sort, query])

  const apply = (next: Partial<TableState>) => {
    const merged: TableState = { page, pageSize, sort, query, ...next }
    if (server) {
      server.onChange(merged)
      return
    }
    setPage(merged.page)
    setSize(merged.pageSize)
    setSort(merged.sort)
    setQuery(merged.query)
  }

  const raw = (col: Column<T>, row: T): string | number => {
    if (col.value) return col.value(row)
    const v = (row as Record<string, unknown>)[col.key]
    return typeof v === 'number' ? v : String(v ?? '')
  }

  const filtered = useMemo(() => {
    if (server) return rows
    let out = rows
    if (query.trim()) {
      const q = query.toLowerCase()
      out = out.filter((row) => columns.some((c) => String(raw(c, row)).toLowerCase().includes(q)))
    }
    if (sort) {
      const col = columns.find((c) => c.key === sort.key)
      if (col) {
        out = [...out].sort((a, b) => {
          const av = raw(col, a)
          const bv = raw(col, b)
          const cmp = av < bv ? -1 : av > bv ? 1 : 0
          return sort.dir === 'asc' ? cmp : -cmp
        })
      }
    }
    return out
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows, query, sort, columns, server])

  const totalRows = server ? server.total : filtered.length
  const pages = Math.max(1, Math.ceil(totalRows / pageSize))
  const current = Math.min(page, pages)
  const pageRows = server ? filtered : filtered.slice((current - 1) * pageSize, current * pageSize)
  const from = totalRows === 0 ? 0 : (current - 1) * pageSize + 1
  const to = Math.min(current * pageSize, totalRows)

  const toggleSort = (key: string) =>
    apply({ page: 1, sort: sort?.key === key ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' } })

  const alignClass = (a?: Column<T>['align']) =>
    a === 'end' ? 'text-end' : a === 'center' ? 'text-center' : 'text-start'

  return (
    <div className="min-w-0 max-w-full space-y-3">
      {(searchable || columnPicker) && (
        <div className="flex flex-wrap items-start justify-between gap-2">
          {searchable && (
            <div className="relative min-w-0 max-w-xs flex-1">
              <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
              <Input
                value={query}
                onChange={(e) => apply({ query: e.target.value, page: 1 })}
                placeholder={labels.search}
                aria-label={labels.search}
                className="ps-9"
              />
            </div>
          )}
          {columnPicker && columns.some((c) => c.hideable) && (
            <div className="relative">
              <button
                type="button"
                data-testid="table-columns"
                aria-expanded={pickerOpen}
                onClick={() => setPickerOpen((o) => !o)}
                className="inline-flex items-center gap-1.5 rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary"
              >
                <Columns3 size={14} />
                {labels.columns}
              </button>
              {pickerOpen && (
                <div className="absolute end-0 z-[2] mt-1 w-56 max-w-[80vw] space-y-1 rounded-[10px] border border-border bg-surface p-2 shadow-[var(--shadow-small)]">
                  {columns.filter((c) => c.hideable).map((c) => (
                    <label key={c.key} className="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs text-text-primary hover:bg-surface-secondary">
                      <input
                        type="checkbox"
                        checked={columnChoice[c.key] ?? !c.defaultHidden}
                        onChange={(e) => toggleColumn(c.key, e.target.checked)}
                      />
                      <span className="min-w-0 truncate">{c.header}</span>
                    </label>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      <div
        className="max-w-full overflow-auto rounded-[13px] border border-border bg-surface shadow-[var(--shadow-small)]"
        style={maxHeight ? { maxHeight } : undefined}
      >
        <table className="w-full min-w-[560px] border-collapse text-sm">
          <thead className="sticky top-0 z-[1]">
            <tr className="border-b border-border bg-surface-secondary">
              {expandRow && <th aria-hidden className="w-8 px-1" />}
              {shownColumns.map((c) => (
                <th
                  key={c.key}
                  aria-sort={sort?.key === c.key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                  className={`px-3.5 py-2.5 font-semibold text-text-secondary ${alignClass(c.align)}`}
                >
                  {c.sortable ? (
                    <button
                      type="button"
                      onClick={() => toggleSort(c.key)}
                      className="inline-flex items-center gap-1 hover:text-text-primary"
                    >
                      {c.header}
                      {sort?.key === c.key ? (
                        sort.dir === 'asc' ? (
                          <ArrowUp size={13} />
                        ) : (
                          <ArrowDown size={13} />
                        )
                      ) : (
                        <ArrowUpDown size={13} className="opacity-50" />
                      )}
                    </button>
                  ) : (
                    c.header
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i} className="border-b border-border">
                  {expandRow && <td className="w-8 px-1" />}
                  {shownColumns.map((c) => (
                    <td key={c.key} className="px-3.5 py-3">
                      <Skeleton className="h-4 w-full" />
                    </td>
                  ))}
                </tr>
              ))
            ) : pageRows.length === 0 ? null : (
              pageRows.map((row) => (
                /*
                  The row's own key on the element — LEAD-DEDUP-001.

                  A cell that wants to point at ANOTHER row (a duplicate naming the lead it
                  duplicates) needs a way to find it. Without this the only route was to re-render
                  the whole table from outside, which is a lot of machinery to answer «which one».
                */
                <Fragment key={rowKey(row)}>
                  <tr
                    data-row-key={rowKey(row)}
                    className="border-b border-border last:border-0 hover:bg-surface-secondary target:bg-brand-500/10"
                  >
                    {expandRow && (
                      <td className="w-8 px-1 text-center">
                        <button
                          type="button"
                          data-testid="table-expand"
                          aria-expanded={expanded === rowKey(row)}
                          aria-label={expanded === rowKey(row) ? labels.collapseRow : labels.expandRow}
                          onClick={() => setExpanded((open) => (open === rowKey(row) ? null : rowKey(row)))}
                          className="rounded p-1 text-text-muted hover:text-text-primary"
                        >
                          <ChevronDown size={14} className={`transition-transform duration-[var(--motion-control)] ${expanded === rowKey(row) ? 'rotate-180' : ''}`} />
                        </button>
                      </td>
                    )}
                    {shownColumns.map((c) => (
                      <td key={c.key} className={`px-3.5 py-3 text-text-primary ${alignClass(c.align)}`}>
                        {c.render ? c.render(row) : c.truncate ? <FoldedCell text={String(raw(c, row))} max={c.truncate} labels={labels} /> : String(raw(c, row))}
                      </td>
                    ))}
                  </tr>
                  {expandRow && expanded === rowKey(row) && (
                    <tr data-testid="table-row-detail" className="border-b border-border bg-surface-secondary/50">
                      <td colSpan={shownColumns.length + 1} className="px-3.5 py-3 text-xs text-text-secondary">
                        {expandRow(row)}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))
            )}
          </tbody>
        </table>

        {!loading && error && (
          <div className="p-4">
            <ErrorState title="Failed to load data" onRetry={onRetry} />
          </div>
        )}
        {!loading && !error && pageRows.length === 0 && (
          <div className="p-4">
            <EmptyState title={emptyTitle} />
          </div>
        )}
      </div>

      {!loading && !error && totalRows > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-text-secondary" data-testid="table-pager">
          <span className="tnum" dir="ltr" data-testid="table-total">
            {from}–{to} / {totalRows} {labels.total}
          </span>
          <label className="flex items-center gap-1.5">
            <span>{labels.rowsPerPage}</span>
            <select
              value={pageSize}
              onChange={(e) => apply({ pageSize: Number(e.target.value), page: 1 })}
              aria-label={labels.rowsPerPage}
              data-testid="table-page-size"
              className="rounded-[8px] border border-border bg-surface px-1.5 py-1"
            >
              {pageSizeOptions.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
          <nav className="flex flex-wrap items-center gap-1" aria-label={labels.page}>
            <button
              type="button"
              disabled={current <= 1}
              onClick={() => apply({ page: current - 1 })}
              aria-label={labels.previous}
              className="rounded-[8px] border border-border px-2.5 py-1 disabled:opacity-40 rtl:rotate-180"
            >
              ‹
            </button>
            {pageNumbers(current, pages).map((n, i) =>
              n === null ? (
                <span key={`gap-${i}`} aria-hidden className="px-1">
                  …
                </span>
              ) : (
                <button
                  key={n}
                  type="button"
                  onClick={() => apply({ page: n })}
                  aria-label={`${labels.page} ${n}`}
                  aria-current={n === current ? 'page' : undefined}
                  className={`tnum min-w-[1.9rem] rounded-[8px] border px-2 py-1 ${n === current ? 'border-brand-500 bg-brand-500/10 font-bold text-text-primary' : 'border-border'}`}
                >
                  {n}
                </button>
              ),
            )}
            <button
              type="button"
              disabled={current >= pages}
              onClick={() => apply({ page: current + 1 })}
              aria-label={labels.next}
              className="rounded-[8px] border border-border px-2.5 py-1 disabled:opacity-40 rtl:rotate-180"
            >
              ›
            </button>
          </nav>
        </div>
      )}
    </div>
  )
}
