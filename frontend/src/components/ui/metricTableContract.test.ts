import { describe, expect, it } from 'vitest'

/**
 * TABLE-PRESENTATION-CONTRACT-001 — one analytical table, and a list of the surfaces still outside it.
 *
 * ## What the product looked like without this
 *
 * `MetricTable` was defined inside `AnalyticsPage`, a three-thousand-line page module, so a surface
 * that wanted it had to import the page. None did. They wrote their own instead — seventeen files
 * across analytics, reports, content and campaigns, each with its own answer to where a number sits
 * in its cell, whether a column can be ordered, what an unreported figure looks like, and whether
 * the table or the page scrolls sideways on a phone.
 *
 * The worst of them was in the document a CLIENT keeps: the shared creative comparison left-aligned
 * every figure and could not be sorted at all.
 *
 * ## TABLE-NUMERIC-ALIGNMENT-001 raised the bar this guard holds
 *
 * The owner has now reported the same presentation defect five times, and the instruction is that it
 * is not to be patched a sixth. So two things changed here. The two client-facing report surfaces —
 * the deck a client reads and the funnel inside their creative detail — came OFF this list, because
 * the document the client keeps is where the defect was doing its damage. And every remaining
 * «pending» was replaced with the actual obstacle, because a list of reasons is a plan and a list of
 * «pending» is a list nobody can act on.
 *
 * ## Why an exemption list rather than a clean rule
 *
 * Seventeen surfaces cannot be migrated in one change without a rewrite nobody could review. So the
 * guard does the one thing that matters immediately: it stops the list GROWING. A new analytical
 * table has to justify itself by being added here, in front of somebody, rather than appearing.
 *
 * Each exemption is asserted to still contain a table, so the list cannot be satisfied by deleting
 * an entry that has already been migrated — the same rule the typography guard follows. Migrating a
 * surface means removing its line, and the count below going down is the only direction this list
 * is allowed to move.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/{analytics,reports,content,campaigns}/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * Comments are stripped first: the files that MIGRATED describe the table they used to hand-roll,
 * and a scan that reads prose reports the explanation as the offence — which teaches the next author
 * to delete the explanation.
 */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

/** Surfaces that still render their own analytical table, and why each is still waiting. */
const EXEMPT: Record<string, string> = {
  'src/features/reports/ReportsPage.tsx': 'a list of reports and a share access log — identifiers, times and controls, with nothing a reader compares across rows. Out of scope by KIND, not waiting to be migrated',
  'src/features/reports/PrintDocument.tsx': 'a printed page has no sort control, no hover and no scroller, and those three are most of what the primitive is. Out of scope by kind',
  'src/features/content/CreativePulseSection.tsx': 'transposed — metrics down the side, creative type across. The primitive has no shape for that yet; the file already applies the centring rule by hand and says so in its own note',
  'src/features/content/CreativeCompare.tsx': 'transposed, like the pulse table',
  'src/features/campaigns/CampaignComparison.tsx': 'transposed, like the pulse table',
  'src/features/content/CreativesPage.tsx': 'the list view of a grid: selection checkboxes and media previews per row',
  'src/features/content/CreativeGroupsPage.tsx': 'grouped rows with a media thumbnail column',
  'src/features/content/CreativeDetailPage.tsx': 'three tables, one of which is a per-day series that wants a chart rather than a migration',
  'src/features/campaigns/CampaignCommandCenter.tsx': 'inline editing per row — the cells are controls, and the spec API needs an editable kind first',
  'src/features/campaigns/CampaignsPage.tsx': 'row selection and bulk actions live inside the table',
  'src/features/campaigns/CampaignDepthTabs.tsx': 'nested expansion rows, which the primitive does not model',
  'src/features/campaigns/overview/UnifiedCampaignOverview.tsx': 'per-row sparklines and a drag handle',
}

const handRolled = () =>
  Object.entries(TREE)
    .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
    .filter(([path]) => !/\.test\.tsx?$/.test(path))
    .filter(([, source]) => /<table[\s>]/.test(withoutComments(source)))
    .map(([path]) => path)

describe('the analytical table contract', () => {
  it('has no surface outside the primitive that is not written down', () => {
    const offenders = handRolled().filter((path) => !(path in EXEMPT))

    expect(
      offenders,
      'A new analytical table appeared. Use `MetricTable` from `@/components/ui/MetricTable`, or add\n'
      + 'the surface to EXEMPT with the reason it cannot use it:\n  ' + offenders.join('\n  '),
    ).toEqual([])
  })

  it('lists no surface that has already been migrated', () => {
    const stale = Object.keys(EXEMPT).filter((path) => !handRolled().includes(path))

    expect(
      stale,
      'these are exempted from a rule they no longer break — remove them, so the list keeps shrinking:\n  ' + stale.join('\n  '),
    ).toEqual([])
  })

  /**
   * The client's comparison table is the one that had to move first.
   *
   * It is the most scrutinised table in the product — a client reads it in a document they keep —
   * and it was the furthest from the product's conventions.
   */
  it('has migrated the client-facing creative comparison', () => {
    const source = TREE['/src/features/reports/SharedCreativeSection.tsx'] ?? ''

    expect(source).toContain("from '@/components/ui/MetricTable'")
    expect(source).toContain('<MetricTable head={head} rows={rows} values={values} />')
  })
})

/**
 * STORE-TABLE-PRESENTATION-001 — the store tab is a consumer now, and the list is one shorter.
 *
 * The exemption list is only meaningful if it shrinks. This pins the direction: the store tab must
 * stay migrated, so a later change that hand-rolls a table there again has to remove this test in
 * front of somebody rather than quietly adding a line to EXEMPT.
 */
describe('the analytics tabs use the product’s table', () => {
  it.each([
    'src/features/analytics/StoreFunnelTab.tsx',
    'src/features/analytics/AttributionPanel.tsx',
  ])('%s renders through the primitive and is no longer exempt', (path) => {
    const source = TREE['/' + path] ?? ''

    expect(source).toContain("from '@/components/ui/MetricTable'")
    expect(source).toContain('<MetricTable')
    expect(Object.keys(EXEMPT)).not.toContain(path)
  })

  /**
   * The debt is a number, and it is only allowed to fall.
   *
   * `has no surface outside the primitive that is not written down` stops the list growing by
   * accident; this stops it growing on purpose without somebody editing the ceiling and being asked
   * why.
   */
  it('has not grown', () => {
    expect(Object.keys(EXEMPT).length).toBeLessThanOrEqual(12)
  })
})
