import { useEffect, type ReactNode } from 'react'
import { MutationCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { probeSession } from '@/features/auth/api'
import { fetchMemberships } from '@/features/auth/memberships'
import { listenForWorkspaceSwitch } from '@/features/auth/workspaceSwitch'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { applyDocument, useUi } from '@/stores/ui'
import { upgradeRefusalFrom, useUpgrade } from '@/stores/upgrade'
import { UpgradeRequiredDialog } from '@/components/UpgradeRequiredDialog'
import { OverlayBoundary } from '@/components/OverlayBoundary'
import { QUERY_GC_TIME_MS, QUERY_STALE_TIME_MS } from '@/lib/query/swr'

/**
 * How long an answer is reused by a second asker — PERF-REFETCH-001.
 *
 * React Query dedupes requests that are IN FLIGHT; it does not dedupe a request that has just
 * landed. With the default `staleTime: 0`, a component mounting a moment after its sibling resolved
 * the same key asks again — and the dashboard asks the same freshness, drivers and summary keys from
 * the header, the KPI strip and a tab. Measured on the analytics page: three `/freshness` requests
 * for one screen, identical in every byte. Thirty seconds is shorter than any figure on these screens
 * changes, long enough to cover a page's mount, and every mutation still invalidates its keys on
 * success, which refetches regardless of this.
 */
export { QUERY_STALE_TIME_MS, QUERY_GC_TIME_MS }

export const queryClient = new QueryClient({
  /*
   * Every commercial refusal, caught in one place — PAY-AUDIT-004.
   *
   * `EnsureWithinPlanLimit` and `EnsureEntitlement` both answer 403 with the metric, the usage, the
   * cap and an upgrade path. Nothing in this application read any of it: the backend named a route
   * to upgrade and every caller showed a red toast carrying the sentence alone.
   *
   * Wired at the MutationCache rather than at each call site because there is one answer for all of
   * them — your plan does not currently allow this, here is the way through — and there are
   * hundreds of mutations. A refusal that is not commercial is left entirely alone here and still
   * surfaces wherever it always did; see `upgradeRefusalFrom`, which is deliberately strict, because
   * telling somebody to upgrade when a colleague simply has not granted them a role would be worse
   * than saying nothing.
   */
  mutationCache: new MutationCache({
    onError: (error) => {
      const refusal = upgradeRefusalFrom(toApiError(error))
      if (refusal !== null) useUpgrade.getState().show(refusal)
    },
  }),
  defaultOptions: {
    queries: {
      /**
       * Retry once — but never a 4xx. A 403 from a client outside the caller's scope, or a 404 for
       * a record that does not exist, will not become a 200 on a second attempt; retrying only
       * delays the honest answer behind a spinner, which reads as the page hanging.
       */
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status !== undefined && status >= 400 && status < 500) return false
        return failureCount < 1
      },
      refetchOnWindowFocus: false,
      staleTime: QUERY_STALE_TIME_MS,
      /* PERF-INSTANT-DATA-001 — an answer outlives its reader, so a revisit paints on the first frame. */
      gcTime: QUERY_GC_TIME_MS,
    },
  },
})

/**
 * Addresses that are token-gated and read no session at all (PUBLIC-REPORT-NOAUTH).
 *
 * `/r/` is the short client link; `/reports/share/` is the older address every link already sent
 * still uses; `/reports/print/` is the headless-Chromium print route, which has no browser session
 * by construction. All three are matched, because an auth dependency on any one of them is an auth
 * dependency on a client's report.
 *
 * `/data-deletion` joins them for the same reason (LEGAL-DELETE-001): somebody asking to have their
 * data deleted has usually already lost access, or never had an account and appears only inside a
 * client's data. Probing `/auth/me` there can only ever be answered 401, on the one page that has to
 * work when everything else about the account has failed.
 *
 * Deliberately NOT «every route outside RequireAuth». A public page such as `/` legitimately wants
 * to know who you are, so it can offer «back to your dashboard» to somebody already signed in. This
 * list is the surfaces where the answer is not merely unnecessary but meaningless.
 */
const SESSIONLESS_PREFIXES = ['/r/', '/reports/share/', '/reports/print/', '/data-deletion']

/**
 * PERF-INSTANT-DATA-001 — the surfaces whose guards read the membership list.
 * `/portal/login` is a sign-in form, not a portal page, and has no session to list.
 */
const MEMBERSHIP_PREFIXES = ['/app', '/agency', '/influencers', '/portal']

export function readsMemberships(pathname: string): boolean {
  if (pathname === '/portal/login') return false

  return MEMBERSHIP_PREFIXES.some((p) => pathname === p || pathname.startsWith(`${p}/`))
}

export function isSessionlessSurface(pathname: string): boolean {
  return SESSIONLESS_PREFIXES.some((prefix) => pathname.startsWith(prefix))
}

export function Providers({ children }: { children: ReactNode }) {
  const { theme, locale } = useUi()
  const setUser = useAuth((s) => s.setUser)

  // Apply theme + direction on first mount and whenever they change.
  useEffect(() => {
    applyDocument(theme, locale)
  }, [theme, locale])

  /*
   * Restore the session from the cookie on load (ADR 0001).
   *
   * The result is applied ONLY if nothing has answered the question in the meantime. This probe says
   * "who were you when the page loaded?", and a page that signs someone in while it is still in
   * flight — email verification landing on a confirmation link, accepting an invitation — has a
   * newer answer. Applying the stale one signed the person straight back out: the store went
   * authenticated, then `setUser(null)` arrived from this line and the route guard bounced them to
   * /login. Whoever moved the store off `loading` knows more than this does.
   */
  useEffect(() => {
    /*
     * PUBLIC-REPORT-NOAUTH — the probe is not run on a surface that has no session by design.
     *
     * A client opening `/r/<token>` has no account, so `GET /auth/me` there is a request that can
     * only ever be answered 401 — and it was, twice per load, on the one page an agency sends to a
     * paying customer. Harmless while nothing renders a 401, and one release away from «انتهت
     * جلستك» appearing on a report belonging to somebody who was never signed in.
     *
     * The answer is set rather than merely skipped. Leaving the store on `loading` would be a
     * different bug — anything that waits for the question to be settled would wait forever — and
     * `null` is not a guess here: these addresses are token-gated and read no session at all.
     *
     * Matched on `window.location` rather than `useLocation` because Providers mounts ABOVE the
     * router, and this probe runs once at load: it is asking who you were when the page opened, so
     * the address the page opened at is exactly the right thing to read.
     */
    if (isSessionlessSurface(window.location.pathname)) {
      if (useAuth.getState().status === 'loading') setUser(null)

      return
    }

    /*
     * Asked beside the probe, not after it: both read the same session, so the second round trip was
     * pure waiting. Same key and function as every portal guard, so they find it already in flight.
     * A failure is swallowed here and left for the guard's own query to report.
     */
    if (readsMemberships(window.location.pathname)) {
      void queryClient.prefetchQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
    }

    void probeSession().then(({ user, unreachable }) => {
      if (useAuth.getState().status !== 'loading') return
      if (unreachable) useAuth.getState().setStatus('unreachable')
      else setUser(user)
    })
  }, [setUser])

  /*
   * WORKSPACE-SWITCH-001 — the active workspace belongs to the SESSION, so every tab of this browser has just moved.
   * A tab left behind would keep drawing the old workspace while its next request runs in the new one.
   */
  useEffect(() => listenForWorkspaceSwitch(queryClient), [])

  /*
   * The upgrade prompt is mounted ABOVE the router, so a commercial refusal is answered the same way
   * in every portal — an advertiser hitting a project cap and an agency hitting a seat cap are the
   * same conversation, and neither should depend on the page they happened to be on.
   */
  return (
    <QueryClientProvider client={queryClient}>
      {children}
      {/*
        Bounded, because this sits outside the router where nothing else would catch it — a throw
        here unmounts the entire root and the page goes blank. See `OverlayBoundary`.
      */}
      <OverlayBoundary>
        <UpgradeRequiredDialog />
      </OverlayBoundary>
    </QueryClientProvider>
  )
}
