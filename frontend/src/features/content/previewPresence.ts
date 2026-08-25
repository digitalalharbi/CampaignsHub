/**
 * CONTENT-NO-PREVIEW-001 — whether a result set has any creative file to show at all.
 *
 * Every grid card reserves a 16:9 panel for the asset. Meta's ad API does not hand back the creative
 * file, so a Meta-only result renders rows of identical grey rectangles each repeating «لا تتوفر
 * معاينة», pushing the figures people came for below the fold.
 *
 * The question is about the RESULT, not the card: one creative cannot say whether the column is
 * worth reserving. A grid where some creatives do have assets keeps every panel, so the missing ones
 * stay visibly missing instead of being quietly levelled with the rest.
 */
export type PreviewFields = {
  thumbnail_url?: string | null
  image_url?: string | null
  video_url?: string | null
}

/** True when this creative carries something the card can actually display. */
export function hasDisplayablePreview(preview: PreviewFields | null | undefined): boolean {
  if (!preview) return false

  // A video with no separate thumbnail IS an asset — CONTENT-PREVIEW-VIDEO-001.
  return Boolean(preview.thumbnail_url ?? preview.image_url ?? preview.video_url)
}

/** True when at least one creative in the result carries one. */
export function anyDisplayablePreview(creatives: ReadonlyArray<{ preview?: PreviewFields | null }>): boolean {
  return creatives.some((c) => hasDisplayablePreview(c.preview))
}
