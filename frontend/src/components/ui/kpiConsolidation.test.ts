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
  /*
   * The DECK, not the folder.
   *
   * This read `src/features/reports/` — every file under it — and the reason given was true of two of
   * them: a printed deck is a designed document with its own type scale, which a client sees as a PDF
   * rather than as the app. `ReportsPage` is not that. It is the ordinary in-app list of reports, it
   * drew four hand-rolled summary cards, and a folder-wide exemption written for the deck is what let
   * it. An exemption belongs to the files its reason is about.
   */
  'src/features/reports/PrintDocument.tsx': 'the printed deck is a designed document with its own scale — a client sees it as a PDF, not as the app',
  'src/features/reports/PrintReport.tsx': 'the print route, for the same reason',
  'src/features/reports/InteractiveReport.tsx': 'the deck on screen, which must match the printed one exactly',
  'src/components/ui/StatCard.tsx': 'the shared card itself',
  'src/components/ui/MetricStrip.tsx': 'the strip, which is the shared card in its multi-metric form',
}

/**
 * Empty, and it took twelve units to get here.
 *
 * This list held the surfaces that drew a figure-above-label card of their own. Each conversion
 * changed what a page looks like, so each belonged in its own unit with its own screenshots rather
 * than smuggled in beside a component change — and the list existed to stop a THIRTEENTH appearing
 * while the twelve were worked through.
 *
 * The last two went with UX-KPI-PRESENTATION-001's final pass: the agency dashboard, which kept its
 * icon and its tone and gave up its type, and the integrations summary, which kept its tone for the
 * same reason — whether «two accounts awaiting credentials» is a warning is a fact about
 * integrations, not about cards.
 *
 * It stays here, empty, because an empty list is a guard and a deleted one is not.
 */
const NOT_YET_MIGRATED: string[] = []

describe('no surface draws its own KPI card', () => {
  it('finds no file that builds a labelled figure without the shared card', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !/\.test\.tsx$/.test(path))
      .filter(([path]) => !Object.keys(EXEMPT).some((prefix) => path.startsWith(prefix)))
      .filter(([, source]) => {
        // The shape of the thing: a rounded card, a tabular figure at KPI weight, in one element.
        const drawsCard = /rounded-2xl[^"'`]*bg-surface/.test(source)
        /*
         * ORDER-INDEPENDENT, because Tailwind classes have none.
         *
         * This required `tnum` to come BEFORE the size in the same class string, and five surfaces
         * wrote `text-2xl font-extrabold tnum` — the same card, the classes in the other order, and
         * the guard walked past every one of them while its migration list sat empty, which read as
         * «all twelve are done». An empty list is only a guard if the scan that fills it can see.
         */
        const classes = source.match(/class(Name)?=\{?[`"'][^`"']*[`"']/g) ?? []
        const drawsFigure = classes.some((c) =>
          /\btnum\b/.test(c) && /text-(2xl|3xl|\[2[0-9]px\])/.test(c) && /font-extrabold/.test(c),
        )
        /*
         * The import is NOT an escape.
         *
         * It used to be: a file that imported the shared card was skipped entirely, so any surface
         * using it once could hand-draw a second card beside it and the guard would agree. Found by
         * injecting exactly that — a hand-drawn card in a file that had just been migrated, which
         * this passed. Reading the source for the SHAPE catches both, and a file that genuinely uses
         * only the shared card has no reason to contain these classes at all.
         */
        return drawsCard && drawsFigure
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
