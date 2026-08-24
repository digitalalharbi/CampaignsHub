import { useRef, useState } from 'react'

/**
 * CONTENT-VIDEO-POSTER-001 — a still frame from a video that has no thumbnail.
 *
 * Production holds 549 video creatives and **zero** thumbnails: Snapchat returns the file and no
 * separate poster image. The library therefore has to make its own, and the first attempt did it
 * with a media fragment — `<video src="…#t=0.1" preload="metadata">` — on the reasoning that the
 * browser would seek to 0.1s and paint that frame.
 *
 * Browsers do not agree about that. With `preload="metadata"` several fetch the header, learn the
 * duration, and paint nothing at all until something asks them to play; the fragment is a request,
 * not an instruction. The result was a grid of black rectangles where the operator was told there
 * was a preview — which is worse than saying there is none, because it looks like a broken image.
 *
 * So the seek is performed rather than requested: once metadata has arrived the element is told to
 * move, and moving is what forces a frame to be decoded and painted. `duration / 2` guards the very
 * short clip whose total length is under the offset — seeking past the end paints nothing, and a
 * one-second bumper is exactly the kind of asset that would hit it.
 *
 * ## Failing honestly
 *
 * A video can fail for reasons this product does not control: an expired signed URL, a CDN that
 * refuses the range request, a codec the browser will not open. Any of those leaves an element that
 * renders as a black box forever. `onError` hands the decision back to the caller, so the card shows
 * the same «no preview» state it shows for a creative that genuinely has no asset — one sentence for
 * one fact, rather than a silent rectangle.
 *
 * `crossOrigin` is deliberately unset: these are signed CDN links, and asking for a credentialled
 * fetch is how a request that would have succeeded starts failing CORS.
 */
export function VideoPoster({
  src,
  className,
  onUnavailable,
}: {
  src: string
  className: string
  onUnavailable: () => void
}) {
  const ref = useRef<HTMLVideoElement>(null)
  const [seeked, setSeeked] = useState(false)

  return (
    <video
      ref={ref}
      src={src}
      preload="metadata"
      muted
      playsInline
      controls={false}
      className={className}
      data-testid="creative-video-poster"
      data-painted={seeked ? 'true' : 'false'}
      onLoadedMetadata={() => {
        const el = ref.current
        if (el === null) return

        /*
         * Guarded, because a seek on a duration the browser reports as 0, NaN or Infinity throws —
         * and a stream whose length is not yet known reports exactly those.
         */
        const duration = el.duration
        if (!Number.isFinite(duration) || duration <= 0) return

        try {
          el.currentTime = Math.min(0.1, duration / 2)
        } catch {
          // A browser that refuses the seek still shows whatever it painted; it is not an error
          // worth demoting the card for.
        }
      }}
      onSeeked={() => setSeeked(true)}
      onError={onUnavailable}
    />
  )
}
