import { describe, expect, it } from 'vitest'
import { overBudget, PUBLIC_REPORT_BUDGET_BYTES, PUBLIC_REPORT_FACADE, routeBytes, routeOverBudget, staticClosure } from './bundleBudget'

describe('the entry chunk budget', () => {
  it('names an entry chunk over budget', () => {
    expect(overBudget([{ fileName: 'index.js', isEntry: true, bytes: 4_763_480 }])).toHaveLength(1)
  })

  it('leaves route chunks and an entry within budget alone', () => {
    expect(overBudget([
      { fileName: 'index.js', isEntry: true, bytes: 407_000 },
      { fileName: 'CampaignsPage.js', isEntry: false, bytes: 900_000 },
    ])).toEqual([])
  })
})

/**
 * The shared report's weight on arrival — the entry's static closure plus the route chunk's, never
 * what a dynamic import would fetch later.
 */
describe('the /r/ route budget', () => {
  const chunks = [
    { fileName: 'index.js', isEntry: true, bytes: 400_000, imports: ['react.js', 'ui.js'] },
    { fileName: 'react.js', isEntry: false, bytes: 100_000, imports: [] },
    { fileName: 'ui.js', isEntry: false, bytes: 50_000, imports: ['react.js'] },
    { fileName: 'PublicReport.js', isEntry: false, bytes: 140_000, imports: ['react.js', 'charts.js'], facadeModuleId: '/repo/src/features/reports/PublicReport.tsx' },
    { fileName: 'charts.js', isEntry: false, bytes: 300_000, imports: [] },
    // Reached only through a dynamic import — not on arrival.
    { fileName: 'InteractiveReport.js', isEntry: false, bytes: 900_000, imports: ['charts.js'], facadeModuleId: '/repo/src/features/reports/InteractiveReport.tsx' },
  ]

  it('follows static imports from the entry and the route chunk, once each', () => {
    expect(staticClosure(chunks, ['index.js'])).toEqual(new Set(['index.js', 'react.js', 'ui.js']))
    expect(routeBytes(chunks, PUBLIC_REPORT_FACADE)).toBe(400_000 + 100_000 + 50_000 + 140_000 + 300_000)
  })

  it('holds the measured figure to its ceiling', () => {
    expect(routeOverBudget(chunks, PUBLIC_REPORT_FACADE, 990_000)).toBeNull()
    expect(routeOverBudget(chunks, PUBLIC_REPORT_FACADE, 989_999)).toMatch(/990000 bytes on arrival, budget 989999/)
  })

  it('does not fail a build whose route is gone', () => {
    expect(routeOverBudget(chunks, '/features/reports/Removed.tsx', 1)).toBeNull()
  })

  it('keeps the ceiling at the measured figure × 1.15, not a round number nobody measured', () => {
    // 1,154,478 B measured by the plugin on the split; see `bundleBudget.ts`.
    expect(PUBLIC_REPORT_BUDGET_BYTES).toBeLessThanOrEqual(Math.round(1_154_478 * 1.15))
    expect(PUBLIC_REPORT_BUDGET_BYTES).toBeGreaterThan(1_154_478)
  })
})
