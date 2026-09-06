import { useUi } from '@/stores/ui'
import { absenceLabel, posterSource, previewShape, readPreview } from './adPreview'
import type { CreativePreview } from './api'
import { PosterImage } from './PosterImage'

/**
 * AD-PREVIEW-001 — one still, drawn the same way on every surface that shows an ad.
 *
 * Never a `<video>`: a grid of twenty ads that each preloaded a stream costs a phone tens of
 * megabytes to open a page, and a player belongs in the panel somebody opened on purpose. When there
 * is nothing to draw, the reason is drawn instead — four sentences, never one grey box.
 */
export function AdPoster({
  preview,
  name,
  className = 'h-32 w-full',
  testid,
  width,
  height,
  aspectRatio,
}: {
  preview: CreativePreview | null | undefined
  name: string
  className?: string
  testid?: string
  /**
   * CONTENT-PREVIEW-SHAPES-001 — the asset's own dimensions, where the platform reported them.
   *
   * A 9:16 story cropped into a landscape card keeps the middle third and throws away the top and
   * the bottom, which on a story is the logo and the call to action. The card then shows a picture
   * the ad never was, and two creatives compared side by side are two crops this product invented.
   */
  width?: number | null
  height?: number | null
  aspectRatio?: string | null
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const reading = readPreview(preview, ar)
  const src = posterSource(reading)

  /*
   * The stated absence, drawn either because there was never a still to draw or because the browser
   * could not draw the one there was. `data-absence` names which, so the two never blur together.
   */
  const absent = (reason: string) => (
    <span
      data-testid={testid ? `${testid}-absent` : undefined}
      /* Which of the reasons this is, so a surface can tell «there is a film here» from «there is nothing». */
      data-absence={reason}
      className={`flex items-center justify-center rounded-lg bg-surface-secondary p-2 text-center text-[11px] leading-tight text-text-muted ${className}`}
    >
      {reason === 'fetch_failed'
        ? ar
          ? 'تعذّر تحميل أصل هذا الإعلان من المنصة — قد يكون الرابط انتهت صلاحيته. يحتاج مزامنة جديدة.'
          : 'This ad’s asset could not be loaded from the platform — the link may have expired. It needs a fresh sync.'
        : absenceLabel(reading, ar)}
    </span>
  )

  if (!src) {
    /*
     * The attribute names the shape, and it used to name only one of them. Everything that was not
     * `none` was labelled `video-no-cover`, so a catalog ad and a collection with no hero both
     * reported themselves as a film without a poster — the sentence was right and the attribute a
     * surface or a test reads was wrong.
     */
    return absent(
      reading.kind === 'none'
        ? reading.reason
        : reading.kind === 'catalog'
          ? 'catalog'
          : reading.kind === 'collection'
            ? 'collection-no-hero'
            : 'video-no-cover',
    )
  }

  /*
   * A portrait asset is contained, never covered — the whole frame is the point of a story. The
   * backdrop is what stops the letter-boxing reading as a broken image: a picture floating on the
   * page looks like a layout fault, and one sitting on a surface looks deliberate.
   */
  const portrait = previewShape(width, height, aspectRatio) === 'portrait'

  return (
    <PosterImage
      src={src}
      alt={name}
      shape={portrait ? 'portrait' : 'landscape'}
      testid={testid}
      /*
       * A `data:` URI must load eagerly. A lazy one never enters the viewport observer, never
       * decodes, and leaves a blank frame with no error anywhere — which is how the demo library
       * came to render ten empty cards.
       */
      loading={src.startsWith('data:') ? 'eager' : 'lazy'}
      /*
       * A load that fails says so. It used to leave an `<img>` painting nothing over the space where
       * the ad should be — the owner's blank card, with no sentence and no signal anywhere.
       */
      fallback={absent('fetch_failed')}
      className={`rounded-lg ${portrait ? 'bg-surface-secondary object-contain' : 'object-cover'} ${className}`}
    />
  )
}
