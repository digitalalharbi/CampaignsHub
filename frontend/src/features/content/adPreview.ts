import type { CreativePreview } from './api'

/**
 * AD-PREVIEW-001 — the one place that decides what an ad can show, and what it says when it cannot.
 *
 * ## Why this is a function and not four `if`s per surface
 *
 * Six surfaces render an ad's media, and each had worked out the rules again: the library, the
 * detail page, the pulse section, the groups page, the shared report and — worst of the six — the
 * campaign command centre, which asked the SERVER for a boolean called `has_preview` computed as
 * «thumbnail or preview_url is not null». That was wrong in both directions at once. Too generous,
 * because `preview_url` is the platform's shareable link and the presenter withholds it when it
 * carries a credential, so the card asked for a picture that would never arrive. Too mean, because
 * a creative with a real `asset_url` and no listing thumbnail — every Meta image ad — was declared
 * to have no preview at all.
 *
 * ## The fallback order, in one place
 *
 *   1. the provider's own media for the kind of ad it is — a video's file, an image's file;
 *   2. the stored canonical asset;
 *   3. the thumbnail, which for a video is the poster the platform itself chose;
 *   4. an honest, stated absence.
 *
 * Nothing below invents a fourth option. There is no placeholder image, no frame derived here, no
 * «similar» asset from the same campaign: a broken image and a fabricated one are the two failures
 * this exists to prevent, and the second is worse because nobody can see it.
 */
export type PreviewReading =
  | { kind: 'image'; src: string; note: null }
  | { kind: 'video'; src: string; poster: string | null; note: null }
  /**
   * Nothing to render, and WHY — four different sentences, never one grey box.
   *
   * `withheld` and `expired` are facts about the platform's link; `unavailable` is the platform not
   * exposing the asset at all; `no_media` is the state the presenter calls «available» while every
   * URL on it is null, which happens when a creative carries only a carousel breakdown.
   */
  | { kind: 'none'; reason: 'withheld' | 'expired' | 'unavailable' | 'no_media'; note: string | null }

export function readPreview(preview: CreativePreview | null | undefined, ar: boolean): PreviewReading {
  const note = (p: CreativePreview) => (ar ? p.note_ar : p.note_en) ?? null

  if (!preview) {
    return { kind: 'none', reason: 'unavailable', note: null }
  }

  if (preview.state !== 'available') {
    return { kind: 'none', reason: preview.state, note: note(preview) }
  }

  /*
   * A video is a video even when only its poster arrived.
   *
   * `video_url` is frequently absent — resolving a playable source is a per-asset call several
   * connectors decline — and the poster is a real frame the platform chose. Rendering it as an
   * IMAGE would be the honest fallback for the picture and a lie about the ad: a reader deciding
   * between a still and a film needs to know which one ran.
   */
  if (preview.kind === 'video' && preview.video_url) {
    return { kind: 'video', src: preview.video_url, poster: preview.thumbnail_url ?? preview.image_url, note: null }
  }

  const src = preview.image_url ?? preview.thumbnail_url

  return src ? { kind: 'image', src, note: null } : { kind: 'none', reason: 'no_media', note: note(preview) }
}

/** The still to draw for a reading — a video's poster, an image's file, or nothing. */
export function posterSource(reading: PreviewReading): string | null {
  return reading.kind === 'image' ? reading.src : reading.kind === 'video' ? reading.poster : null
}

/**
 * What to tell the reader when there is nothing to draw.
 *
 * The platform's own note wins where it sent one — it is more specific than anything written here,
 * and it is what the presenter composed for exactly this moment.
 */
export function absenceLabel(reading: PreviewReading, ar: boolean): string {
  if (reading.kind !== 'none') {
    return ''
  }
  if (reading.note) {
    return reading.note
  }

  const words: Record<string, [string, string]> = {
    withheld: ['رابط المعاينة من المنصة يحمل بيانات اعتماد، فلا يُعرض.', 'The platform’s preview link carries a credential, so it is not shown.'],
    expired: ['انتهت صلاحية رابط المنصة — يحتاج مزامنة جديدة.', 'The platform link has expired — it needs a fresh sync.'],
    unavailable: ['لا تتيح هذه المنصة ملف الإعلان.', 'This platform does not expose the ad’s file.'],
    no_media: ['لم تُرسل المنصة ملفًا لهذا الإعلان.', 'The platform sent no file for this ad.'],
  }

  const pair = words[reading.reason] ?? words.unavailable

  return ar ? pair[0] : pair[1]
}
