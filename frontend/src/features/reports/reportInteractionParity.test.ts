import { describe, expect, it } from 'vitest'

/**
 * REPORT-INTERACTION-PARITY-001 — the same report, opened three ways, behaves the same way.
 *
 * A report reaches a reader as an interactive deck, as a live shared link, or as a printed document.
 * The first two are pages a person operates; the third is paper. What must not happen is the first
 * two disagreeing with each other — an ad card that opens in the deck and does nothing on the link
 * is the same defect production already shipped once, and the reader who meets it concludes the
 * report is broken rather than that a feature is missing.
 *
 * ## Why a source scan and not three render tests
 *
 * Render tests already cover what each surface does; they cannot see that a FOURTH surface was added
 * without the affordance, or that one of the three quietly lost it. This asks the parity question
 * directly: every surface that renders the ads section, except the one that prints, hands it a way
 * to open an ad.
 *
 * ## And the printed page is absent, not inert
 *
 * `PrintDocument` deliberately passes no handler. A control that cannot succeed must not invite the
 * press — on paper there is nothing to open, and a card that looks pressable in a PDF is worse than
 * one that plainly is not.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/reports/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Comments are stripped: the files that FIXED this describe the defect at length. */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

/** Surfaces that render the ads section, by behaviour rather than by a list somebody maintains. */
function surfacesRenderingAds(): string[] {
  return Object.entries(TREE)
    .map(([path, source]) => [path.replace(/^\/+/, ''), withoutComments(source)] as const)
    .filter(([path]) => !/\.test\.tsx$/.test(path))
    .filter(([, source]) => /<ReportAdsSection/.test(source))
    .map(([path]) => path)
}

describe('a report behaves the same way whichever surface it reaches the reader on', () => {
  it('finds the surfaces that render the ads', () => {
    // Two operable surfaces today: the interactive deck and the live shared link.
    expect(surfacesRenderingAds().length).toBeGreaterThanOrEqual(2)
  })

  /**
   * Every operable surface can open an ad.
   *
   * Production served a client a report whose ad cards were `<article>` elements with no handler:
   * five cards that looked pressable and did nothing. Fixing the deck and leaving the link behind
   * would have reproduced it for exactly the reader who cannot ask anybody about it.
   */
  it('lets an ad be opened wherever the ads are operable', () => {
    const offenders = surfacesRenderingAds().filter((path) => {
      const source = withoutComments(TREE['/' + path] ?? '')

      return !/onOpen=\{/.test(source)
    })

    expect(
      offenders,
      'these surfaces render the ads and offer no way to open one:\n  ' + offenders.join('\n  '),
    ).toEqual([])
  })

  /** And each of them mounts the detail that opening leads to. */
  it('mounts the read-only detail on every surface that can open one', () => {
    const offenders = surfacesRenderingAds().filter((path) => {
      const source = withoutComments(TREE['/' + path] ?? '')

      return !/<ReportAdDetail/.test(source)
    })

    expect(offenders, 'these surfaces open an ad into nothing:\n  ' + offenders.join('\n  ')).toEqual([])
  })

  /**
   * The printed page offers no handler, and that is the correct behaviour rather than an omission.
   *
   * `ReportAdsSection` renders a plain `<article>` when no `onOpen` is given, so paper gets a card
   * that does not pretend. Asserting it here stops somebody «fixing» the print surface into parity
   * with a control that cannot work.
   */
  it('leaves the printed document without a control that cannot succeed', () => {
    const print = withoutComments(TREE['/src/features/reports/PrintDocument.tsx'] ?? '')

    expect(print.length, 'PrintDocument is not in the tree — this guard reads a file that moved').toBeGreaterThan(0)
    expect(/onOpen=\{/.test(print)).toBe(false)
  })
})
