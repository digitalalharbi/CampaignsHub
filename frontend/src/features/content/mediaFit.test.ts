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
  /** Owner regression 5 — a 9:16 story is fully contained wherever its stage is not 9:16. */
  it('contains a portrait asset in a stage that is not its shape', () => {
    expect(mediaFit('vertical', 'horizontal')).toBe('contain')
    expect(mediaFit('vertical', null)).toBe('contain')
  })

  /** Owner regression 6 — square and 4:5 are not cropped either, which the old rule could not say. */
  it('contains a square asset in the guessed landscape frame', () => {
    expect(mediaFit('square', null)).toBe('contain')
    expect(mediaFit('square', 'horizontal')).toBe('contain')
  })

  /** An asset whose shape nobody stated is a shape nothing may be cropped to. */
  it('never covers an asset of unknown shape', () => {
    expect(mediaFit(null, 'horizontal')).toBe('contain')
    expect(mediaFit(undefined, null)).toBe('contain')
  })

  it('covers only where the stage is the asset’s own shape', () => {
    expect(mediaFit('vertical', 'vertical')).toBe('cover')
    expect(mediaFit('square', 'square')).toBe('cover')
    expect(mediaFit('horizontal', 'horizontal')).toBe('cover')
    /* …including the frame's own 16:9 fallback, which a 16:9 film fills exactly. */
    expect(mediaFit('horizontal', null)).toBe('cover')
  })

  /** A viewer opened on one creative always contains: there is room, and nothing may be lost. */
  it('always contains when the surface is a viewer', () => {
    for (const asset of ['vertical', 'square', 'horizontal', null] as const) {
      expect(mediaFit(asset, asset, 'viewer'), `${asset} was cropped in a viewer`).toBe('contain')
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
