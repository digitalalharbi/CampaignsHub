import type { ReactNode } from 'react'
import { useIsTablet } from './useFitRows'
import { FIT_CARD, FIT_HEAD_HEIGHT, FIT_ROW_HEIGHT } from '@/styles/scale'

export interface ListColumn<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  /** A CSS width; the widths of a table should add up to 100%. The first column takes what is left. */
  width?: string
  align?: 'start' | 'center' | 'end'
}

/**
 * NOV-FIT-001 — one list, two shapes: a table on a desktop, cards on a phone.
 *
 * A table is the right shape for comparing rows and the wrong one for a 360px screen, where it either squeezes
 * its columns to nothing or scrolls sideways. So below `md` the same rows are drawn as cards (`renderCard`), in
 * the page's own vertical scroll, and from `md` up they are a table of the PRIMARY columns only. Everything else
 * about a row — the second-tier figures, the long text — is behind `onOpen`, in a detail panel, which is how a row
 * stays one line tall.
 *
 * The table's rows are a fixed `FIT_ROW_HEIGHT`, the header `FIT_HEAD_HEIGHT`: a long value is cut with an
 * ellipsis and kept whole in its `title`, never wrapped, so the row count `useFitRows` computes is exact.
 * Only the shape that is showing is in the document, so a name appears once, not twice.
 */
export function ResponsiveList<T>({
  rows, columns, rowKey, renderCard, onOpen, testid, empty,
}: {
  rows: T[]
  columns: ListColumn<T>[]
  rowKey: (row: T) => string
  renderCard: (row: T) => ReactNode
  /** Pressing anywhere on a row opens its details. The keyboard's way in is the button in the row's first cell. */
  onOpen?: (row: T) => void
  testid: string
  empty?: ReactNode
}) {
  const tablet = useIsTablet()
  if (rows.length === 0 && empty) return <div data-testid={`${testid}-empty`}>{empty}</div>

  if (!tablet) {
    return (
      <ul data-testid={`${testid}-cards`} className="grid gap-2">
        {rows.map((row) => <li key={rowKey(row)} className="min-w-0">{renderCard(row)}</li>)}
      </ul>
    )
  }

  return (
    <>
      <div data-testid={`${testid}-table`} className={`min-h-0 overflow-hidden ${FIT_CARD}`}>
        <table className="w-full table-fixed border-collapse text-start text-sm">
          <thead>
            <tr style={{ height: FIT_HEAD_HEIGHT }} className="border-b border-border text-xs text-text-muted">
              {columns.map((c, i) => (
                <th
                  key={c.key}
                  scope="col"
                  style={i === 0 ? undefined : { width: c.width }}
                  className={`px-3 font-semibold ${c.align === 'end' ? 'text-end' : c.align === 'center' ? 'text-center' : 'text-start'}`}
                >
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={rowKey(row)}
                style={{ height: FIT_ROW_HEIGHT }}
                onClick={onOpen ? () => onOpen(row) : undefined}
                className={`border-b border-border/60 last:border-0 ${onOpen ? 'cursor-pointer hover:bg-surface-hover' : ''}`}
              >
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={`truncate px-3 ${c.align === 'end' ? 'text-end' : c.align === 'center' ? 'text-center' : 'text-start'}`}
                  >
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}
