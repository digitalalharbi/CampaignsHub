import { describe, expect, it } from 'vitest'
import { assetAspect, mediaFit } from './adPreview'

/**
 * CONTENT-PREVIEW-FIT-001 — a crop is a claim about the ad that the product invented.
 *
 * ## What the owner saw
 *
 * «Opening a creative can crop the image/video. Portrait / Story / 9:16 content is especially
 * affected. The media is being forced into a stage that does not respect the source aspect ratio,
 * so part of the actual advertisement is lost.»
 *
 * ## The rule
 *
 * Cover only where the stage is provably the asset's own shape — there it crops nothing and merely
 * avoids a hairline of background. Contain everywhere else, which is never wrong.
 *
 * The rule it replaces asked «is this portrait», which left a square creative, a 4:5 and every
 * asset whose dimensions were never sent covered into a landscape box; and it read the asset's
 * shape from `creative.width/height` while the FRAME read `preview.aspect`, so the two could
 * disagree about one creative and crop a story inside a 9:16 frame.
 */
describe('how media is fitted to its stage', () => {
  /**
   * Owner regressions 5 and 6 — nothing on a judging surface is cropped, whatever its shape.
   *
   * Including the case the first attempt at this rule got wrong: an asset whose STATED shape
   * matches the stage. The stated aspect describes the ad, not the file — platforms return a
   * landscape cover for a 9:16 video routinely — so it cannot license a crop. Measured on the
   * seeded library, six cards were drawn at 0.563 and 1.775 against an intrinsic 1.000 under
   * exactly that reasoning.
   */
  it('contains on every surface where a creative is being judged', () => {
    for (const asset of ['vertical', 'square', 'horizontal', null] as const) {
      for (const stage of ['vertical', 'square', 'horizontal', null] as const) {
        expect(mediaFit(asset, stage), `${asset} in ${stage} was cropped`).toBe('contain')
        expect(mediaFit(asset, stage, 'viewer'), `${asset} in ${stage} was cropped in a viewer`).toBe('contain')
      }
    }
  })

  /**
   * CONTENT-THUMB-FILL-001 — a thumbnail FILLS, whatever is known about the asset.
   *
   * It used to fill only where the shape was known AND matched the frame. Every caller draws into a
   * square of 36 to 64 pixels, so that meant a horizontal still letterboxed with grey bands down two
   * sides — and the two callers that pass no shape at all contained every asset they ever drew.
   *
   * The rule it came from, «a story is contained, never covered», is about a surface somebody READS.
   * Nobody reads a call to action at 36 pixels: a thumbnail is what you recognise a row by, and the
   * whole asset is one click away in the viewer, which still contains.
   */
  it('covers a thumbnail whatever is known about its shape', () => {
    for (const asset of ['square', 'vertical', 'horizontal', null] as const) {
      expect(mediaFit(asset, 'square', 'thumb'), `${asset} was letterboxed in a thumbnail`).toBe('cover')
    }
  })
})

/**
 * The shape reader the rule above depends on — and the SQUARE band is the point.
 *
 * `previewShape` answers «portrait or not», which calls a 1:1 creative landscape. That answer is
 * fine for choosing a tall frame or a wide one and wrong for deciding whether covering will crop.
 */
describe('reading an asset’s own shape', () => {
  it('tells a square from a landscape, which the portrait-or-not reader cannot', () => {
    expect(assetAspect(1080, 1080)).toBe('square')
    expect(assetAspect(1920, 1080)).toBe('horizontal')
    expect(assetAspect(1080, 1920)).toBe('vertical')
  })

  it('reads the platform’s own ratio string where the numbers are absent', () => {
    expect(assetAspect(null, null, '9:16')).toBe('vertical')
    expect(assetAspect(null, null, '1080x1080')).toBe('square')
    expect(assetAspect(null, null, '1.91:1')).toBe('horizontal')
  })

  it('calls a 4:5 portrait rather than nearly-square, because the platforms serve it as one', () => {
    expect(assetAspect(1080, 1350)).toBe('vertical')
  })

  it('says nothing where nothing was stated', () => {
    expect(assetAspect(null, null, null)).toBeNull()
    expect(assetAspect(0, 0, 'unparseable')).toBeNull()
  })
})
