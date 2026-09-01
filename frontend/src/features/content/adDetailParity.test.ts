import { describe, expect, it } from 'vitest'

/**
 * CONTENT-DETAIL-MODAL-001 — one way of reading an ad's media, on every surface that opens one.
 *
 * THREE modals open one ad: the content library's `CreativeViewer`, the report's `ReportAdDetail`,
 * and `AdPreviewDialog`, which analytics opens from its ad tables. Two of them played a film and
 * paged a carousel; the third drew both as a still. Same ad, two behaviours, decided by which screen
 * the reader happened to open it from — and a poster frame of a video is a plausible-looking picture
 * of the wrong thing, which is why the drift survived review.
 *
 * Enumerating them here is the point: the drift began because the second was written without the
 * first being a list anybody could check against.
 *
 * What is asserted is the OUTCOME, not the mechanism. `AdPreviewDialog` and `ReportAdDetail` route
 * through `readPreview`; `CreativeViewer` decides from its own `showing` state, because it offers
 * the reader an explicit toggle between the poster and the film that the other two do not. Both
 * routes are defensible and a guard that demanded one would be enforcing a refactor rather than a
 * behaviour. What is NOT defensible is a detail modal that cannot play a film, cannot page a
 * carousel, or cannot be left with the keyboard, and those are what this holds.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The modals that open ONE ad on a reader's request. Cards and grids are not detail surfaces. */
const DETAIL_SURFACES = [
  'src/features/content/AdPreviewDialog.tsx',
  'src/features/content/CreativeViewer.tsx',
  'src/features/reports/ReportAdDetail.tsx',
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
