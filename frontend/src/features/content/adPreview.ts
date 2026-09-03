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
  /**
   * CONTENT-PREVIEW-SHAPES-001 — two shapes whose media is not one asset.
   *
   * A COLLECTION is a hero over a grid of tiles. The hero renders — it is a real frame the reader
   * should see — but showing it alone and calling it the ad is showing one sixth of it, so the
   * reading carries the shape as well and the surface says which it is.
   *
   * A CATALOG ad has no fixed creative at all: the platform composes one per product at delivery.
   * That is NOT an absence — nothing is missing, and «no media» would send an operator looking for
   * a sync fault that does not exist.
   */
  | { kind: 'collection'; src: string | null; note: null }
  | { kind: 'catalog'; note: null }

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
  /*
   * The two shapes without one asset, decided BEFORE the image path.
   *
   * A collection's hero would otherwise read as an ordinary still and a catalog ad — which has no
   * asset by design — would fall through to `no_media`, which reads as a fault.
   */
  if (preview.kind === 'catalog') {
    return { kind: 'catalog', note: null }
  }

  if (preview.kind === 'collection') {
    return { kind: 'collection', src: preview.image_url ?? preview.thumbnail_url ?? null, note: null }
  }

  if (preview.kind === 'video' && preview.video_url) {
    return { kind: 'video', src: preview.video_url, poster: preview.thumbnail_url ?? preview.image_url, note: null }
  }

  const src = preview.image_url ?? preview.thumbnail_url

  return src ? { kind: 'image', src, note: null } : { kind: 'none', reason: 'no_media', note: note(preview) }
}

/** The still to draw for a reading — a video's poster, an image's file, or nothing. */
export function posterSource(reading: PreviewReading): string | null {
  // A collection's HERO is a real frame and is drawn; the tiles beneath it are the part a still
  // cannot carry, which is why the surface also says what shape it is looking at.
  return reading.kind === 'image'
    ? reading.src
    : reading.kind === 'video'
      ? reading.poster
      : reading.kind === 'collection'
        ? reading.src
        : null
}

/**
 * What to tell the reader when there is nothing to draw.
 *
 * The platform's own note wins where it sent one — it is more specific than anything written here,
 * and it is what the presenter composed for exactly this moment.
 */
export function absenceLabel(reading: PreviewReading, ar: boolean): string {
  /*
   * CONTENT-PREVIEW-SHAPES-001 — a video whose platform sent no still frame.
   *
   * This function answers one question — «`posterSource` gave me nothing; what do I say?» — and for
   * two months it had no answer for the commonest case of all. A video reading is not `none`, so it
   * returned the empty string, and every surface drew a grey box with nothing written in it: the
   * library, the pulse strip, the campaign centre, the analytics grid, the client's report and the
   * printed deck. That is the ONE outcome the whole module exists to prevent, and it was reachable
   * from the most ordinary state a Snapchat or TikTok video ad can be in — `video_url` resolved,
   * `thumbnail_url` never sent.
   *
   * The sentence says what is there rather than what is missing, because something IS there: the
   * film plays, it simply has no cover.
   */
  if (reading.kind === 'video' && reading.poster === null) {
    return ar
      ? 'فيديو — لم ترسل المنصة صورة غلاف له. افتح الإعلان لتشغيله.'
      : 'A video — the platform sent no cover frame for it. Open the ad to play it.'
  }

  /*
   * CONTENT-PREVIEW-SHAPES-001 — a catalog ad has no creative, and that is not an absence.
   *
   * The platform composes one per product at delivery, so there is nothing to have sent. «The
   * platform sent no file» would read as a fault and send an operator looking for a sync problem
   * that does not exist.
   */
  if (reading.kind === 'catalog') {
    return ar
      ? 'إعلان كتالوج — تُركّب المنصة صورته لكل منتج عند العرض، فلا يوجد ملف واحد له.'
      : 'A catalog ad — the platform composes its image per product at delivery, so it has no single file.'
  }

  /*
   * A collection with no hero: the shape is known and the frame is not.
   *
   * Different from `no_media`, which says nothing arrived at all — here the ad's SHAPE is a hero
   * over tiles and only the hero is missing, which is a smaller and more specific claim.
   */
  if (reading.kind === 'collection' && reading.src === null) {
    return ar
      ? 'إعلان مجموعة — لم ترسل المنصة صورة الغلاف. البلاطات تحتها ليست ملفًا واحدًا.'
      : 'A collection ad — the platform sent no hero frame. The tiles beneath it are not one file.'
  }

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

/**
 * CONTENT-PREVIEW-SHAPES-001 — the shape of the asset, so a story is not shown as a crop of itself.
 *
 * A 9:16 story rendered with `object-cover` into a landscape card keeps the middle third and throws
 * away the top and the bottom — which on a story is the logo and the call to action. The card then
 * shows a picture the ad never was, and a reader comparing two creatives is comparing two crops
 * this product invented.
 *
 * `unknown` when the platform reported no dimensions, and it is treated as landscape rather than
 * guessed at: cropping a landscape asset slightly is a cosmetic loss, and letter-boxing every
 * unknown asset would make the common case worse to protect the rare one.
 *
 * The 1.2 threshold keeps «roughly square» out of portrait — a 1080×1200 feed image is not a story
 * and does not want a story's frame.
 */
export type PreviewShape = 'portrait' | 'landscape' | 'unknown'

export function previewShape(width?: number | null, height?: number | null, aspectRatio?: string | null): PreviewShape {
  if (typeof width === 'number' && typeof height === 'number' && width > 0 && height > 0) {
    return height > width * 1.2 ? 'portrait' : 'landscape'
  }

  /*
   * The provider's own string, where the numbers are absent. Read as «w:h» or «w x h» — Snapchat
   * reports `9:16`, Meta reports `1080x1920`, and a ratio nobody can parse is not a shape.
   */
  const parsed = (aspectRatio ?? '').match(/(\d+(?:\.\d+)?)\s*[:x×]\s*(\d+(?:\.\d+)?)/i)

  if (parsed) {
    const w = Number(parsed[1])
    const h = Number(parsed[2])

    if (w > 0 && h > 0) return h > w * 1.2 ? 'portrait' : 'landscape'
  }

  return 'unknown'
}
