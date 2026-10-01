import { describe, expect, it } from 'vitest'
import { absenceShort, readPreview } from './adPreview'
import type { CreativePreview } from './api'

/**
 * CONTENT-ABSENCE-NOT-A-FAULT-001 — «فيديو بلا غلاف» reads like a broken product.
 *
 * ## What the owner saw
 *
 * «Best/Worst cards can show "فيديو بلا غلاف", which reads like a broken product rather than an
 * intentional provider-data state… Do not imply CampaignsHub failed unless CampaignsHub actually
 * failed.»
 *
 * ## The distinction these hold
 *
 * A film whose platform sent no cover image is not a film that is missing. The media is there and
 * plays; what is absent is the STILL. Saying «بلا غلاف» — without a cover — describes the ad as
 * deficient, when the only thing deficient is a thumbnail nobody at the platform generated.
 *
 * And none of these states is CampaignsHub failing. Each one is a fact about what an advertising
 * platform exposes for an account, so each says who it is about.
 */
const preview = (over: Partial<CreativePreview> = {}): CreativePreview =>
  ({
    state: 'available', kind: 'video', image_url: null, thumbnail_url: null,
    video_url: 'https://cdn.example/film.mp4', aspect: 'vertical',
    note_ar: null, note_en: null,
    ...over,
  }) as CreativePreview

describe('what a card says when there is no still to draw', () => {
  /** Owner regression 4 — a video without a poster gets an intentional preview state. */
  it('does not call a playable film «without a cover»', () => {
    for (const ar of [true, false]) {
      const said = absenceShort(readPreview(preview(), ar), ar)

      expect(said, 'the compact label still describes the ad as deficient').not.toMatch(/بلا غلاف|no cover/i)
      expect(said, 'the compact label says nothing at all').toBeTruthy()
    }
  })

  /**
   * And it must not say the MEDIA is unavailable, because the media is right there and plays. Only
   * the static preview is missing.
   */
  it('does not claim the film itself is unavailable', () => {
    const said = absenceShort(readPreview(preview(), true), true)

    expect(said).not.toMatch(/غير متاح|لا يوجد ملف/)
  })

  /**
   * A file the platform never sent is the PLATFORM's absence, and the sentence says so rather than
   * reading as a fault on this side of the wire.
   */
  it('names the platform where the platform is the one that sent nothing', () => {
    const said = absenceShort(readPreview(preview({ state: 'unavailable' }), true), true)

    expect(said, 'an absent file still reads as a missing file rather than a provider fact').not.toBe('لا يوجد ملف')
  })
})
