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
 * And on every OTHER analytics tab, the analytical blocks come after that tab's own primary content.
 *
 * The owner's correction is about the whole system, not the two surfaces that happen to carry a
 * `MetricStrip`: «the system is built on the primary picture of the data — the cards, and beneath
 * them the chart and the analytical side». Four tabs opened with `ChangeDiagnosis` and no figures
 * above it at all, so a reader arriving at Objectives, Campaigns, Accounts or Ad sets met a
 * diagnosis before a single number.
 *
 * Checked as «the first analytical block is not the first thing rendered»: each of these tabs leads
 * with a `<Panel>` — its table or its chart — and the decomposition follows.
 */
const TAB_ORDER = ['ObjectiveTab', 'CampaignsTab', 'AccountsTab', 'EntityTab', 'CreativeTab'] as const

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

  it.each(TAB_ORDER)('%s renders its own content before any analytical block', (fn) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith('/AnalyticsPage.tsx'))
    expect(entry, 'AnalyticsPage.tsx was not found').toBeTruthy()

    const whole = bodyOf(entry![1])
    const start = whole.indexOf(`function ${fn}(`)
    expect(start, `${fn} was not found — the guard would pass having read nothing`).toBeGreaterThan(-1)

    const next = whole.indexOf('\nfunction ', start + 10)
    const body = whole.slice(start, next === -1 ? undefined : next)

    const panel = body.indexOf('<Panel')
    expect(panel, `${fn} renders no Panel — this guard is measuring the wrong thing`).toBeGreaterThan(-1)

    // Every analytical block this tab may carry, each of which belongs after the tab's own content.
    for (const block of ['<ChangeDiagnosis', '<ContentReading']) {
      const at = body.indexOf(block)
      if (at === -1) continue

      expect(
        at,
        `${fn} renders ${block} before its own content — the reader meets an analysis before a figure`,
      ).toBeGreaterThan(panel)
    }
  })

  /**
   * And on the surfaces themselves, EVERY drawing comes before the first block of reasoning.
   *
   * «قوم بازالة البيانات هذه من لوحة التحكم نظرة عامة او اجعلها اخر نظرة عامة بالاسفل لان بالاساس
   * بالنظام الشارت والرسوم التفاعلية.» Moving the diagnosis below the KPI row was not enough: the
   * curve, the rate trends, the funnel and the platform split all still sat underneath it, so the
   * reader met a paragraph of reasoning before the drawings the product is built on.
   *
   * The rule this encodes is the ORDER of a surface — figures, then everything that draws, then the
   * reading of why — so it is checked as «the last drawing precedes the first reasoning block»
   * rather than as a fixed list of positions. Inserting a new chart is free; inserting it below the
   * diagnosis is what fails.
   */
  const ORDERED = [
    {
      what: 'the Analytics Overview',
      file: 'AnalyticsPage.tsx',
      /* Scoped to the tab, because the file holds a dozen others. */
      scope: 'function PerformanceTab(',
      draws: ['<MetricStrip', '<Panel', '<RateTrend', '<UnifiedCampaignOverview'],
      explains: ['<DiagnosticPanel', '<ChangeDiagnosis'],
    },
    {
      /*
       * `DashboardPage.tsx` is NOT what `/app/dashboard` renders — the route is
       * `<AnalyticsPage surface="dashboard" />`, and this file is imported by nothing but its own
       * four test files. It is kept in the same order as the surface it used to be, so that
       * re-mounting it cannot quietly reintroduce the inversion; it is named here for what it is
       * rather than as «the Dashboard», because a guard that overstates its subject is worse than
       * no guard.
       */
      what: 'the unrouted DashboardPage',
      file: 'DashboardPage.tsx',
      scope: null,
      draws: ['<MetricStrip', '<UnifiedCampaignOverview', '<Panel'],
      explains: ['<ConciseFindingLine'],
    },
  ] as const

  it.each(ORDERED)('$what draws everything it draws before it explains anything', ({ file, scope, draws, explains }) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith(`/${file}`))
    expect(entry, `${file} was not found — the guard would pass having read nothing`).toBeTruthy()

    const whole = bodyOf(entry![1])
    let body = whole
    if (scope) {
      const start = whole.indexOf(scope)
      expect(start, `${scope} was not found — the guard would pass having read nothing`).toBeGreaterThan(-1)
      const next = whole.indexOf('\nfunction ', start + 10)
      body = whole.slice(start, next === -1 ? undefined : next)
    }

    const lastDrawing = draws
      .map((tag) => {
        const at = body.lastIndexOf(tag)
        expect(at, `${file} renders no ${tag} — this guard is measuring the wrong thing`).toBeGreaterThan(-1)
        return { tag, at }
      })
      .reduce((a, b) => (b.at > a.at ? b : a))

    for (const tag of explains) {
      const at = body.indexOf(tag)
      expect(at, `${file} renders no ${tag} — this guard is measuring the wrong thing`).toBeGreaterThan(-1)

      expect(
        at,
        `${file} renders ${tag} before ${lastDrawing.tag} — the reader meets the reasoning before the picture`,
      ).toBeGreaterThan(lastDrawing.at)
    }
  })

  /** The guard must have read real files, not an empty glob. */
  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(20)
  })
})
