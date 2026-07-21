import { useMemo, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown, Search } from 'lucide-react'
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
  emptyTitle?: string
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  onRetry,
  searchable = true,
  pageSize = 10,
  emptyTitle = 'No records yet',
}: DataTableProps<T>) {
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null)
  const [page, setPage] = useState(1)

  const raw = (col: Column<T>, row: T): string | number => {
    if (col.value) return col.value(row)
    const v = (row as Record<string, unknown>)[col.key]
    return typeof v === 'number' ? v : String(v ?? '')
  }

  const filtered = useMemo(() => {
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
  }, [rows, query, sort, columns])

  const pages = Math.max(1, Math.ceil(filtered.length / pageSize))
  const current = Math.min(page, pages)
  const pageRows = filtered.slice((current - 1) * pageSize, current * pageSize)

  const toggleSort = (key: string) =>
    setSort((s) => (s?.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }))

  const alignClass = (a?: Column<T>['align']) =>
    a === 'end' ? 'text-end' : a === 'center' ? 'text-center' : 'text-start'

  return (
    <div className="space-y-3">
      {searchable && (
        <div className="relative max-w-xs">
          <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
          <Input
            value={query}
            onChange={(e) => {
              setQuery(e.target.value)
              setPage(1)
            }}
            placeholder="Search…"
            className="ps-9"
          />
        </div>
      )}

      <div className="overflow-x-auto rounded-[13px] border border-border bg-surface shadow-[var(--shadow-small)]">
        <table className="w-full min-w-[560px] border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-border bg-surface-secondary">
              {columns.map((c) => (
                <th
                  key={c.key}
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
                  {columns.map((c) => (
                    <td key={c.key} className="px-3.5 py-3">
                      <Skeleton className="h-4 w-full" />
                    </td>
                  ))}
                </tr>
              ))
            ) : pageRows.length === 0 ? null : (
              pageRows.map((row) => (
                <tr key={rowKey(row)} className="border-b border-border last:border-0 hover:bg-surface-secondary">
                  {columns.map((c) => (
                    <td key={c.key} className={`px-3.5 py-3 text-text-primary ${alignClass(c.align)}`}>
                      {c.render ? c.render(row) : String(raw(c, row))}
                    </td>
                  ))}
                </tr>
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

      {!loading && !error && filtered.length > pageSize && (
        <div className="flex items-center justify-between text-[12px] text-text-secondary">
          <span className="tnum">
            {(current - 1) * pageSize + 1}–{Math.min(current * pageSize, filtered.length)} / {filtered.length}
          </span>
          <div className="flex items-center gap-1">
            <button
              type="button"
              disabled={current <= 1}
              onClick={() => setPage(current - 1)}
              className="rounded-[8px] border border-border px-2.5 py-1 disabled:opacity-40"
            >
              ‹
            </button>
            <span className="tnum px-2">
              {current} / {pages}
            </span>
            <button
              type="button"
              disabled={current >= pages}
              onClick={() => setPage(current + 1)}
              className="rounded-[8px] border border-border px-2.5 py-1 disabled:opacity-40"
            >
              ›
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
