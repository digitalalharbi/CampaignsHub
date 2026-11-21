import { firstFrameSrc, type PreviewReading } from '@/features/content/adPreview'
import type { CreativePreview } from '@/features/content/api'

/**
 * CREATIVE-VIEWER-FORMATS-001 — the printed covers the PDF could not draw.
 *
 * The forwarded file printed a sentence wherever `posterSource` was null, and on the live estate that
 * is most Snapchat content: 125 videos and 117 collections whose top snap is a film arrive with a
 * `video_url` and no still. The film's own first frame is the cover in the interactive report, and
 * Chromium — the same engine that renders the PDF — decodes it, so the file prints it too.
 *
 * A film that cannot be decoded before the page is printed must not leave a black box in a document
 * a client keeps, so readiness (`settlePrintFilms`) gives each a bounded budget and replaces any that
 * did not paint with the same sentence the cell would have printed without it.
 */
export function PrintFilmFrame({ src, absence, absentClass, className }: { src: string; absence: string; absentClass: string; className?: string }) {
  return (
    <video
      data-print-film=""
      data-cover="video-first-frame"
      data-absence={absence}
      data-absent-class={absentClass}
      src={firstFrameSrc(src)}
      preload="auto"
      muted
      playsInline
      className={className}
    />
  )
}

/** The film a reading can print as its cover — a video the platform sent without a still. */
export function printFilm(reading: PreviewReading): string | null {
  return reading.kind === 'video' && reading.poster === null ? reading.src : null
}

/**
 * OPENAI-CHAT-CARD-TEXT-001 — a ChatGPT chat card's own words are its printed cover.
 *
 * The card's picture is not readable by our sync today, and its title and body ARE the creative, so
 * the cell prints them rather than «no file».
 */
export function printChatText(preview: CreativePreview | null | undefined): { headline: string | null; body: string | null } | null {
  const text = preview?.text
  if (!text || (!text.headline && !text.body)) return null

  return { headline: text.headline ?? null, body: text.body ?? null }
}

/**
 * Wait, bounded, for every printed film to paint a frame; replace the ones that did not.
 *
 * Resolves for every film — a print is never held open by a picture (REPORT-AD-PREVIEW-001).
 */
export async function settlePrintFilms(doc: Document = document, budgetMs = 6000): Promise<void> {
  const films = Array.from(doc.querySelectorAll<HTMLVideoElement>('video[data-print-film]'))

  await Promise.all(films.map((video) => new Promise<void>((resolve) => {
    const done = () => {
      if (video.error || video.readyState < 2) {
        const note = doc.createElement('span')
        note.className = video.dataset.absentClass ?? ''
        note.textContent = video.dataset.absence ?? ''
        video.replaceWith(note)
      }
      resolve()
    }
    if (video.error || video.readyState >= 2) {
      done()

      return
    }
    const timer = setTimeout(done, budgetMs)
    const settle = () => { clearTimeout(timer); done() }
    video.addEventListener('loadeddata', settle, { once: true })
    video.addEventListener('error', settle, { once: true })
  })))
}
