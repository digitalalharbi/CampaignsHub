import { describe, expect, it } from 'vitest'

/**
 * CONTENT-DETAIL-MODAL-001 — one way of reading an ad's media, on every surface that opens one.
 *
 * ## The list is now two, and that is the fix rather than a shrinking guard
 *
 * There were three: the library's full-screen `CreativeViewer`, the report's `ReportAdDetail`, and
 * `AdPreviewDialog`. Two played a film and paged a carousel; the third drew both as a still. Same
 * ad, two behaviours, decided by which screen the reader opened it from.
 *
 * AD-PREVIEW-DEFAULT-001 retired the viewer. It was the heaviest of the three and the one carrying a
 * rail of figures that the creative's OWN page draws in more depth — identity, copy, figures, funnel,
 * trend, per platform, peers, fatigue, evidence, insights, against that rail's four blocks — so a
 * reader met a shallower copy of the page with no route from it to the real one. Every surface now
 * opens the quick popup, and the popup links to the page.
 *
 * Deleting an entry here is normally how a guard is quietly defeated; deleting the COMPONENT is what
 * makes this one honest, so the list asserts each file still exists.
 *
 * What is asserted is the OUTCOME, not the mechanism: a detail modal that cannot play a film, cannot
 * page a carousel, or cannot be left with the keyboard.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The modals that open ONE ad on a reader's request. Cards and grids are not detail surfaces. */
const DETAIL_SURFACES = [
  'src/features/content/AdPreviewDialog.tsx',
  'src/features/reports/ReportAdDetail.tsx',
]

/**
 * And the surfaces that OPEN one. A fourth modal appearing is how the drift started.
 *
 * Every one of these opens `AdPreviewDialog`; a file here that reaches for anything else is growing
 * the second detail surface this guard exists to prevent.
 */
const OPENERS = [
  'src/features/content/CreativesPage.tsx',
  'src/features/content/CreativeDetailPage.tsx',
  'src/features/analytics/AnalyticsPage.tsx',
  'src/features/reports/SharedCreativeSection.tsx',
]

const sourceOf = (path: string): string => {
  const entry = Object.entries(TREE).find(([key]) => key.replace(/^\/+/, '') === path)

  if (entry === undefined) {
    throw new Error(`${path} is not in the tree — this guard is watching a file that moved.`)
  }

  return entry[1]
}

describe('every surface that opens one ad', () => {
  it('is a file this guard can still find', () => {
    for (const path of DETAIL_SURFACES) {
      expect(() => sourceOf(path)).not.toThrow()
    }
  })

  it('the retired full-screen viewer is gone, not merely unused', () => {
    const stale = Object.keys(TREE).filter((k) => /CreativeViewer\.tsx$|CreativeQuickFacts\.tsx$/.test(k))

    expect(stale, 'the viewer is still in the tree — an unused second detail surface invites a third').toEqual([])
  })

  for (const path of OPENERS) {
    it(`«${path.split('/').pop()}» opens the canonical popup`, () => {
      expect(sourceOf(path)).toContain('<AdPreviewDialog')
    })
  }

  for (const path of DETAIL_SURFACES) {
    it(`«${path.split('/').pop()}» can play a film and page a carousel`, () => {
      const source = sourceOf(path)

      /*
       * RENDERED, not merely imported.
       *
       * The first spelling of this guard looked for the identifier anywhere in the file, and an
       * injected defect that deleted the JSX but left the import passed it — a guard that watches
       * the import list is watching the one line a dead component keeps.
       */
      expect(source, 'a video shown as its poster frame is a picture of the wrong thing').toContain(
        '<CreativeVideoPlayer',
      )
      expect(source, 'a carousel reduced to its first card hides four fifths of the ad').toContain(
        '<CreativeCarousel',
      )
    })

    /** A modal a keyboard cannot leave is a trap, and both of these open from grids people tab through. */
    it(`«${path.split('/').pop()}» closes on Escape`, () => {
      expect(sourceOf(path)).toMatch(/key === 'Escape'/)
    })
  }
})
