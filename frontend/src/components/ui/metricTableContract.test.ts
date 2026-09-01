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
  'src/features/analytics/AttributionPanel.tsx': 'attribution rows pair a claim with a store fact; the columns are not metrics',
  'src/features/reports/ReportsPage.tsx': 'a list of reports, not an analytical table',
  'src/features/reports/InteractiveReport.tsx': 'the deck lays out to a fixed slide, and its own layout gate measures it',
  'src/features/reports/PrintDocument.tsx': 'the printed page has no sort control and cannot scroll sideways',
  'src/features/reports/SharedCreativeSection.tsx': 'the funnel ladder inside the creative detail; the comparison table is migrated',
  'src/features/content/CreativesPage.tsx': 'the library grid, pending CONTENT-DETAIL-MODAL-001',
  'src/features/content/CreativePulseSection.tsx': 'says in its own note that it hand-rolls one; pending',
  'src/features/content/CreativeGroupsPage.tsx': 'pending',
  'src/features/content/CreativeDetailPage.tsx': 'pending',
  'src/features/content/CreativeCompare.tsx': 'pending',
  'src/features/campaigns/CampaignCommandCenter.tsx': 'pending',
  'src/features/campaigns/CampaignsPage.tsx': 'pending',
  'src/features/campaigns/CampaignDepthTabs.tsx': 'pending',
  'src/features/campaigns/overview/UnifiedCampaignOverview.tsx': 'pending',
  'src/features/campaigns/CampaignComparison.tsx': 'pending',
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
describe('the store tab uses the product’s table', () => {
  it('renders through the primitive and is no longer exempt', () => {
    const source = TREE['/src/features/analytics/StoreFunnelTab.tsx'] ?? ''

    expect(source).toContain("from '@/components/ui/MetricTable'")
    expect(source).toContain('<MetricTable')
    expect(Object.keys(EXEMPT)).not.toContain('src/features/analytics/StoreFunnelTab.tsx')
  })
})
