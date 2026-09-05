import { describe, expect, it } from 'vitest'

/**
 * DASHBOARD-HIERARCHY — nothing analytical may be rendered above the primary KPI region.
 *
 * «The top of the Dashboard is permanently reserved for the PRIMARY PERFORMANCE KPIs … never insert
 * diagnostic cards, change-driver cards, recommendation cards, alerts or explanatory cards ABOVE the
 * primary KPI row. The first thing the user sees must remain the campaign performance indicators.»
 *
 * Two surfaces broke it. The Dashboard opened with `ConciseFindingLine`, a diagnostic carrying a
 * warning icon — so a struggling account led with an alarm before the reader had seen one figure.
 * The Analytics overview opened with `ChangeDiagnosis`, and the note above it argued that leading
 * with «why» was the point of the page.
 *
 * ## Why this reads the SOURCE rather than a rendered tree
 *
 * The rule is about ORDER, and order is a property of the file: a rendering test would need both
 * pages mounted with every request mocked, and it would still only prove the order for the one data
 * state it mocked. Reading the JSX proves it for every state at once, and it fails the moment a
 * component is inserted above the strip — which is the regression being guarded, not a layout bug.
 *
 * `import.meta.glob` rather than `node:fs`: CI typechecks these files, and `node:fs` types are not
 * available to them.
 */
const SOURCES = import.meta.glob('/src/features/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** The surfaces that carry a primary KPI region, and the marker that region renders under. */
const GUARDED = [
  { file: 'DashboardPage.tsx', region: '<MetricStrip' },
  { file: 'AnalyticsPage.tsx', region: '<MetricStrip' },
]

/**
 * Blocks that answer «why», «what changed» or «what should I do» — every one of them belongs below
 * the figures. Named by component, because that is what an author actually inserts.
 */
const NOT_ABOVE = [
  'ChangeDiagnosis',
  'ConciseFindingLine',
  'ContentReading',
  'DiagnosticPanel',
  'AttributionPanel',
  'RecommendedActions',
  'AlertsPanel',
  'BudgetReading',
]

const bodyOf = (source: string) => {
  // Comments name these components while explaining why they sit below — strip them, or the guard
  // reports its own documentation as a violation.
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

  // Imports name every component in the file by definition, and sit above everything.
  return code.replace(/^import[\s\S]*?from\s+['"][^'"]+['"];?$/gm, '')
}

describe('the primary KPI region is the first analytical block on the page', () => {
  it.each(GUARDED)('$file renders no analytical block above $region', ({ file, region }) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith(`/${file}`))
    expect(entry, `${file} was not found — the guard would pass having read nothing`).toBeTruthy()

    const code = bodyOf(entry![1])
    const kpiAt = code.indexOf(region)
    expect(kpiAt, `${file} renders no ${region} — this guard is measuring the wrong file`).toBeGreaterThan(-1)

    const above = NOT_ABOVE.filter((name) => {
      const at = code.indexOf(`<${name}`)
      return at > -1 && at < kpiAt
    })

    expect(
      above,
      `${file} renders ${above.join(', ')} above its primary KPI row — the reader meets a diagnosis before a figure`,
    ).toEqual([])
  })

  /** The guard must have read real files, not an empty glob. */
  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(20)
  })
})
