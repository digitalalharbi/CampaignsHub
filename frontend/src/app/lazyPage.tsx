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

function reloadOnceFor<T>(error: unknown): Promise<T> {
  let recent = false

  try {
    const last = Number(sessionStorage.getItem(RELOAD_FLAG) ?? 0)
    recent = Date.now() - last < RELOAD_WINDOW_MS

    if (!recent) {
      sessionStorage.setItem(RELOAD_FLAG, String(Date.now()))
    }
  } catch {
    // Storage refused: without the flag a reload could loop, so the error boundary takes it.
    throw error
  }

  if (recent) {
    throw error
  }

  window.location.reload()

  // The page is going away; nothing should render in the meantime.
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
