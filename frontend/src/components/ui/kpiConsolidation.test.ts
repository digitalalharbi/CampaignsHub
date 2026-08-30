import { describe, expect, it } from 'vitest'

/**
 * UX-KPI-PRESENTATION-001 — one card draws a labelled figure, and the surfaces call it.
 *
 * Nine components used to draw this object. Each was defensible alone and the set was not: a row of
 * cards on one page did not line up with the row on the next, because `p-3.5` met `p-4` and a 12px
 * label met an 11px uppercase one. Consolidating them is only half the work — the other half is
 * stopping the tenth, which is what this scans for.
 *
 * Two exemptions, both real and both named. A report deck is a designed document with its own type
 * scale, and a `Fact` row inside a card is deliberately NOT a KPI card: a card nested in a card is a
 * second reading of the same importance, which is the confusion the shared card exists to remove.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Files allowed to draw their own, each for a stated reason. */
const EXEMPT = {
  'src/features/reports/': 'the report deck is a designed document with its own scale — a client sees it as a PDF, not as the app',
  'src/components/ui/StatCard.tsx': 'the shared card itself',
  'src/components/ui/MetricStrip.tsx': 'the strip, which is the shared card in its multi-metric form',
}

/**
 * Not yet migrated, and named so the number can only go down.
 *
 * These twelve draw a figure-above-label card of their own. They are a different composition from
 * the shared card rather than a different opinion about the same one, so converting them changes
 * what each page looks like and belongs in its own unit with its own screenshots — not smuggled in
 * beside a component change. What this list does is stop a THIRTEENTH appearing: a new file that
 * draws its own card is not on it, and fails.
 */
const NOT_YET_MIGRATED = [
  'src/features/admin/BillingPage.tsx',
  'src/features/admin/CutoverPage.tsx',
  'src/features/admin/PlatformOverviewPage.tsx',
  'src/features/agency/AgencyDashboardPage.tsx',
  'src/features/billing/FinanceOverviewPage.tsx',
  'src/features/billing/InvoicesPage.tsx',
  'src/features/projects/PlatformIntegrationsPanel.tsx',
  'src/features/requests/portal/ClientDashboardPage.tsx',
]

describe('no surface draws its own KPI card', () => {
  it('finds no file that builds a labelled figure without the shared card', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !/\.test\.tsx$/.test(path))
      .filter(([path]) => !Object.keys(EXEMPT).some((prefix) => path.startsWith(prefix)))
      .filter(([, source]) => {
        // The shape of the thing: a rounded card, a tabular figure at KPI weight, in one element.
        const drawsCard = /rounded-2xl[^"'`]*bg-surface/.test(source)
        const drawsFigure = /tnum[^"'`]*text-(2xl|3xl|\[2[0-9]px\])[^"'`]*font-extrabold/.test(source)
        return drawsCard && drawsFigure && !source.includes("from '@/components/ui/StatCard'")
      })
      .map(([path]) => path)

    expect(
      offenders.filter((path) => !NOT_YET_MIGRATED.includes(path)),
      'a labelled figure belongs to StatCard — this file draws its own and is not on the migration list',
    ).toEqual([])
  })

  /** The scan reads something: a guard over an empty set passes for the wrong reason. */
  it('reads the source tree', () => {
    expect(Object.keys(TREE).length).toBeGreaterThan(200)
  })

  /**
   * The migration list is a list of real files, and it shrinks.
   *
   * An entry that no longer draws its own card is one somebody migrated and forgot to remove, and a
   * list carrying stale entries stops being a count of the work left.
   */
  it('names only files that still draw their own card', () => {
    const stale = NOT_YET_MIGRATED.filter((path) => {
      const source = TREE['/' + path]
      if (source === undefined) return true
      return source.includes("from '@/components/ui/StatCard'")
    })

    expect(stale, 'these are migrated (or gone) — take them off the list').toEqual([])
  })
})
