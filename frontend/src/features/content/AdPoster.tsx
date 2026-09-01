import { useUi } from '@/stores/ui'
import { absenceLabel, posterSource, previewShape, readPreview } from './adPreview'
import type { CreativePreview } from './api'

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

  if (!src) {
    return (
      <span
        data-testid={testid ? `${testid}-absent` : undefined}
        /* Which of the reasons this is, so a surface can tell «there is a film here» from «there is nothing». */
        data-absence={reading.kind === 'none' ? reading.reason : 'video-no-cover'}
        className={`flex items-center justify-center rounded-lg bg-surface-secondary p-2 text-center text-[11px] leading-tight text-text-muted ${className}`}
      >
        {absenceLabel(reading, ar)}
      </span>
    )
  }

  /*
   * A portrait asset is contained, never covered — the whole frame is the point of a story. The
   * backdrop is what stops the letter-boxing reading as a broken image: a picture floating on the
   * page looks like a layout fault, and one sitting on a surface looks deliberate.
   */
  const portrait = previewShape(width, height, aspectRatio) === 'portrait'

  return (
    <img
      src={src}
      alt={name}
      data-shape={portrait ? 'portrait' : 'landscape'}
      data-testid={testid}
      /*
       * A `data:` URI must load eagerly. A lazy one never enters the viewport observer, never
       * decodes, and leaves a blank frame with no error anywhere — which is how the demo library
       * came to render ten empty cards.
       */
      loading={src.startsWith('data:') ? 'eager' : 'lazy'}
      className={`rounded-lg ${portrait ? 'bg-surface-secondary object-contain' : 'object-cover'} ${className}`}
    />
  )
}
