import type { FormatComparisonPayload, FormatComparisonTable } from '@/features/content/api'

/**
 * REPORT-FORMAT-COMPARE-TABLE-001 — what the report's comparison table draws, with no React: the deck's page list (`reportSections`)
 * asks the same question without loading the renderer.
 *
 * The server built the table (rows = the report's KPIs, columns = the types). Later redaction passes can still null a whole row (a
 * money row on a client copy) or a cell, so the reader keeps only what survived: a row with no positive figure is not drawn, a type
 * with nothing in any drawn row is not a column — never a 0 or «—» row.
 */
export const present = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v) && v > 0

export interface DrawnTable {
  rows: string[]
  formats: FormatComparisonTable['formats']
  cell: (row: string, format: string) => number | null
  table: FormatComparisonTable
}

export function drawnTable(data: FormatComparisonPayload | null | undefined, hidesMoney = false): DrawnTable | null {
  const table = data?.table
  if (!table || !Array.isArray(table.rows) || !Array.isArray(table.formats)) return null
  const cell = (row: string, format: string): number | null => {
    const v = table.cells?.[row]?.[format]

    return present(v) ? v : null
  }
  // A client's copy, or a link hiding spend, never draws money — even from a stale payload (ROAS is money over money).
  const isMoney = (r: string) => ['money', 'multiplier'].includes(table.kinds?.[r] ?? '')
  const rows = table.rows.filter((r) => !(hidesMoney && isMoney(r)) && table.formats.some((f) => cell(r, f.format) !== null))
  const formats = table.formats.filter((f) => rows.some((r) => cell(r, f.format) !== null))
  if (rows.length === 0 || formats.length === 0) return null

  return { rows, formats, cell, table }
}

/** The type that wins a row, or null: a row with no direction, fewer than two figures, or a tie names no winner. */
export function rowWinner(t: DrawnTable, row: string): string | null {
  const better = t.table.better?.[row]
  if (!better) return null
  const values = t.formats.map((f) => ({ format: f.format, v: t.cell(row, f.format) })).filter((x): x is { format: string; v: number } => x.v !== null)
  if (values.length < 2) return null
  values.sort((a, b) => (better === 'higher' ? b.v - a.v : a.v - b.v))

  return values[0].v === values[1].v ? null : values[0].format
}

/**
 * The one-line verdict: on the first figure of the objective's order that at least two types with a sufficient sample answer, and
 * where one is strictly better. A type flagged small sample does not take part. Nothing to state → null (no speculation).
 */
export function verdictOf(t: DrawnTable): { row: string; winner: string; winnerValue: number; runnerUp: string; runnerValue: number } | null {
  for (const row of t.table.verdict_order ?? []) {
    const better = t.table.better?.[row]
    if (!better || !t.rows.includes(row)) continue
    const values = t.formats
      .filter((f) => !f.small_sample)
      .map((f) => ({ format: f.format, v: t.cell(row, f.format) }))
      .filter((x): x is { format: string; v: number } => x.v !== null)
    if (values.length < 2) continue
    values.sort((a, b) => (better === 'higher' ? b.v - a.v : a.v - b.v))
    if (values[0].v === values[1].v) continue

    return { row, winner: values[0].format, winnerValue: values[0].v, runnerUp: values[1].format, runnerValue: values[1].v }
  }

  return null
}

/** Whether the comparison has anything to draw for this reader — a section with no figure is not a section, and no page. */
export function tableHasFigures(data: FormatComparisonPayload | null | undefined, hidesMoney = false): boolean {
  return drawnTable(data, hidesMoney) !== null
}
