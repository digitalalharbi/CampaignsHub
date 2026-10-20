import type { ReactNode } from 'react'

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
