/**
 * Small formatters shared by the creative surfaces.
 *
 * They live here rather than beside the components that use them so those files export components
 * and nothing else — a module that mixes the two breaks React Fast Refresh, and the helpers need to
 * be importable by tests without dragging a player or a viewer into the test file.
 *
 * Latin digits throughout, in both languages: these sit beside English metric names, currency codes
 * and ISO dates, and a mixed-script line is unreadable in a sentence and unalignable in a column.
 */

/**
 * Whether an image may be deferred — `lazy` for anything fetched, `eager` for anything inline.
 *
 * Found live, and it emptied the whole library: every card carried `loading="lazy"`, and a `data:`
 * URI marked lazy never loaded AT ALL — not off-screen, not scrolled into view, not while sitting in
 * the middle of the viewport. The same URI loaded instantly through `new Image()` and through an
 * identical element marked `eager`, so the markup and the payload were both fine; the attribute was
 * the whole fault. The page showed ten cards with ten blank frames and no error anywhere.
 *
 * Deferring an inline image was never worth anything in any case: the bytes are already in the
 * document, so there is no request to postpone. Remote thumbnails — the production path, where a
 * grid of forty really does cost forty requests — keep `lazy`, which is where the saving actually is.
 */
export function imageLoading(src: string | null | undefined): 'lazy' | 'eager' {
  return src?.startsWith('data:') ? 'eager' : 'lazy'
}

/** `m:ss`. Anything that is not a finite, non-negative number reads as `0:00` rather than `NaN:aN`. */
export function formatClock(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
  const whole = Math.floor(seconds)
  const mins = Math.floor(whole / 60)
  const secs = whole % 60
  return `${mins}:${secs < 10 ? '0' : ''}${secs}`
}

/**
 * Bytes as B/KB/MB — or `null`.
 *
 * `null` stays `null` and the caller omits the row. Rendering «0 KB» for a platform that does not
 * report a file size states a measurement nobody took.
 */
export function formatBytes(bytes: number | null): string | null {
  if (bytes === null || !Number.isFinite(bytes)) return null
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
