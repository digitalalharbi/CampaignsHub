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
 * AD-MEDIA-RECOVERY-001 — and «absence» is not one state. A row DERIVED from ad-level performance
 * was never fetched as an ad at all, so «this platform does not expose the asset» is a false
 * accusation against the provider: nobody asked it. `never_fetched` is that case, said as itself.
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
  | { kind: 'none'; reason: 'withheld' | 'expired' | 'unavailable' | 'never_fetched' | 'shape_not_fetched' | 'no_media'; note: string | null }
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
  /**
   * A collection carries the platform's own note, because a collection with no hero has two readings.
   *
   * A STATIC collection whose top snap never arrived is missing a file. A DYNAMIC one is missing
   * nothing: Snapchat picks its top snap from the product catalogue per product at delivery, so there
   * is no file to have sent. The server tells them apart — it keeps the platform's `render_type` — and
   * says which in the note, so the surfaces state a provider FACT instead of accusing it of a gap.
   */
  | { kind: 'collection'; src: string | null; note: string | null }
  | { kind: 'catalog'; note: null }

/**
 * The frame's shape, for a surface that draws a box before it knows what goes in it.
 *
 * Read from the reading rather than from the preview, so every caller answers it the same way — and
 * `null` stays a real answer: «the platform did not say» is not «square», and a surface that
 * defaulted would be making a claim about the ad's composition from nothing.
 */
export function frameAspect(preview: CreativePreview | null | undefined): 'vertical' | 'square' | 'horizontal' | null {
  return preview?.aspect ?? null
}

/**
 * The Tailwind aspect class for a frame, or null to keep whatever the surface already had.
 *
 * One mapping, because two surfaces choosing their own would put the same story ad in two different
 * boxes — and the reader comparing them across pages would be comparing crops rather than ads.
 */
export type MediaAspect = 'vertical' | 'square' | 'horizontal'

/**
 * The asset's own shape, with a SQUARE band — CONTENT-PREVIEW-FIT-001.
 *
 * {@see previewShape} answers «portrait or not», which is the right question for choosing between a
 * tall frame and a wide one and the wrong one for deciding whether covering will crop: it calls a
 * 1:1 creative «landscape», so a square ad drawn into a 16:9 box loses a third of its height and
 * nothing in the old rule could tell. The band is ±12.5% either side of 1:1 — wide enough to admit
 * the 4:5 and 1.91:1 the platforms actually serve as their own shapes rather than as near-squares.
 *
 * `null` is a real answer and the important one: a shape nobody stated is a shape nothing may be
 * cropped to.
 */
export function assetAspect(
  width?: number | null,
  height?: number | null,
  aspectRatio?: string | null,
): MediaAspect | null {
  const ratio = (() => {
    if (typeof width === 'number' && typeof height === 'number' && width > 0 && height > 0) {
      return width / height
    }

    const parsed = (aspectRatio ?? '').match(/(\d+(?:\.\d+)?)\s*[:x\u00d7]\s*(\d+(?:\.\d+)?)/i)

    if (parsed) {
      const w = Number(parsed[1])
      const h = Number(parsed[2])

      if (w > 0 && h > 0) return w / h
    }

    return null
  })()

  if (ratio === null) return null
  if (ratio > 1.125) return 'horizontal'
  if (ratio < 0.889) return 'vertical'

  return 'square'
}

/**
 * CONTENT-PREVIEW-FIT-001 — contain by default; cover only a decorative tile whose shape is known.
 *
 * ## The crop
 *
 * «Opening a creative can crop the image/video… The media is being forced into a stage that does
 * not respect the source aspect ratio, so part of the actual advertisement is lost.» The owner's
 * instruction is explicit: «Default media fit: object-fit: contain. NOT cover.»
 *
 * ## Why the declared aspect cannot license a crop
 *
 * The first attempt at this covered wherever the stated shape matched the stage, reasoning that a
 * frame built from `preview.aspect` IS the asset's own box. Measured on the seeded library, six
 * cards were still cropped: assets whose intrinsic ratio is 1.000 drawn into 0.563 and 1.775
 * frames. The stated aspect describes the AD, not the file — a platform returns a landscape cover
 * for a 9:16 video all the time, which this codebase had already written down elsewhere — so
 * matching it against the stage proves nothing about the picture that actually arrives.
 *
 * Only the browser knows the file's ratio, and only after it decodes. So the rule does not try to
 * predict it: media is contained, the stage keeps the declared shape, and an asset that genuinely
 * matches fills it with no letterboxing at all. One that does not is shown WHOLE on the neutral
 * ground the card already draws — «bounded stage + contained media + neutral intentional
 * background», which is the owner's own description of the grid.
 *
 * ## The one exception
 *
 * A `thumb` is a 48–64px navigation tile — a carousel strip, a group list, a ranking row — where the
 * reader is picking a row rather than judging an ad, and where letterboxing a square into a square
 * costs legibility for nothing. It still contains unless the shape is known and matches.
 */
export function mediaFit(
  asset: MediaAspect | null | undefined,
  stage: MediaAspect | null | undefined,
  surface: 'stage' | 'viewer' | 'thumb' = 'stage',
): 'contain' | 'cover' {
  if (surface !== 'thumb') return 'contain'

  return asset !== null && asset !== undefined && asset === (stage ?? 'horizontal') ? 'cover' : 'contain'
}

/** The Tailwind utility for {@see mediaFit}, so no surface spells the class itself. */
export function mediaFitClass(
  asset: MediaAspect | null | undefined,
  stage: MediaAspect | null | undefined,
  surface: 'stage' | 'viewer' | 'thumb' = 'stage',
): string {
  return mediaFit(asset, stage, surface) === 'contain' ? 'object-contain' : 'object-cover'
}

export function aspectClass(aspect: 'vertical' | 'square' | 'horizontal' | null): string | null {
  return aspect === 'vertical' ? 'aspect-[9/16]' : aspect === 'horizontal' ? 'aspect-video' : aspect === 'square' ? 'aspect-square' : null
}

export function readPreview(preview: CreativePreview | null | undefined, ar: boolean): PreviewReading {
  const note = (p: CreativePreview) => (ar ? p.note_ar : p.note_en) ?? null

  if (!preview) {
    return { kind: 'none', reason: 'unavailable', note: null }
  }

  if (preview.state !== 'available') {
    return { kind: 'none', reason: preview.state, note: note(preview) }
  }

  /*
   * A video with no playable source falls to its POSTER, and the card badges the film separately.
   *
   * This paragraph used to say the opposite — «rendering it as an IMAGE would be a lie about the
   * ad» — and the code below has always returned an image reading in that case. A comment that
   * describes an intention the code does not implement is worse than none, because it is what a
   * reviewer checks instead of the behaviour, so it is corrected rather than the code.
   *
   * The behaviour is right. `video_url` is frequently absent — resolving a playable source is a
   * per-asset call several connectors decline — and the poster is a real frame the platform chose,
   * so drawing it shows the reader the ad. A `video` reading with a null source would hand every
   * player an element with nothing to play, which is the blank box this module exists to prevent.
   *
   * What the reading must not do is LOSE the distinction, and it does not: the film is named where
   * the reader can act on it — the library card badges `preview.kind === 'video'` from the envelope
   * rather than from this reading, so «a still or a film» is still answered.
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
    const still = preview.image_url ?? preview.thumbnail_url ?? null

    /*
     * A COLLECTION'S HERO MAY BE A FILM, and this module used to assume it never was.
     *
     * The reading below resolves a collection to `image_url ?? thumbnail_url` and never looks at
     * `video_url`, so a collection whose top snap is a video — which is most of them on Snapchat —
     * resolved to `src: null` and drew an empty frame under «تشكيلة بلا غلاف / Collection, no hero».
     * The ad has a hero. Nobody was drawing it.
     *
     * Measured on the live estate rather than argued: of 457 static collections, **162 drew
     * nothing**, every one of them carrying a `video_url` and no still. They never reached the
     * defect census either, because that asks «is any url set» — which is the right question for a
     * sync fault and the wrong one for a reader, and is why this sat unseen behind a count that
     * said the library was healthy.
     *
     * A film reading rather than a still, for the same reason `video` gets one: the player draws it,
     * and a poster is only the frame shown before it does. The SHAPE is not lost — `CreativesPage`
     * and `CreativeCarousel` both badge from `preview.kind` on the envelope, not from this reading,
     * which is the separation this module's own video branch already relies on.
     */
    if (still === null && preview.video_url) {
      // `note: null` like every other film: a hero that DRAWS has no absence to explain, and the
      // video reading's type says so.
      return { kind: 'video', src: preview.video_url, poster: null, note: null }
    }

    return { kind: 'collection', src: still, note: note(preview) }
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
    /*
     * The platform's own sentence wins where the server composed one — it is the more specific truth.
     *
     * A dynamic collection has no top snap by design, and «the platform sent no hero frame» reads as
     * a fault and sends an operator looking for a sync problem that does not exist. The generic
     * sentence stays for the collection that really is missing one.
     */
    return reading.note ?? (ar
      ? 'إعلان مجموعة — لم ترسل المنصة صورة الغلاف. البلاطات تحتها ليست ملفًا واحدًا.'
      : 'A collection ad — the platform sent no hero frame. The tiles beneath it are not one file.')
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
    unavailable: ['جُلب هذا الإعلان من المنصة، ولم تُتِح المنصة ملفه.', 'This ad was fetched from the platform, and the platform exposed no file for it.'],
    /*
     * Not the platform's doing — AD-MEDIA-RECOVERY-001.
     *
     * The row exists because spend and impressions were attributed to a creative, not because the
     * creative was fetched. Saying «the platform does not expose it» sends an operator to debug an
     * integration that is working.
     */
    never_fetched: [
      'هذا الصف مُستنتج من أداء الإعلان، ولم يُجلب الإعلان نفسه من المنصة — فلا يوجد ملف لعرضه.',
      'This row was derived from ad-level performance; the ad itself was never fetched, so there is no file to show.',
    ],
    /*
     * Also not the platform's doing, and for a different reason than `never_fetched`.
     *
     * The ad WAS fetched. Its shape simply carries more than one asset — a collection is a hero over
     * a grid of product tiles — and the tiles are behind a call this product does not make yet.
     * Snapchat exposes them; `MetaConnector` is the only place in the tree that has ever written
     * `cards`. Saying «the platform exposed no file» would send an operator to debug a working
     * integration, which is the exact defect `never_fetched` exists to prevent, one shape over.
     *
     * No «coming soon»: a status line that promises a date ages into a lie, and this one is true
     * whatever happens next.
     */
    /*
     * CONTENT-COLLECTION-TILES-001 — the claim this sentence used to make is no longer true.
     *
     * It said «the platform exposes the tiles; this product does not fetch them yet». That was honest
     * when written and is not now: the tiles are read through the ad's interaction zone. So this
     * reason no longer means «we never ask» — it means we asked and this ad's zone did not answer,
     * which is what tells an operator to re-sync rather than to wait for a feature.
     */
    shape_not_fetched: [
      'إعلان تشكيلة: لم تصل بطاقاته في آخر مزامنة — تُقرأ من منطقة التفاعل، وهذه لم تُقرأ.',
      'A collection ad — its tiles did not arrive in the last sync. They are read through the ad’s interaction zone, and this one did not answer.',
    ],
    no_media: ['لم تُرسل المنصة ملفًا لهذا الإعلان.', 'The platform sent no file for this ad.'],
  }

  const pair = words[reading.reason] ?? words.unavailable

  return ar ? pair[0] : pair[1]
}

/**
 * CONTENT-MEDIA-ABSENCE-COMPACT-001 — the same absence, in three or four words.
 *
 * ## Why a second label rather than a shorter one
 *
 * `absenceLabel` is right, and it is a SENTENCE — «A collection ad — its tiles did not arrive in the
 * last sync.» In a 128-pixel poster box that sentence is eight lines of
 * 11px grey text where a picture belongs, repeated down a grid of twenty-four cards. The owner's
 * word for it was a «large explanatory paragraph occupying the creative image area», and they are
 * right that it reads worse than the absence it describes.
 *
 * Shortening the sentence itself was the wrong fix: it is the sentence an operator needs in order to
 * know whether to re-sync, to wait, or to do nothing, and every clause in it was added because
 * somebody acted on a vaguer one. So the box gets the label and keeps the sentence in its `title`,
 * where the reader who wants it can get it without the grid paying for it.
 *
 * The two are built from the same reading, so they cannot describe different absences.
 */
export function absenceShort(reading: PreviewReading, ar: boolean): string {
  /*
   * CONTENT-ABSENCE-NOT-A-FAULT-001 — «فيديو بلا غلاف» described the AD as deficient.
   *
   * The film is there and it plays. What is absent is a still, and no platform owes one: Snapchat
   * returns the file and no separate poster for every video creative on the live estate. «Without a
   * cover» reads as a broken product on a card a client sees, which is the opposite of true — it is
   * the one state where the media is completely intact.
   *
   * So the compact form names what the card IS showing — a video preview — and the surface draws
   * the play affordance beside it. The long form {@see absenceLabel} already said «افتح الإعلان
   * لتشغيله», and these two now agree.
   */
  if (reading.kind === 'video' && reading.poster === null) {
    return ar ? 'معاينة الفيديو' : 'Video preview'
  }

  if (reading.kind === 'catalog') {
    return ar ? 'كتالوج' : 'Catalog ad'
  }

  if (reading.kind === 'collection' && reading.src === null) {
    /* Composed, not absent — the long sentence's short form, and the same distinction. */
    return reading.note !== null
      ? (ar ? 'تُركَّب لكل منتج' : 'Composed per product')
      /* And where it is genuinely absent, it is the platform's hero that is missing, not ours. */
      : (ar ? 'لم ترسل المنصة غلافًا' : 'Platform sent no hero')
  }

  if (reading.kind !== 'none') {
    return ''
  }

  /*
   * Each of these names WHO the absence belongs to — CONTENT-ABSENCE-NOT-A-FAULT-001.
   *
   * «لا يوجد ملف» is a statement about this product's contents and reads as a gap on this side of
   * the wire. The truth in every one of these cases is about the platform: it did not send a file,
   * or it sent a link that has since expired, or it was never asked. Said that way the reader
   * learns that media availability varies by provider; said the old way they learn that
   * CampaignsHub lost something.
   */
  const words: Record<string, [string, string]> = {
    withheld: ['الرابط محمي', 'Link protected'],
    expired: ['انتهت صلاحية الرابط', 'Link expired'],
    unavailable: ['لم ترسل المنصة ملفًا', 'Platform sent no file'],
    never_fetched: ['لم يُطلب من المنصة', 'Never requested'],
    shape_not_fetched: ['البطاقات لم تُطلب', 'Tiles never requested'],
    no_media: ['لم ترسل المنصة ملفًا', 'Platform sent no file'],
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

/**
 * A missing picture as a CLIENT reads it — CLIENT-DIAGNOSTIC-SEPARATION-001.
 *
 * `absenceLabel` / `absenceShort` keep the four operator states apart, because each is a different
 * next move for whoever runs the sync. A client has no such move, and «لم يُجلب» on their report is
 * our fetching described to them. So the `none` reasons fold to what is true for the reader: this
 * link does not show it (withheld), the preview is unavailable for now (expired), or there is none.
 * The server's note is never used here — it is written for the operator. Shape readings (a video
 * without a cover, a catalog ad, a collection without a hero) describe the ad itself and keep their
 * sentences.
 */
export function clientAbsence(reading: PreviewReading, ar: boolean): { short: string; sentence: string } {
  /*
   * A FILM WITH NO POSTER, on a surface that cannot play it.
   *
   * `absenceLabel` answers '' for a film, and rightly: where the player draws there is nothing to
   * explain. The CLIENT surfaces are the other case — `PrintDocument` reads this sentence onto a page
   * that cannot play anything, and `ReportAdsSection` shows it where no cover frame exists. Without
   * an arm of its own, a collection whose hero is a video would arrive as an empty frame with no
   * sentence beside it, on the one surface a client keeps.
   *
   * The wording holds on both: «no cover frame for it» is true of a page and of a screen. An earlier
   * draft said «to print» and was false on the second — caught by `ReportAdsSection`'s own case.
   *
   * It became reachable when a collection carrying a film began resolving to a film reading instead
   * of to `src: null`. Before that the collection arm answered here; the picture is better now and
   * the sentence has to follow it.
   */
  if (reading.kind === 'video' && reading.poster === null) {
    return {
      /*
       * Its OWN label, not the card's — CONTENT-ABSENCE-NOT-A-FAULT-001 §15.
       *
       * This borrowed `absenceShort`, which was right while that said «no cover» and is wrong now
       * that it says «معاينة الفيديو». A printed page cannot preview anything, and `ReportAdsSection`
       * shows this where no cover frame exists: offering a preview on either is a promise the
       * surface cannot keep. The label names what the row IS and why there is no picture of it.
       */
      short: ar ? 'فيديو — لا غلاف من المنصة' : 'Video — no cover from the platform',
      sentence: ar
        ? 'محتوى هذا الإعلان فيديو، ولم ترسل المنصة صورة غلاف له.'
        : 'This ad’s content is a video, and the platform sent no cover frame for it.',
    }
  }

  if (reading.kind !== 'none') {
    return { short: absenceShort(reading, ar), sentence: absenceLabel(reading, ar) }
  }

  if (reading.reason === 'withheld') {
    return ar
      ? { short: 'غير معروضة في هذا الرابط', sentence: 'معاينة هذا المحتوى غير معروضة في هذا الرابط.' }
      : { short: 'Not shown on this link', sentence: 'This content’s preview is not shown on this link.' }
  }

  if (reading.reason === 'expired') {
    /*
     * The one reassurance a client needs — CONTENT-ABSENCE-NOT-A-FAULT-001.
     *
     * A missing picture in a document somebody is reading raises a question about everything else on
     * the page. The link is what expired at the source; the figures were measured and are unaffected,
     * and saying so is the difference between «this report is incomplete» and «this ad's picture is
     * no longer hosted».
     */
    return ar
      ? {
          short: 'المعاينة غير متاحة حاليًا',
          sentence: 'المعاينة غير متاحة حاليًا من المصدر، بينما تبقى بيانات الأداء متاحة.',
        }
      : {
          short: 'Preview unavailable for now',
          sentence: 'The preview is not available from the source right now; the performance figures are unaffected.',
        }
  }

  return ar
    ? { short: 'لا تتوفر معاينة', sentence: 'لا تتوفر معاينة لهذا المحتوى.' }
    : { short: 'No preview available', sentence: 'No preview is available for this content.' }
}
