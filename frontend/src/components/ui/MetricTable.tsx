import { useMemo, useState, type ReactNode } from 'react'
import { orderRows } from '@/lib/tableSort'

/**
 * TABLE-PRESENTATION-CONTRACT-001 — the product's one analytical table.
 *
 * ## Why it moved here
 *
 * It was defined inside `AnalyticsPage`, so a surface outside analytics could only reuse it by
 * importing a 3,000-line page module — which nobody did. They wrote another table instead: the
 * client's shared creative section had its own, left-aligning every number and offering no sort at
 * all, and the pulse strip has a note in it saying it hand-rolls one «rather than by using
 * `MetricTable`». Three tables, three alignments, three answers to what a missing figure looks like,
 * in one product, in front of the same reader.
 *
 * Nothing about the rendering changed in the move. What changed is that it is now reachable.
 *
 * ## The rules the contract holds it to
 *
 * The first column is text and starts; every other column is numeric, centred, and `tnum`, so the
 * header and its digits share one alignment — under `dir="rtl"`, `text-end` puts a number against
 * the LEFT edge of its cell, as far from its Arabic heading as the column is wide, and it reads as
 * belonging to no column in particular.
 *
 * The table scrolls sideways inside its own container and never the page body. Sorting goes through
 * `orderRows`, where an absent figure sorts last in BOTH directions: «this platform does not report
 * CPM» is not the cheapest CPM, and letting it win an ascending sort is how an absence gets read as
 * a best result.
 *
 * Cells are `ReactNode` — a bar, a pill, a link — so they cannot be compared. `values` carries the
 * raw figure per cell, positionally matched. A table with no `values` is simply not sortable rather
 * than sortable-and-wrong.
 */

/** One row's sortable values, positionally matched to its cells. `null` sorts last in both directions. */
export type SortValues = Array<number | string | null>

/**
 * TABLE-SORT-ALIGN-001 — every analytics table, sortable and squarely aligned.
 *
 * ## Alignment
 *
 * Header and cell were both `text-end`, which measured as a drift of exactly 0 on all eleven tables
 * — they were never misaligned in the DOM. But `text-end` under `dir="rtl"` means the LEFT edge of
 * the cell, so a number sat as far from its Arabic heading as the column is wide, and read as
 * belonging to no column in particular. Numeric columns are centred now: header and body share one
 * alignment, so the association is unmistakable at a glance.
 *
 * `tnum` stays, because it is what keeps digits the same width; centring only moves where the block
 * of digits sits, it does not stagger them.
 *
 * ## Sorting
 *
 * Cells are `ReactNode` — a bar, a pill, a link — so they cannot be compared. `values` carries the
 * raw figure per cell, positionally matched, and the caller passes the same source it rendered from.
 * A table with no `values` is simply not sortable rather than sortable-and-wrong.
 *
 * Nulls sort last in BOTH directions on purpose: «this platform does not report CPM» is not the
 * cheapest CPM, and letting it win an ascending sort is how an absence gets read as a best result.
 */
export function MetricTable({
  head,
  rows,
  values,
  initialSort,
}: {
  head: string[]
  rows: ReactNode[][]
  values?: SortValues[]
  /** Column index to sort by on first render, and its direction. */
  initialSort?: { column: number; dir: 'asc' | 'desc' }
}) {
  const [sort, setSort] = useState<{ column: number; dir: 'asc' | 'desc' } | null>(initialSort ?? null)

  const order = useMemo(
    () => (values && sort ? orderRows(values, sort.column, sort.dir) : rows.map((_, i) => i)),
    [rows, values, sort],
  )

  const toggle = (i: number) => {
    if (!values) return
    setSort((prev) => (prev?.column === i ? { column: i, dir: prev.dir === 'desc' ? 'asc' : 'desc' } : { column: i, dir: 'desc' }))
  }

  /*
   * `min-w-0` and `max-w-full`, or the container does not contain anything.
   *
   * A grid or flex ITEM defaults to `min-width: auto`, which means «as wide as my content» — so an
   * `overflow-x-auto` wrapper holding a 640px table grows the ITEM to 640px instead of clipping, and
   * the page scrolls sideways on a phone while the wrapper looks perfectly correct in isolation. The
   * live report's detail tables did exactly that: three browsers, four locale/theme combinations,
   * and the gate caught every one of them.
   */
  return (
    <div className="min-w-0 max-w-full overflow-x-auto">
      <table className="w-full min-w-[640px] text-sm">
        <thead>
          <tr className="border-b border-border text-text-muted">
            {head.map((h, i) => {
              const align = i === 0 ? 'text-start' : 'text-center'
              const active = sort?.column === i

              return (
                <th key={i} className={`py-2 font-semibold ${align}`} aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                  {values ? (
                    <button
                      type="button"
                      onClick={() => toggle(i)}
                      className={`inline-flex items-center gap-1 hover:text-text-primary ${active ? 'text-text-primary' : ''}`}
                      data-testid={`sort-${i}`}
                    >
                      {h}
                      {/* The arrow only appears on the column actually in force, so the row does not
                          look like six competing sort states. */}
                      <span aria-hidden className={active ? '' : 'opacity-0 group-hover:opacity-40'}>
                        {active ? (sort.dir === 'asc' ? '↑' : '↓') : '↕'}
                      </span>
                    </button>
                  ) : h}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {order.map((rowIndex) => (
            <tr key={rowIndex} className="border-b border-border last:border-0 hover:bg-surface-secondary">
              {rows[rowIndex].map((cell, j) => (
                <td key={j} className={`py-2.5 ${j === 0 ? 'text-start' : 'tnum text-center'}`}>
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
