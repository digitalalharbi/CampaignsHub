import { useMemo, useState, type ReactNode } from 'react'
import { readMetricValue } from '@/lib/metricValue'
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

/**
 * TABLE-NUMERIC-ALIGNMENT-001 — the primitive owns the FORMATTING, not only the layout.
 *
 * The alignment rules below were already here and every consumer still formatted its own cells, so
 * the same figure was compact on one screen and exact on the next, a missing value was «—» here and
 * «0» there, and a currency was appended by whichever page remembered. Owning the layout and not the
 * content is owning half of a contract: the half a reader does not see.
 *
 * A column now declares its KIND and hands over raw values. The primitive decides the abbreviation,
 * the exact figure behind it, the currency, the number of digits after the point, what a null looks
 * like, and where the digits sit. A page cannot get any of those wrong, because it is no longer
 * asked.
 *
 * The `ReactNode` cell API stays beside it, deliberately. Some cells genuinely are components — a
 * bar, a status pill, a link to a campaign — and forcing those through a formatter would produce a
 * table that describes numbers well and cannot show anything else. A surface may mix them: declare
 * the numeric columns, and pass a node for the one that is a control.
 */
export type ColumnKind = 'text' | 'number' | 'money' | 'cost' | 'percent' | 'ratio'

export interface Column {
  /** The key this column reads from each row. */
  key: string
  label: string
  kind: ColumnKind
  /**
   * Money columns only. `null` prints the figure BARE rather than under a guessed currency — the
   * money contract's rule, applied here so a table cannot be the surface that breaks it.
   */
  currency?: string | null
  /** Percent columns only: digits after the point. Defaults to one, which is what a rate deserves. */
  digits?: number
}

/** One row of raw values, keyed by column. A node is allowed for a cell that is genuinely a control. */
export type Row = Record<string, number | string | null | undefined | ReactNode>

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
/**
 * What one cell reads as, what it sorts by, and what its tooltip reveals — in one place.
 *
 * The three have to be decided together or they disagree: a cell that prints «1.2M», sorts by the
 * string «1.2M» and reveals nothing is three separate defects wearing one number.
 */
/**
 * One cell, through the product's one value law — {@see readMetricValue}.
 *
 * The law used to live HERE, which made it reachable only by rendering a table. The KPI cards took a
 * pre-formatted string from whichever surface built them and each surface chose its own notation, so
 * a card and a table cell of the same metric could disagree — and did. Moving the law out and calling
 * it from both is what makes them agree by construction.
 *
 * A node is still shown as given: a cell that is genuinely a control is not a figure.
 */
function render(column: Column, raw: unknown): { cell: ReactNode; value: number | string | null; exact: string | null } {
  if (raw !== null && raw !== undefined && typeof raw !== 'number' && typeof raw !== 'string') {
    return { cell: raw as ReactNode, value: null, exact: null }
  }

  const read = readMetricValue(column.kind, raw, { currency: column.currency, digits: column.digits })

  return {
    cell: read.text,
    // Text sorts as text; a figure sorts as its number, and a missing one sorts as an absence.
    value: column.kind === 'text' ? (read.text === '—' ? null : read.text) : read.value,
    exact: read.exact,
  }
}

/**
 * The spec-driven table: declare the columns, hand over the rows, and the primitive does the rest.
 *
 * This is the form every analytical surface should reach for. `MetricTable` below it stays for the
 * cells that are genuinely components, and the two render identically because one calls the other.
 */
export function DataMetricTable({
  columns,
  rows,
  initialSort,
}: {
  columns: Column[]
  rows: Row[]
  initialSort?: { column: number; dir: 'asc' | 'desc' }
}) {
  const built = useMemo(() => {
    const cells: ReactNode[][] = []
    const values: SortValues[] = []
    const exact: (string | null)[][] = []

    for (const row of rows) {
      const c: ReactNode[] = []
      const v: SortValues = []
      const e: (string | null)[] = []

      for (const column of columns) {
        const out = render(column, row[column.key])
        c.push(out.cell)
        v.push(out.value)
        e.push(out.exact)
      }

      cells.push(c)
      values.push(v)
      exact.push(e)
    }

    return { cells, values, exact }
  }, [columns, rows])

  return (
    <MetricTable
      head={columns.map((c) => c.label)}
      rows={built.cells}
      values={built.values}
      exact={built.exact}
      initialSort={initialSort}
    />
  )
}

export function MetricTable({
  head,
  rows,
  values,
  exact,
  initialSort,
}: {
  head: string[]
  rows: ReactNode[][]
  values?: SortValues[]
  /**
   * NUMBER-PRESENTATION-001 — the exact figure behind an abbreviated one, per cell.
   *
   * A compact number is a reading aid and a lossy one: «1.2M» is any of eleven thousand different
   * figures, and two rows both reading «32K» can be a thousand results apart with nothing on screen
   * to say so. The rule the product already applies on KPI cards is that the exact value travels
   * WITH the compact one and is always one hover or one tap away — this is that rule reaching the
   * tables, which is where most of the product's numbers actually live.
   *
   * Positional, like `values`, and sparse: a cell with nothing to reveal passes `null` and gets no
   * tooltip, because a tooltip that repeats what is already on screen teaches a reader to stop
   * looking at them.
   */
  exact?: (string | null)[][]
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
              {rows[rowIndex].map((cell, j) => {
                const full = exact?.[rowIndex]?.[j] ?? null
                /*
                 * The first column LABELS its row, and a screen reader needs to be told so.
                 *
                 * Every hand-rolled table this primitive replaces wrote `<th scope="row">` for the
                 * name — the platform, the campaign, the ad set — and rendering it as a plain cell
                 * takes that away: a reader moving down a column hears the figures with nothing
                 * saying which row they belong to. Caught by a migration whose own test asked for the
                 * `rowheader` role and did not find one, which is the only way this surfaces — it
                 * looks identical on screen.
                 *
                 * The first column only, because that is what «labels the row» means here: the
                 * upright tables all put the name first, and a second header cell would make the row
                 * ambiguous rather than clearer. And only when the row HAS other cells — a
                 * single-column table has nothing for its one cell to label, and marking it a header
                 * would announce a relationship that does not exist.
                 */
                const Cell = j === 0 && rows[rowIndex].length > 1 ? 'th' : 'td'

                return (
                  <Cell
                    key={j}
                    scope={j === 0 ? 'row' : undefined}
                    /*
                     * `title`, and a dotted underline so it is discoverable.
                     *
                     * An affordance nobody can see is not an affordance: a reader who does not know
                     * the figure can be revealed will never hover, and the abbreviation stays the
                     * only number they ever have. `decoration-dotted` is the quietest mark that
                     * still reads as «there is more here».
                     */
                    title={full ?? undefined}
                    className={`py-2.5 ${j === 0 ? 'text-start font-normal' : 'tnum text-center'}`
                      + (full === null ? '' : ' cursor-help underline decoration-dotted underline-offset-4')}
                  >
                    {cell}
                  </Cell>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * The TRANSPOSED shape — metrics down the side, subjects across the top.
 *
 * Three surfaces read this way (creative pulse, creative comparison, campaign comparison) and all
 * three hand-rolled it, because the primitive had no shape for it. Each then made its own alignment
 * decision: the pulse table centres its figures, the campaign comparison ends them, and the creative
 * comparison starts them with a hard `dir="ltr"` per cell. Three tables, three answers, one product —
 * which is the page-specific formatting the contract exists to abolish.
 *
 * Only the ORIENTATION differs. Every cell still goes through the same `render()` the upright table
 * uses, so a missing figure is the same dash, a currency prints the same way, a compact count carries
 * the same exact-value tooltip, and the kind is declared once per ROW rather than per column.
 *
 * ## What it deliberately does not do
 *
 * No sorting. Sorting a transposed table would reorder the METRICS, which is not a question anybody
 * asks — the rows are a fixed reading order chosen by the surface, and «sort by value» has no meaning
 * when each row is a different unit.
 */
export interface TransposedColumn {
  key: string
  /** Arbitrary: these headers carry thumbnails, links and badges on the surfaces that use them. */
  header: ReactNode
}

export interface TransposedRow {
  key: string
  /** Prose — the metric's name. Start-aligned, because it is read rather than compared. */
  label: ReactNode
  kind: ColumnKind
  currency?: string | null
  digits?: number
  values: Array<number | string | null | undefined | ReactNode>
  /** A sub-label under a figure — «per lead», «orders» — one per column, or absent. */
  notes?: Array<ReactNode>
  /** Which cell in this row, if any, is the best reading. The surface decides what «best» means. */
  emphasis?: Array<boolean>
}

export function TransposedMetricTable({
  columns,
  rows,
  minWidth = '40rem',
  testId,
}: {
  columns: TransposedColumn[]
  rows: TransposedRow[]
  minWidth?: string
  testId?: string
}) {
  return (
    <div className="min-w-0 max-w-full overflow-x-auto">
      <table className="w-full text-sm" style={{ minWidth }} data-testid={testId}>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="p-2 text-start text-xs font-medium text-text-secondary">
              {/* The corner cell names the side axis, and every surface calls it the same thing. */}
            </th>
            {columns.map((column) => (
              /*
               * Centred, like the figures beneath it. A header that ends while its column centres is
               * the drift this contract exists to prevent, and it is invisible in one direction.
               */
              <th key={column.key} scope="col" className="p-2 text-center align-bottom">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className="border-t border-border">
              <th scope="row" className="p-2 text-start text-xs font-medium text-text-secondary">
                {row.label}
              </th>
              {columns.map((column, i) => {
                const out = render(
                  { key: column.key, label: '', kind: row.kind, currency: row.currency, digits: row.digits },
                  row.values[i],
                )
                const best = row.emphasis?.[i] === true

                return (
                  <td
                    key={column.key}
                    title={out.exact ?? undefined}
                    className={`tnum p-2 text-center ${best ? 'font-semibold text-success' : 'text-text-primary'}`}
                  >
                    {out.cell}
                    {row.notes?.[i] ? (
                      <span className="block text-xs font-normal text-text-muted">{row.notes[i]}</span>
                    ) : null}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
