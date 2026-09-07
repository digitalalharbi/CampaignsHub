import { useEffect, useRef, useState } from 'react'

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
 * That was not enough on its own. WebKit accepts the `currentTime` and defers the work until
 * something plays, so the seek never completes and the card stays empty — proved on CI's WebKit,
 * where this poster sat unpainted for twenty seconds on a file the same browser plays perfectly one
 * route away. A muted inline video is allowed to start without a gesture, so it is started and
 * stopped again on the first frame: the decode happens, nothing streams, and the cost is the one
 * frame the card is asking for.
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
  const [painted, setPainted] = useState(false)

  /*
   * A browser that will not paint has to SAY so, or the card is the blank rectangle.
   *
   * Two attempts at making WebKit decode a frame under `preload="metadata"` both failed on CI, and
   * the second failure is the instructive one: the element sat at `data-painted="false"` and stayed
   * mounted, so the card showed an empty box for ever. That is owner defect «no unexplained blank
   * rectangle» — the requirement is not that every browser manages a poster, it is that the reader
   * is never left looking at nothing with no account of it.
   *
   * So the attempt is given a budget, and when it expires the card is told the asset is unavailable
   * and falls back to the sentence it already has for a film with no cover — «فيديو — لم ترسل
   * المنصة صورة غلاف له», which is true, and which the client report has shown for this exact shape
   * all along. A browser that CAN decode still shows the frame; one that cannot shows a sentence
   * rather than a hole.
   *
   * Eight seconds: comfortably longer than the two it takes on the browsers that manage it, short
   * enough that nobody sits in front of an empty card wondering. Cleared on unmount and on success,
   * so a card scrolled past mid-decode does not report a failure it never had.
   */
  const gaveUp = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    setPainted(false)

    gaveUp.current = setTimeout(() => {
      if (ref.current === null || ref.current.readyState < 2) onUnavailable()
    }, 8000)

    /*
     * The frame can arrive BEFORE this effect runs — a cached file decodes between render and
     * commit, its events fire into a `settle` whose timeout does not exist yet, and the reset above
     * then puts the card back to «not painted» with nothing left to fire. Asking the element
     * directly closes that window: it is the same question `settle` asks, at the one moment no
     * event will answer it.
     */
    settle()

    return () => {
      if (gaveUp.current !== null) clearTimeout(gaveUp.current)
    }
    // `onUnavailable` is a fresh closure on every render; the budget belongs to the SOURCE.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [src])

  /*
   * «Painted» is a frame being AVAILABLE, not a particular event having fired.
   *
   * This reported the `seeked` event, and WebKit does not always send one: under
   * `preload="metadata"` with no user gesture it accepts `currentTime` and defers the work until
   * something plays, so the seek never completes and the card sits at `data-painted="false"` for
   * ever. Caught by the gate on CI's WebKit, where this poster stayed unpainted for twenty seconds
   * on a file the same browser plays perfectly on the detail page — which is the owner's blank card.
   *
   * `readyState >= HAVE_CURRENT_DATA` is the browser's own statement that there is a frame at the
   * current position, which is the actual claim being made, and it is true however the frame arrived.
   */
  const settle = () => {
    const el = ref.current
    if (el === null || el.readyState < 2) return

    if (gaveUp.current !== null) {
      clearTimeout(gaveUp.current)
      gaveUp.current = null
    }

    setPainted(true)
  }

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
      data-painted={painted ? 'true' : 'false'}
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

        /*
         * The nudge WebKit needs, and the cheapest one there is.
         *
         * A muted, inline video may start without a gesture, and starting is what makes a browser
         * that deferred the decode actually perform it. It is stopped again on the first frame, so
         * nothing plays and nothing streams: the cost is the first frame, which is the thing being
         * asked for. `play()` rejects when a browser declines — an unhandled rejection there would
         * be a console error on a page with twenty cards — so the refusal is swallowed, and the
         * card falls back to its own sentence through `onError` if the media is genuinely bad.
         */
        const started = el.play() as Promise<void> | undefined

        /*
         * `play()` returns a promise in every real browser and NOTHING under jsdom, where the method
         * is unimplemented — so calling `.then` on the result unguarded turns a passing unit test
         * into a TypeError thrown from an event handler.
         */
        if (started !== undefined && typeof started.then === 'function') {
            void started.then(() => el.pause()).catch(() => undefined)
        }
      }}
      /*
       * Every event that can mean «there is a frame now», because which one arrives is the part
       * browsers disagree about. `settle()` asks the element rather than trusting the event.
       */
      onSeeked={settle}
      onLoadedData={settle}
      onTimeUpdate={settle}
      onCanPlay={settle}
      onError={onUnavailable}
    />
  )
}
