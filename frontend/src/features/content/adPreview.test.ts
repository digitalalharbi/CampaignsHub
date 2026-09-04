import { describe, expect, it } from 'vitest'
import { absenceLabel, aspectClass, frameAspect, posterSource, previewShape, readPreview } from './adPreview'
import type { CreativePreview } from './api'

/**
 * AD-PREVIEW-001 — the fallback order, and the four different silences.
 *
 * Six surfaces rendered an ad's media and each had worked out the rules again. The campaign command
 * centre asked the SERVER for a boolean — `thumbnail_url !== null || preview_url !== null` — which
 * was wrong in both directions at once: it said yes for a link the presenter withholds, and no for
 * an asset that was sitting in the row.
 */
const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'available',
  kind: 'image',
  image_url: null,
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  ...over,
})

describe('the fallback order', () => {
  it('prefers the full asset over the listing thumbnail', () => {
    const reading = readPreview(preview({ image_url: 'https://cdn/full.jpg', thumbnail_url: 'https://cdn/small.jpg' }), false)

    expect(reading).toEqual({ kind: 'image', src: 'https://cdn/full.jpg', note: null })
  })

  it('falls back to the thumbnail when that is all that arrived', () => {
    expect(posterSource(readPreview(preview({ thumbnail_url: 'https://cdn/small.jpg' }), false))).toBe('https://cdn/small.jpg')
  })

  /**
   * A video is a video even when only its poster arrived.
   *
   * Resolving a playable source is a per-asset call several connectors decline, so the poster is
   * often the only thing there — and it is a real frame the platform chose. Rendering it as an IMAGE
   * would be an honest fallback for the picture and a lie about the ad: a reader deciding between a
   * still and a film needs to know which one ran.
   */
  it('keeps a video a video, and uses its poster as the still', () => {
    const withSource = readPreview(preview({ kind: 'video', video_url: 'https://cdn/v.mp4', thumbnail_url: 'https://cdn/poster.jpg' }), false)

    expect(withSource).toEqual({ kind: 'video', src: 'https://cdn/v.mp4', poster: 'https://cdn/poster.jpg', note: null })
    expect(posterSource(withSource)).toBe('https://cdn/poster.jpg')
  })

  it('shows a video with no playable source as its poster rather than nothing', () => {
    expect(posterSource(readPreview(preview({ kind: 'video', thumbnail_url: 'https://cdn/poster.jpg' }), false)))
      .toBe('https://cdn/poster.jpg')
  })
})

describe('the four silences, told apart', () => {
  it.each([
    ['withheld' as const, 'The platform’s preview link carries a credential'],
    ['expired' as const, 'The platform link has expired'],
    ['unavailable' as const, 'This platform does not expose'],
  ])('%s says what happened', (state, expected) => {
    const reading = readPreview(preview({ state }), false)

    expect(reading.kind).toBe('none')
    expect(absenceLabel(reading, false)).toContain(expected)
  })

  /** «Available» with every URL null is its own state — a carousel whose cards are the only media. */
  it('an available preview with no media at all is not called unavailable', () => {
    const reading = readPreview(preview(), false)

    expect(reading).toMatchObject({ kind: 'none', reason: 'no_media' })
    expect(absenceLabel(reading, false)).toContain('sent no file')
  })

  /** The platform's own note wins: it is more specific than anything written here. */
  it('prefers the presenter’s note over the generic sentence', () => {
    const reading = readPreview(preview({ state: 'expired', note_en: 'Snapchat links expire after 7 days.' }), false)

    expect(absenceLabel(reading, false)).toBe('Snapchat links expire after 7 days.')
  })

  it('answers in Arabic when the reader is reading Arabic', () => {
    expect(absenceLabel(readPreview(preview({ state: 'withheld' }), true), true)).toContain('بيانات اعتماد')
  })

  /** A row with no preview block at all — an older payload — is an absence, never a crash. */
  it('treats a missing preview block as an absence', () => {
    expect(readPreview(undefined, false)).toMatchObject({ kind: 'none', reason: 'unavailable' })
  })
})

/**
 * CONTENT-PREVIEW-SHAPES-001 — the grey box with nothing written in it.
 *
 * `absenceLabel` answers one question: «`posterSource` gave me nothing; what do I say?» For a video
 * whose platform never sent a cover frame it answered the empty string, because a video reading is
 * not `none` — and every surface then drew an empty grey square. That is the single outcome this
 * module exists to prevent, reachable from the most ordinary state a Snapchat or TikTok video ad
 * can be in: the file resolved, the thumbnail never arrived.
 */
describe('a video the platform sent no cover for', () => {
  const film = readPreview(preview({ kind: 'video', video_url: 'https://cdn/ad.mp4' }), false)

  it('is still a video, and has nothing to draw', () => {
    expect(film).toEqual({ kind: 'video', src: 'https://cdn/ad.mp4', poster: null, note: null })
    expect(posterSource(film)).toBeNull()
  })

  it('says there is a film here, rather than saying nothing at all', () => {
    expect(absenceLabel(film, false)).toContain('no cover frame')
    // And it says something is playable — the reader's next move is to open it, not to give up.
    expect(absenceLabel(film, false)).toMatch(/play/i)
    expect(absenceLabel(film, true)).toContain('غلاف')
  })

  /** A video WITH a cover is not an absence at all, and must stay silent. */
  it('stays silent when the cover arrived', () => {
    const covered = readPreview(preview({ kind: 'video', video_url: 'https://cdn/ad.mp4', thumbnail_url: 'https://cdn/cover.jpg' }), false)

    expect(posterSource(covered)).toBe('https://cdn/cover.jpg')
    expect(absenceLabel(covered, false)).toBe('')
  })
})

/**
 * CONTENT-PREVIEW-SHAPES-001 — a story is not a crop of itself.
 *
 * `object-cover` on a 9:16 asset in a landscape card keeps the middle third and throws away the top
 * and the bottom, which on a story is the logo and the call to action. The card then shows a picture
 * the ad never was — and two creatives compared side by side are two crops this product invented,
 * which is a worse failure than showing nothing, because it looks like evidence.
 */
describe('the shape of the asset', () => {
  it('reads a portrait asset from its dimensions', () => {
    expect(previewShape(1080, 1920)).toBe('portrait')
    expect(previewShape(1920, 1080)).toBe('landscape')
  })

  /**
   * «Roughly square» is not a story.
   *
   * A 1080×1200 feed image does not want a story's frame, so the threshold sits above 1.2 rather
   * than at «taller than it is wide».
   */
  it('keeps a nearly-square asset out of the portrait frame', () => {
    expect(previewShape(1080, 1200)).toBe('landscape')
    expect(previewShape(1080, 1080)).toBe('landscape')
  })

  /** The provider's own string, where the numbers never arrived. */
  it('reads the ratio the platform reported', () => {
    expect(previewShape(null, null, '9:16')).toBe('portrait')
    expect(previewShape(null, null, '1080x1920')).toBe('portrait')
    expect(previewShape(null, null, '16:9')).toBe('landscape')
  })

  /**
   * Unknown is treated as landscape, deliberately.
   *
   * Cropping a landscape asset slightly is a cosmetic loss; letter-boxing every asset whose
   * dimensions a platform did not report would make the common case worse to protect the rare one.
   */
  it('does not guess when the platform reported nothing', () => {
    expect(previewShape(null, null, null)).toBe('unknown')
    expect(previewShape(0, 0, 'square-ish')).toBe('unknown')
  })

  /**
   * CONTENT-PREVIEW-SHAPES-001 — a catalog ad has no creative, and that is not an absence.
   *
   * Meta's dynamic product ads and their equivalents are composed per product at delivery. Nothing
   * is missing, so «the platform sent no file» is the wrong sentence: it reads as a fault and sends
   * an operator looking for a sync problem that does not exist. Before this it arrived as `other`
   * and fell through to exactly that.
   */
  it('says a catalog ad has no single file, rather than reporting an absence', () => {
    const reading = readPreview(
      { state: 'available', kind: 'catalog', image_url: null, video_url: null, thumbnail_url: null } as never,
      true,
    )

    expect(reading.kind).toBe('catalog')
    expect(posterSource(reading)).toBeNull()
    expect(absenceLabel(reading, true)).toContain('كتالوج')
    expect(absenceLabel(reading, true), 'a catalog ad is not a missing file').not.toContain('لم ترسل المنصة ملفًا')
  })

  /**
   * A collection's hero is real and is drawn; the tiles beneath it are not one file.
   *
   * Showing the hero alone and calling it the ad shows a reader one sixth of it, so the SHAPE
   * travels with the reading and the surface can say which it is.
   */
  it('draws a collection hero and keeps the shape with it', () => {
    const reading = readPreview(
      { state: 'available', kind: 'collection', image_url: 'https://cdn.example/hero.jpg', video_url: null, thumbnail_url: null } as never,
      true,
    )

    expect(reading.kind).toBe('collection')
    expect(posterSource(reading)).toBe('https://cdn.example/hero.jpg')
  })

  /** A collection with no hero is a smaller claim than «nothing arrived». */
  it('separates a collection with no hero from an ad with no media at all', () => {
    const noHero = readPreview(
      { state: 'available', kind: 'collection', image_url: null, video_url: null, thumbnail_url: null } as never,
      true,
    )
    const noMedia = readPreview(
      { state: 'available', kind: 'image', image_url: null, video_url: null, thumbnail_url: null } as never,
      true,
    )

    expect(absenceLabel(noHero, true)).toContain('مجموعة')
    expect(absenceLabel(noMedia, true)).toContain('لم تُرسل المنصة ملفًا')
    expect(absenceLabel(noHero, true)).not.toBe(absenceLabel(noMedia, true))
  })
})

/**
 * CONTENT-PREVIEW-SHAPES-001 — the shape of the FRAME, which is not the shape of the media.
 *
 * A story or a reel is 9:16, and every card drew `aspect-video`: the ad was letterboxed into a third
 * of its own frame, and a reader comparing two ads was comparing two crops. One mapping, because two
 * surfaces choosing their own would put the same ad in two different boxes.
 */
describe('the frame an ad is drawn in', () => {
  it('gives a vertical ad a tall frame', () => {
    expect(aspectClass('vertical')).toBe('aspect-[9/16]')
  })

  it('gives a horizontal ad a wide one, and a square ad a square', () => {
    expect(aspectClass('horizontal')).toBe('aspect-video')
    expect(aspectClass('square')).toBe('aspect-square')
  })

  /**
   * «The platform did not say» is not «square».
   *
   * Null returns null rather than a class, so the surface keeps whatever frame it already had — a
   * default here would be this module making a claim about an ad's composition from no evidence, on
   * every provider that does not report dimensions.
   */
  it('states no shape when the platform stated none', () => {
    expect(aspectClass(null)).toBeNull()
  })

  it('reads the shape off the preview, and null off nothing at all', () => {
    expect(frameAspect(preview({ aspect: 'vertical' }))).toBe('vertical')
    expect(frameAspect(preview())).toBeNull()
    expect(frameAspect(null)).toBeNull()
  })
})
