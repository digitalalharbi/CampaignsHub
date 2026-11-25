import { lazy, type ComponentType } from 'react'

/**
 * CHUNK-RELOAD-001 — a file a deploy removed is not an error to show anybody.
 *
 * Chunk names carry a hash and every release removes the previous ones, so a tab left open across a deploy asks
 * for a file that no longer exists: «Failed to fetch dynamically imported module …/ReportFromView-CLIVq8Vj.js».
 * Route pages already reloaded once on that (`lazyPage`), but ten section-level `lazy()` calls — the analytics
 * board's sections among them — did not, so the Owner opened «التحليلات» after a release and met «حدث خطأ في عرض
 * هذه الصفحة» (2026-11-25). This module is now the ONE place that answers it; `chunkGuard.test.ts` fails the build
 * if a raw `lazy()` from React appears anywhere else.
 *
 * The page reloads once and picks up the new names. Once, because a chunk that is genuinely unreachable must reach
 * the error boundary instead of reloading for ever.
 */
const RELOAD_FLAG = 'campaignshub.chunk-reload-at'
const RELOAD_WINDOW_MS = 30_000

/**
 * REDIRECT-ONCE-002 — a chunk that failed because the page is being LEFT is not a stale chunk.
 *
 * Firefox and WebKit abort in-flight requests the moment a navigation starts, before the new document commits. A
 * chunk still downloading then rejects exactly like a removed file, and reloading at once would replace the
 * navigation the person had just asked for. So the reload waits: a document that is going away takes its timers with
 * it. The once-only flag is written when the reload happens, not when it is scheduled.
 */
const RELOAD_DELAY_MS = 1000

let reloadScheduled = false

/** Is this the browser's way of saying a code file could not be fetched? Wording differs by engine. */
export function isChunkLoadError(error: unknown): boolean {
  const message = error instanceof Error ? `${error.name} ${error.message}` : typeof error === 'string' ? error : ''
  return /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS|ChunkLoadError|Loading chunk [\w-]+ failed/i.test(message)
}

/** Has this tab already spent its one reload recently? Unreadable storage counts as yes, so nothing can loop. */
export function reloadRecentlySpent(): boolean {
  try {
    return Date.now() - Number(sessionStorage.getItem(RELOAD_FLAG) ?? 0) < RELOAD_WINDOW_MS
  } catch {
    return true
  }
}

/** Schedule the one reload. Returns false when it has already been spent, so the caller shows the error instead. */
export function scheduleChunkReload(): boolean {
  if (reloadRecentlySpent()) return false

  if (!reloadScheduled) {
    reloadScheduled = true
    window.setTimeout(() => {
      reloadScheduled = false

      try {
        sessionStorage.setItem(RELOAD_FLAG, String(Date.now()))
      } catch {
        return
      }

      window.location.reload()
    }, RELOAD_DELAY_MS)
  }

  return true
}

/** The rejection handler for a dynamic import: reload once, otherwise let the error boundary have it. */
export function reloadOnceFor<T>(error: unknown): Promise<T> {
  if (!scheduleChunkReload()) throw error

  // The page is about to reload; nothing should render in the meantime.
  return new Promise<T>(() => {})
}

/** `React.lazy` that survives a deploy. The only `lazy()` the codebase may call. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function lazyWithReload<C extends ComponentType<any>>(load: () => Promise<{ default: C }>) {
  return lazy(() => load().catch((error: unknown) => reloadOnceFor<{ default: C }>(error)))
}

/**
 * Vite's own signal for a failed preload, raised for EVERY dynamic import — including the ones that are not a React
 * component (a chart library, a report block). Installed once at start-up.
 */
export function installChunkReloadListener(): void {
  window.addEventListener('vite:preloadError', () => {
    scheduleChunkReload()
  })
}
