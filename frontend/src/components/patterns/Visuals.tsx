import type { ReactNode } from 'react'
import { Num } from '@/components/ui/Num'
import { useUi } from '@/stores/ui'

/**
 * PRODUCT-VISUAL-001 §2 — the shapes this product draws, and the rule all of them obey.
 *
 * ## Never invent data to make a page visual
 *
 * Each of these renders NOTHING when it has nothing, and says why. A bar chart of zeros, a line
 * drawn to the floor across days nobody reported, a matrix full of dashes — each looks like a
 * measurement and is the absence of one, and a reader who trusts one of those once stops trusting
 * the page. The empty state is part of the component, not an afterthought at the call site.
 *
 * ## Why these are SVG and not the chart library
 *
 * The heavy ones — trends with axes, tooltips and brushes — already live in
 * `features/analytics/components` on recharts and stay there. These are the small, dense shapes a
 * summary surface is made of: a sparkline inside a table row, a ranked bar list, a coverage matrix.
 * They have no axes, no interaction and no tooltip, so a charting runtime buys nothing and costs a
 * container query per row.
 */

/**
 * A ranked horizontal bar list — «who moved it», answered by the first rows.
 *
 * Ranked rather than alphabetical because that is the question. The value is printed beside every
 * bar: a bar's LENGTH is a comparison and its number is the fact, and a reader needs both — one to
 * see the shape, one to quote in a meeting.
 */
export function RankedBars({ rows, format, max, empty, testId }: {
  rows: Array<{ id: string; label: string; value: number; sublabel?: string; color?: string }>
  format: (value: number) => string
  /** The bar that fills the track. Defaults to the largest row, which is what «ranked» implies. */
  max?: number
  empty?: ReactNode
  testId?: string
}) {
  if (rows.length === 0) return <>{empty}</>

  const ceiling = max ?? Math.max(...rows.map((r) => r.value), 0)

  return (
    <ul className="flex flex-col gap-2" data-testid={testId}>
      {rows.map((row) => (
        <li key={row.id} className="flex flex-col gap-1" data-testid={`${testId ?? 'bar'}-${row.id}`}>
          <div className="flex items-baseline justify-between gap-3 text-xs">
            <span className="min-w-0 truncate font-semibold text-text-primary">{row.label}</span>
            <span className="tnum shrink-0 font-bold text-text-primary">{format(row.value)}</span>
          </div>

          <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary">
            <div
              className="h-full rounded-full"
              style={{
                width: ceiling > 0 ? `${Math.max(2, (row.value / ceiling) * 100)}%` : '0%',
                background: row.color ?? 'var(--brand-500)',
              }}
              aria-hidden
            />
          </div>

          {row.sublabel !== undefined && (
            <span className="truncate text-[11px] text-text-muted">{row.sublabel}</span>
          )}
        </li>
      ))}
    </ul>
  )
}

/**
 * A sparkline — the shape of a series, at the size of a table cell.
 *
 * Gaps are REAL gaps: the path breaks where a point is missing rather than joining across it,
 * because a line drawn through a day nobody reported invents a measurement. Fewer than two points
 * draws nothing at all — one point is not a trend, and a flat stub implies stability nobody measured.
 */
export function Sparkline({ points, color = 'var(--brand-500)', width = 96, height = 24, testId }: {
  points: Array<{ date: string; value: number | null }>
  color?: string
  width?: number
  height?: number
  testId?: string
}) {
  const measured = points.filter((p) => typeof p.value === 'number')
  if (measured.length < 2) return null

  const values = measured.map((p) => p.value as number)
  const min = Math.min(...values)
  const max = Math.max(...values)
  const span = max - min || 1

  const x = (i: number) => (points.length === 1 ? 0 : (i / (points.length - 1)) * width)
  const y = (v: number) => height - ((v - min) / span) * (height - 2) - 1

  // One `M` per run of measured points: a break in the data is a break in the line.
  let d = ''
  let open = false
  points.forEach((point, i) => {
    if (typeof point.value !== 'number') { open = false; return }
    d += `${open ? 'L' : 'M'}${x(i).toFixed(1)},${y(point.value).toFixed(1)} `
    open = true
  })

  const lastIndex = points.map((p) => typeof p.value === 'number').lastIndexOf(true)

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      aria-hidden
      data-testid={testId}
      className="overflow-visible"
    >
      <path d={d.trim()} fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
      {/* The endpoint, emphasised: «where it is now» is the one point a reader looks for. */}
      {lastIndex >= 0 && (
        <circle cx={x(lastIndex)} cy={y(points[lastIndex]!.value as number)} r="2" fill={color} />
      )}
    </svg>
  )
}

/**
 * A coverage matrix — which entities are fed by which sources.
 *
 * A filled cell means «this pairing exists»; an empty one means it does not. There is deliberately
 * no third state: «exists but unhealthy» is a different question, and encoding it here would make
 * every cell ambiguous. The row labels are the entities, the columns are the sources, and both are
 * named in text because a grid of coloured squares is not readable without them.
 */
export function CoverageMatrix({ rows, columns, has, columnLabel, empty, testId }: {
  rows: Array<{ id: string; label: string }>
  columns: Array<{ id: string; label: string; color?: string }>
  has: (rowId: string, columnId: string) => boolean
  columnLabel?: (column: { id: string; label: string }) => ReactNode
  empty?: ReactNode
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'

  if (rows.length === 0 || columns.length === 0) return <>{empty}</>

  return (
    <div className="overflow-x-auto" data-testid={testId}>
      <table className="w-full min-w-[22rem] border-separate border-spacing-y-1 text-xs">
        <thead>
          <tr>
            <th scope="col" className="w-[40%] p-0 text-start text-[11px] font-semibold text-text-muted">
              {ar ? 'المشروع' : 'Project'}
            </th>
            {columns.map((column) => (
              <th key={column.id} scope="col" className="p-0 text-center text-[11px] font-semibold text-text-muted">
                {columnLabel?.(column) ?? column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id} data-testid={`${testId ?? 'matrix'}-row-${row.id}`}>
              {/*
                `data-untranslatable` — this cell holds a tenant's OWN name, not product copy.

                `<th scope="row">` is the right element for a row header, and the Arabic-leak guard
                reads every `th` as chrome: a project called «متجر تجريبي» was reported as English
                chrome left in Arabic. Marking it says which it is, instead of reaching for a weaker
                tag to dodge a test.
              */}
              <th
                scope="row"
                data-untranslatable
                className="max-w-0 truncate p-0 pe-2 text-start font-semibold text-text-primary"
              >
                {row.label}
              </th>
              {columns.map((column) => {
                const on = has(row.id, column.id)
                return (
                  <td key={column.id} className="p-0 text-center">
                    <span
                      data-on={on}
                      aria-label={`${row.label} · ${column.label} · ${on ? (ar ? 'مربوط' : 'connected') : (ar ? 'غير مربوط' : 'not connected')}`}
                      className="mx-auto block h-3 w-3 rounded-[4px]"
                      style={{ background: on ? (column.color ?? 'var(--brand-500)') : 'var(--surface-secondary)' }}
                    />
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

/**
 * A distribution of states — how many of each, as proportion and as count.
 *
 * One bar rather than several, because the question is «what is the mix» and separate bars make a
 * reader add them up to find out. Segments below a legible width still render: a single project in
 * trouble among forty is exactly the segment somebody needs to see.
 */
export function StateDistribution({ segments, total, empty, testId }: {
  segments: Array<{ id: string; label: string; count: number; color: string }>
  total?: number
  empty?: ReactNode
  testId?: string
}) {
  const sum = total ?? segments.reduce((n, s) => n + s.count, 0)
  if (sum === 0) return <>{empty}</>

  return (
    <div className="flex flex-col gap-2" data-testid={testId}>
      <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-surface-secondary">
        {segments.filter((s) => s.count > 0).map((segment) => (
          <span
            key={segment.id}
            className="h-full"
            style={{ width: `${(segment.count / sum) * 100}%`, background: segment.color, minWidth: 4 }}
            aria-hidden
          />
        ))}
      </div>

      <ul className="flex flex-wrap gap-x-4 gap-y-1">
        {segments.filter((s) => s.count > 0).map((segment) => (
          <li key={segment.id} className="inline-flex items-center gap-1.5 text-[11px] text-text-secondary">
            <span className="h-2 w-2 rounded-full" style={{ background: segment.color }} aria-hidden />
            {segment.label}
            <span className="tnum font-bold text-text-primary"><Num>{segment.count}</Num></span>
          </li>
        ))}
      </ul>
    </div>
  )
}
