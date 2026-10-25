/**
 * REPORT-OBJECTIVE-TEMPLATE-001 — how many columns a row of KPI cards takes at the wide breakpoint.
 *
 * The Sales funnel is seven cards. In a six-column grid the seventh wrapped to a second row of one, which made the executive summary
 * and every per-platform page taller than the print fit floor (0.85) and rejected the whole PDF on real data. A row that matches the
 * card count (up to seven) keeps it one row. Class names are written out in full so the stylesheet generator sees them.
 */
export function kpiRowClass(count: number, breakpoint: 'lg' | 'xl'): string {
  if (breakpoint === 'lg') return count >= 7 ? 'lg:grid-cols-7' : 'lg:grid-cols-6'

  return count >= 7 ? 'xl:grid-cols-7' : 'xl:grid-cols-6'
}
