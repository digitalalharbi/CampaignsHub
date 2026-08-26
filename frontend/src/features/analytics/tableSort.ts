/**
 * TABLE-SORT-ALIGN-001 — the ordering rule, extracted so it can be tested on real data.
 *
 * The analytics tables render `ReactNode` cells — a bar, a pill, a link — which cannot be compared,
 * so each table passes the raw figures alongside. This is the comparison those figures go through.
 *
 * It lives outside the component because the seeded database has one row in every sortable table:
 * an end-to-end test there can prove the control exists and toggles, and cannot prove that two rows
 * come back in a different order. That part is proven here instead, and the limitation is stated
 * rather than papered over with a test that passes on a single row.
 */
export type SortValue = number | string | null
export type SortDir = 'asc' | 'desc'

export function orderRows(values: SortValue[][], column: number, dir: SortDir): number[] {
  const base = values.map((_, i) => i)

  return [...base].sort((a, b) => {
    const av = values[a]?.[column] ?? null
    const bv = values[b]?.[column] ?? null

    /*
     * An absent figure goes last in BOTH directions.
     *
     * «This platform does not report CPM» is not the cheapest CPM. Sorting nulls to the top of an
     * ascending column is how an absence gets read as a best result — the same confusion between
     * «no data» and «zero» that the money contract exists to prevent, one layer up in the UI.
     */
    if (av === null && bv === null) return a - b
    if (av === null) return 1
    if (bv === null) return -1

    const cmp = typeof av === 'number' && typeof bv === 'number'
      ? av - bv
      // `numeric` so «Campaign 2» sorts before «Campaign 10», which plain string order gets wrong.
      : String(av).localeCompare(String(bv), undefined, { numeric: true })

    // Ties keep their original position, so re-sorting a column does not shuffle equal rows.
    return cmp === 0 ? a - b : dir === 'asc' ? cmp : -cmp
  })
}
