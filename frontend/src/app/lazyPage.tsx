import { lazy, Suspense, type ComponentType } from 'react'

/**
 * PERF-RECOVERY-001 — a route's code arrives when the route is opened.
 *
 * The router imported all 125 pages up front, so a client opening a shared report downloaded the
 * admin console, the CRM and the influencer portal first: 4.76 MB of JavaScript before a single
 * number. The suspense boundary lives here rather than around the router so a page swap keeps its
 * shell on screen, and only the page's own area shows the placeholder.
 *
 * ## After a deploy
 *
 * Chunk names carry a hash and a release removes the old ones, so a tab left open across a deploy
 * asks for a file that no longer exists. That is not an error to show anybody: the page reloads once
 * and picks up the new names. Once, because a chunk that is genuinely unreachable must reach the
 * error boundary instead of reloading for ever.
 */
const RELOAD_FLAG = 'campaignshub.chunk-reload-at'
const RELOAD_WINDOW_MS = 30_000

/**
 * REDIRECT-ONCE-002 — a chunk that failed because the page is being LEFT is not a stale chunk.
 *
 * Firefox and WebKit abort a document's in-flight requests the moment a navigation starts, before the new document
 * commits and before `pagehide` fires. A route whose chunk was still downloading then rejects — indistinguishable,
 * at that instant, from a file a deploy removed — and the reload below ran: `location.reload()` of the OLD address,
 * which replaced the navigation the person had just asked for. Seen as `page.goto: NS_BINDING_ABORTED` on every
 * test that opened a page straight after signing in, and as «Navigation to X is interrupted by another navigation
 * to /app/dashboard»; for a person it is a link that sometimes lands back where they were.
 *
 * So the reload waits. A document that is going away takes its timers with it, which makes the delay itself the
 * answer: if a navigation commits inside the window, nothing runs; if nothing does, the chunk really is gone and the
 * page reloads. The once-only flag is written when the reload happens, not when it is scheduled, so a spurious
 * failure cannot spend the single reload a real one needs.
 */
const RELOAD_DELAY_MS = 1000

let reloadScheduled = false

function reloadOnceFor<T>(error: unknown): Promise<T> {
  let recent = false

  try {
    const last = Number(sessionStorage.getItem(RELOAD_FLAG) ?? 0)
    recent = Date.now() - last < RELOAD_WINDOW_MS
  } catch {
    // Storage refused: without the flag a reload could loop, so the error boundary takes it.
    throw error
  }

  if (recent) {
    throw error
  }

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

  // The page is going away, or is about to reload; nothing should render in the meantime.
  return new Promise<T>(() => {})
}

function PageFallback() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="جارٍ التحميل"
      data-testid="page-loading"
      className="flex min-h-[50vh] w-full flex-col gap-4 p-6"
    >
      <div className="h-8 w-48 max-w-full animate-pulse rounded-md bg-current opacity-10" />
      <div className="h-24 w-full animate-pulse rounded-md bg-current opacity-10" />
      <div className="h-64 w-full animate-pulse rounded-md bg-current opacity-10" />
    </div>
  )
}

export function lazyPage<M, K extends keyof M>(load: () => Promise<M>, name: K): M[K] {
  type Props = Record<string, unknown>
  type Loaded = { default: ComponentType<Props> }

  const Page = lazy(() =>
    load().then((module) => ({ default: module[name] as unknown as ComponentType<Props> }), reloadOnceFor<Loaded>),
  )

  function LazyPage(props: Props) {
    return (
      <Suspense fallback={<PageFallback />}>
        <Page {...props} />
      </Suspense>
    )
  }

  LazyPage.displayName = `lazyPage(${String(name)})`

  return LazyPage as unknown as M[K]
}
