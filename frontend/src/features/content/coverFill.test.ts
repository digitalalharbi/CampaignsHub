import { describe, expect, it } from 'vitest'
import { mediaFit, mediaFitClass } from './adPreview'

/**
 * CONTENT-COVER-FILL-001 — a cover fills its frame; the viewer still shows the whole ad.
 *
 * ## Two instructions from the owner, about two different surfaces
 *
 * The first, which `CONTENT-PREVIEW-FIT-001` records: «Opening a creative can crop the image/video…
 * part of the actual advertisement is lost. Default media fit: object-fit: contain. NOT cover.» That
 * is about the VIEWER, and the owner has since confirmed it: «after opening, the preview is
 * excellent — the original content shows at its own dimensions and looks natural.»
 *
 * The second is about the COVER: «the content cover is still not right — it must be the full cover,
 * not a tall shape … the dimensions need adjusting so the cover holds an image that fills the whole
 * cover, not only a portrait strip.»
 *
 * They only look contradictory until the surface is named. The card is a COVER: a tile in a wall
 * whose job is to be recognised at a glance, and whose frame was taking each asset's own aspect —
 * so a 9:16 story produced a tall card and the grid became a row of strips. The viewer is where the
 * ad is JUDGED, and it loses nothing.
 *
 * What the crop costs is real and is not denied: a story's logo and call to action sit at the top and
 * bottom. That is why the whole asset stays one click away, uncropped, and why the viewer's own guard
 * is untouched.
 */
describe('the cover surface fills, and nothing else starts to', () => {
  it('covers on a card, whatever shape the asset is', () => {
    expect(mediaFit('vertical', 'square', 'cover')).toBe('cover')
    expect(mediaFit('horizontal', 'square', 'cover')).toBe('cover')
    expect(mediaFit(null, null, 'cover')).toBe('cover')
  })

  it('keeps the viewer contained, which is the surface the first instruction was about', () => {
    expect(mediaFit('vertical', 'square', 'viewer')).toBe('contain')
    expect(mediaFit('vertical', 'vertical', 'viewer')).toBe('contain')
  })

  it('keeps the stage contained, including where the shapes happen to agree', () => {
    expect(mediaFit('square', 'square', 'stage')).toBe('contain')
    expect(mediaFit('vertical', 'vertical')).toBe('contain')
  })

  it('leaves the navigation thumb rule exactly as it was', () => {
    expect(mediaFit('square', 'square', 'thumb')).toBe('cover')
    expect(mediaFit('vertical', 'square', 'thumb')).toBe('contain')
  })

  it('spells the utility so no surface writes the class itself', () => {
    expect(mediaFitClass('vertical', 'square', 'cover')).toBe('object-cover')
    expect(mediaFitClass('vertical', 'square', 'viewer')).toBe('object-contain')
  })
})
