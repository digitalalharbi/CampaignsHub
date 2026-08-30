import { describe, expect, it } from 'vitest'
import { absenceLabel, posterSource, readPreview } from './adPreview'
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
