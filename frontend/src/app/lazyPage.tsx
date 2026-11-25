import { Suspense, type ComponentType } from 'react'
import { lazyWithReload } from '@/lib/chunkReload'

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
 * error boundary instead of reloading for ever. The mechanism lives in `@/lib/chunkReload` (CHUNK-RELOAD-001), shared
 * with every other lazily loaded part of the app.
 */
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

  const Page = lazyWithReload<ComponentType<Props>>(() =>
    load().then((module) => ({ default: module[name] as unknown as ComponentType<Props> })),
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
