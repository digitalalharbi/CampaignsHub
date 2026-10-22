import type { ReactNode } from 'react'
import type { Column } from '@/components/ui/MetricTable'

/**
 * REPORT-TABLES-OWNER-COLUMNS-001 — cut a table to the columns the link's Owner chose.
 *
 * `chosen` is `data.report_columns[table]`: absent means every column, which is what every link built
 * before the choice existed renders. `keys` names, per column, the picker key it answers to — an empty
 * string for a column the Owner cannot choose (revenue, ROAS…), which a RESTRICTED table leaves out.
 * The first column, the row's name, is never a choice. Money a link withholds is already absent from
 * the payload, so choosing a spend column there still prints no spend.
 */
export function selectColumns(
  chosen: string[] | undefined,
  keys: string[],
  head: string[],
  rows: ReactNode[][],
): { head: string[]; rows: ReactNode[][] } {
  if (chosen === undefined) return { head, rows }

  const keep = head.map((_, i) => i).filter((i) => i === 0 || (keys[i] !== '' && chosen.includes(keys[i])))

  return { head: keep.map((i) => head[i]), rows: rows.map((r) => keep.map((i) => r[i])) }
}

/**
 * The same cut for a `DataMetricTable`: `keys` maps each column's own key to the picker key it
 * answers to ('' for one the Owner cannot choose). The first column — the row's name — stays.
 */
export function cutColumns(chosen: string[] | undefined, columns: Column[], keys: Record<string, string>): Column[] {
  if (chosen === undefined) return columns

  return columns.filter((c, i) => i === 0 || ((keys[c.key] ?? '') !== '' && chosen.includes(keys[c.key])))
}
