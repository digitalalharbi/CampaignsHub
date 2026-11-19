import { describe, expect, it } from 'vitest'

/**
 * REPORT-LOGIC-001 — one implementation, held by a sweep of the source.
 *
 * The report, the analytics board and (soon) the content library must read their figures from `features/reportLogic`. This fails
 * when a surface grows its own copy of a derivation, or when the board draws a table or a paragraph (the Owner's rule for the board:
 * charts and cards, details in popups).
 */
const SRC: Record<string, string> = import.meta.glob('/src/features/{reports/live,analyticsReport}/*.tsx', { query: '?raw', import: 'default', eager: true })
const read = (name: string) => Object.entries(SRC).find(([p]) => p.endsWith(name))?.[1] ?? ''
const DERIVATIONS = ['function headlineKeys', 'function resultSource', 'function resultBoardKeys', 'function platformCards', 'function rankPlatforms', 'function resultsSplit', 'function contentLists', 'function funnelStages', 'function trendOptions', 'function basisLine']

describe('report logic has one source', () => {
  it('the report summary and every board component import the shared layer and define none of its derivations', () => {
    for (const file of ['ExecutiveSummary.tsx', 'ReportBoard.tsx', 'CompareBoard.tsx']) {
      const src = read(file)
      expect(src.length, file).toBeGreaterThan(0)
      expect(src, file).toContain("@/features/reportLogic")
      for (const d of DERIVATIONS) expect(src, `${file} defines ${d}`).not.toContain(d)
    }
  })

  it('the board draws charts and cards: no table, no table component, no long prose', () => {
    for (const file of ['ReportBoard.tsx', 'CompareBoard.tsx']) {
      const src = read(file)
      expect(src, file).not.toMatch(/<table|<tr[ >]|MetricTable|DataTable|FixedPageTable/)
      // A paragraph is allowed only as a one-line empty state («غير متاح» / no data); explanatory prose belongs in a popup or nowhere.
      for (const m of src.matchAll(/<p [^>]*>([^<]*)</g)) expect(m[1].length, `${file}: «${m[1]}»`).toBeLessThan(120)
    }
  })
})
