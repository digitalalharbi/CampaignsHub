/**
 * REPORT-FILTERS-FUNCTIONAL-001 — whether a served answer carries a figure at all.
 *
 * The server leaves out the KEY of a KPI or a column the operator did not choose (`ChosenFigures` on the backend). That is a
 * different fact from a key that is there and null — a figure nobody reported, or money a link withholds — which keeps its own
 * wording. A block built on a figure the answer does not carry is not drawn: no zero, no dash, no chart of nothing.
 *
 * A bag that is not there says nothing about the choice, so it carries: every answer built before the choice reads as before.
 */
export function carries(bag: unknown, key: string): boolean {
  return bag === null || bag === undefined || typeof bag !== 'object' || key in (bag as object)
}
