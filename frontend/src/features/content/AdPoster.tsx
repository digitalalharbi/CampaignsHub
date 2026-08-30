import { useUi } from '@/stores/ui'
import { absenceLabel, posterSource, readPreview } from './adPreview'
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
}: {
  preview: CreativePreview | null | undefined
  name: string
  className?: string
  testid?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const reading = readPreview(preview, ar)
  const src = posterSource(reading)

  if (!src) {
    return (
      <span
        data-testid={testid ? `${testid}-absent` : undefined}
        className={`flex items-center justify-center rounded-lg bg-surface-secondary p-2 text-center text-[11px] leading-tight text-text-muted ${className}`}
      >
        {absenceLabel(reading, ar)}
      </span>
    )
  }

  return (
    <img
      src={src}
      alt={name}
      data-testid={testid}
      /*
       * A `data:` URI must load eagerly. A lazy one never enters the viewport observer, never
       * decodes, and leaves a blank frame with no error anywhere — which is how the demo library
       * came to render ten empty cards.
       */
      loading={src.startsWith('data:') ? 'eager' : 'lazy'}
      className={`rounded-lg object-cover ${className}`}
    />
  )
}
