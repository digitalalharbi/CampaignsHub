import { useEffect, useState } from 'react'

/**
 * AD-PREVIEW-001 — the still, and what happens when the browser cannot draw it.
 *
 * ## The gap every server-side check misses
 *
 * `integrations:probe --media` fetches each first-page asset from the VPS and reports the status,
 * the content type and the DECODED dimensions. On the live estate it answers 11 usable, 0 unusable —
 * and the owner was still looking at blank cards. That is not a contradiction: a fetch that succeeds
 * from a datacentre says nothing about the fetch a browser makes, which carries a `Referer`, obeys
 * the page's policies, and happens minutes or hours later against a signed link with an expiry on
 * it. There is exactly one place that knows whether pixels arrived, and it is the `<img>`.
 *
 * And the `<img>` was silent. Neither the library card nor `AdPoster` had an `onError`, so a refused
 * asset, an expired signature, a 200 carrying an HTML error page — every one of them rendered an
 * element that painted nothing and kept its space. No sentence, no state, nothing in the console,
 * nothing any test could assert on. The video path had already learned this lesson twice
 * (`onUnavailable`, `brokenVideo`); the image path never did.
 *
 * ## What this does about it
 *
 * A failed load falls back to the caller's own absence — the sentence the card already has for an
 * asset that never arrived — so one fact gets one statement instead of an empty box.
 *
 * `data-media` is the instrument. It is `loaded` only after the browser reports a decode with real
 * dimensions, `failed` when the load errored, and `pending` until one of those happens; `data-natural`
 * carries the decoded size. Neither ever contains the URL — these links carry the signature that
 * makes them work, and this attribute is readable by anything on the page. It is what lets a browser
 * check prove «visibly rendered» rather than «the element exists», which is the whole difference
 * between the acceptance the owner asked for and the acceptance that kept passing while the screen
 * stayed blank.
 *
 * `referrerPolicy="no-referrer"` because a provider CDN is entitled to refuse a request whose
 * `Referer` is not its own product, and a refusal shaped exactly like this one is invisible from the
 * server — the probe sends no referrer at all. `PrintDocument` reached the same conclusion for the
 * printed deck; there is no reason the library should be the surface that keeps the header.
 */
export function PosterImage({
  src,
  alt,
  className,
  loading,
  shape,
  testid,
  fallback,
  onFailed,
}: {
  src: string
  alt: string
  className?: string
  loading?: 'lazy' | 'eager'
  shape?: string
  testid?: string
  /** What to draw instead when the browser could not. The caller's own stated absence. */
  fallback: React.ReactNode
  /** For a caller that has a SECOND thing to try — the library card falls back to the film. */
  onFailed?: () => void
}) {
  const [state, setState] = useState<'pending' | 'loaded' | 'failed'>('pending')
  const [natural, setNatural] = useState<string | null>(null)

  /*
   * A new asset gets a new verdict. Without this, a card recycled by the grid — the same element,
   * a different creative — keeps the previous asset's failure and hides a picture that would load.
   */
  useEffect(() => {
    setState('pending')
    setNatural(null)
  }, [src])

  if (state === 'failed') {
    return <>{fallback}</>
  }

  return (
    <img
      src={src}
      alt={alt}
      loading={loading}
      decoding="async"
      referrerPolicy="no-referrer"
      data-shape={shape}
      data-testid={testid}
      data-media={state}
      data-natural={natural ?? undefined}
      onLoad={(e) => {
        const img = e.currentTarget
        /*
         * `onLoad` fires for a decode of zero by zero — some browsers report it for an image whose
         * bytes were not an image at all. «Loaded» has to mean pixels, so the dimensions decide.
         */
        if (img.naturalWidth > 0 && img.naturalHeight > 0) {
          setNatural(`${img.naturalWidth}x${img.naturalHeight}`)
          setState('loaded')
        } else {
          setState('failed')
          onFailed?.()
        }
      }}
      onError={() => {
        setState('failed')
        onFailed?.()
      }}
      className={className}
    />
  )
}
