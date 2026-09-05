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

/**
 * The composition that carries a primary KPI region, and the marker that region renders under.
 *
 * One entry, not two, and it names a FUNCTION rather than a file. `DashboardPage.tsx` used to be
 * listed here and was never routed — `/app/dashboard` renders `<AnalyticsPage surface="dashboard"/>`
 * (router.tsx) — so the guard was watching a page nobody could open while the real one was watched
 * only by inference. The file has since been retired and its coverage moved onto the surface that
 * actually renders; `AnalyticsPage.tsx` is no longer listed because the strip now lives in the
 * composition, which is where the ordering rule belongs.
 *
 * `scope` narrows to `DashboardOverview` on purpose: `AnalyticsOverview` sits in the same file and
 * puts its strip LAST by design, so a file-wide search would read one surface's contract against the
 * other's composition and report a violation that is the requirement.
 */
const GUARDED = [
  { file: 'OverviewCompositions.tsx', scope: 'export function DashboardOverview(', region: '<MetricStrip' },
]

/**
 * And on every OTHER analytics tab, the change decomposition LEADS.
 *
 * «هذي تكون اعلى كل تصنيف في لوحة التحكم والتحليلات باستثناء على نظرة عامة تكون بمكانها الحالي
 * بالاسفل.» This reverses what these four tabs carried: they were made to open with their table and
 * follow with the decomposition, on the reasoning that an analysis should sit under the thing it
 * analyses. The owner's rule is narrower and it is about ONE block — the change decomposition leads
 * every drill-down tab, and «نظرة عامة» is the single exception, where it stays at the bottom.
 *
 * `ContentReading` is deliberately NOT included: the instruction named the «أي الحملات حرّكت
 * الحساب» square, and the creative reading still sits below the ads it reads. Guarding it here would
 * be inventing a rule nobody asked for.
 */
const TAB_ORDER = ['ObjectiveTab', 'CampaignsTab', 'AccountsTab', 'EntityTab'] as const

/**
 * Blocks that answer «why», «what changed» or «what should I do» — every one of them belongs below
 * the figures. Named by component, because that is what an author actually inserts.
 */
const NOT_ABOVE = [
  'ChangeDiagnosis',
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
  it.each(GUARDED)('$scope renders no analytical block above $region', ({ file, scope, region }) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith(`/${file}`))
    expect(entry, `${file} was not found — the guard would pass having read nothing`).toBeTruthy()

    const whole = bodyOf(entry![1])
    const at = whole.indexOf(scope)
    expect(at, `${scope} was not found — the guard would pass having read nothing`).toBeGreaterThan(-1)
    const after = whole.indexOf('\nexport function ', at + 10)
    const code = whole.slice(at, after === -1 ? undefined : after)

    const kpiAt = code.indexOf(region)
    expect(kpiAt, `${scope} renders no ${region} — this guard is measuring the wrong thing`).toBeGreaterThan(-1)

    const above = NOT_ABOVE.filter((name) => {
      const at = code.indexOf(`<${name}`)
      return at > -1 && at < kpiAt
    })

    expect(
      above,
      `${scope} renders ${above.join(', ')} above its primary KPI row — the reader meets a diagnosis before a figure`,
    ).toEqual([])
  })

  it.each(TAB_ORDER)('%s leads with the change decomposition', (fn) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith('/AnalyticsPage.tsx'))
    expect(entry, 'AnalyticsPage.tsx was not found').toBeTruthy()

    const whole = bodyOf(entry![1])
    const start = whole.indexOf(`function ${fn}(`)
    expect(start, `${fn} was not found — the guard would pass having read nothing`).toBeGreaterThan(-1)

    const next = whole.indexOf('\nfunction ', start + 10)
    const body = whole.slice(start, next === -1 ? undefined : next)

    const diagnosis = body.indexOf('<ChangeDiagnosis')
    expect(diagnosis, `${fn} renders no ChangeDiagnosis — this guard is measuring the wrong thing`).toBeGreaterThan(-1)

    /* Whatever this tab's own content is — its table, or the panel around it. */
    for (const content of ['<Panel', '<MetricTable']) {
      const at = body.indexOf(content)
      if (at === -1) continue

      expect(
        at,
        `${fn} renders ${content} before its change decomposition — the owner asked for the decomposition to lead every tab but «نظرة عامة»`,
      ).toBeGreaterThan(diagnosis)
    }
  })

  /**
   * ## The two surfaces, proved independently — SURFACE-COMPOSITION-001
   *
   * `/app/dashboard` and `/app/analytics` mount the same component. For a while that meant they were
   * the same PAGE: whatever order the overview tab was given, both got it — so #289's Dashboard
   * hierarchy silently became the Analytics hierarchy too, satisfying one requirement by violating
   * ANALYTICS-DIFFERENTIATION-001.
   *
   * The compositions now live in `OverviewCompositions.tsx`, one function each, over ONE
   * `useOverviewData`. These cases hold each contract on its own function, so neither can be
   * satisfied by the other.
   */
  const sectionsOf = (fn: string) => {
    const entry = Object.entries(SOURCES).find(([path]) => path.endsWith('/OverviewCompositions.tsx'))
    expect(entry, 'OverviewCompositions.tsx was not found — the guard would pass having read nothing').toBeTruthy()

    const whole = bodyOf(entry![1])
    const start = whole.indexOf(`export function ${fn}(`)
    expect(start, `${fn} was not found — the guard would pass having read nothing`).toBeGreaterThan(-1)

    const next = whole.indexOf('\nexport function ', start + 10)
    const body = whole.slice(start, next === -1 ? undefined : next)

    /* The major sections, in the order the file renders them, deduplicated to first appearance. */
    const MAJOR = [
      'MetricStrip',
      'Panel',
      'RateTrend',
      'UnifiedCampaignOverview',
      'DistributionBars',
      'DiagnosticPanel',
      'ChangeDiagnosis',
    ]
    return MAJOR.map((tag) => ({ tag, at: body.indexOf(`<${tag}`) }))
      .filter((x) => x.at > -1)
      .sort((a, b) => a.at - b.at)
      .map((x) => x.tag)
  }

  /**
   * DASHBOARD — «ماذا يحدث الآن؟» The figures lead and the reasoning is last.
   *
   * «Primary metrics MUST remain the first analytical region. Do not place explanatory/diagnostic
   * cards above them» — and «قوم بازالة البيانات هذه من لوحة التحكم نظرة عامة او اجعلها اخر نظرة
   * عامة بالاسفل»: nothing that DRAWS may sit below the first block that EXPLAINS.
   */
  it('the Dashboard opens on its figures and closes on its reasoning', () => {
    const order = sectionsOf('DashboardOverview')

    expect(order[0], `the Dashboard opens on ${order[0]}, not its KPI row`).toBe('MetricStrip')
    expect(order.at(-1), `the Dashboard closes on ${order.at(-1)}, not its diagnosis`).toBe('ChangeDiagnosis')

    const lastDrawing = Math.max(
      ...['MetricStrip', 'Panel', 'RateTrend', 'UnifiedCampaignOverview'].map((t) => order.indexOf(t)),
    )
    const firstReasoning = Math.min(...['DiagnosticPanel', 'ChangeDiagnosis'].map((t) => order.indexOf(t)))

    expect(
      firstReasoning,
      `the Dashboard renders ${order[firstReasoning]} before ${order[lastDrawing]} — the reader meets the reasoning before the picture`,
    ).toBeGreaterThan(lastDrawing)
  })

  /**
   * ANALYTICS — «لماذا حدث؟» It opens on decision modules, not on the Dashboard's headline.
   *
   * «Do NOT force Analytics into the Dashboard order … Analytics should open with analytical context
   * and decision modules, not reproduce KPI row → dashboard charts → diagnosis at the bottom.»
   *
   * The strip is still here and must be — a diagnosis a reader cannot check against the totals it
   * was computed from is an assertion — but as EVIDENCE, after the reading it supports.
   */
  it('Analytics opens on decision modules, with the figures as evidence', () => {
    const order = sectionsOf('AnalyticsOverview')

    expect(order[0], `Analytics opens on ${order[0]} — the Dashboard's headline, not a decision module`).not.toBe('MetricStrip')
    expect(order, 'Analytics renders no decision module before its figures').toContain('DiagnosticPanel')
    expect(order, 'Analytics renders no distribution — «where the money sits» is its own question').toContain('DistributionBars')

    expect(
      order.indexOf('MetricStrip'),
      'Analytics leads with its figures — that is the Dashboard\'s job, and this surface argues rather than reports',
    ).toBeGreaterThan(order.indexOf('DiagnosticPanel'))

    /* «باستثناء على نظرة عامة تكون بمكانها الحالي بالاسفل» — on BOTH overviews, this one stays last. */
    expect(order.at(-1), 'the change decomposition must stay at the bottom of «نظرة عامة»').toBe('ChangeDiagnosis')
  })

  /**
   * And the regression the owner asked for by name: «Add a regression that fails if Dashboard and
   * Analytics render the same ordered major sections.»
   *
   * Compared as SEQUENCES rather than as sets, because the sets are deliberately close — the whole
   * point is one engine — and it is the order that carries the difference between «what is
   * happening» and «why».
   */
  it('the Dashboard and Analytics do not render the same ordered sections', () => {
    const dashboard = sectionsOf('DashboardOverview')
    const analytics = sectionsOf('AnalyticsOverview')

    expect(dashboard.length, 'the Dashboard composition has no major sections to compare').toBeGreaterThan(3)
    expect(analytics.length, 'the Analytics composition has no major sections to compare').toBeGreaterThan(3)

    expect(
      analytics.join(' → '),
      'the Dashboard and Analytics render the same ordered sections — one information architecture for two questions',
    ).not.toBe(dashboard.join(' → '))
  })

  /** The guard must have read real files, not an empty glob. */
  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(20)
  })
})
