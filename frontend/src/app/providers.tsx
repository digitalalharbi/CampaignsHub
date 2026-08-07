import { useEffect, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fetchCurrentUser } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'
import { applyDocument, useUi } from '@/stores/ui'

const queryClient = new QueryClient({
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
 * Deliberately NOT «every route outside RequireAuth». A public page such as `/` legitimately wants
 * to know who you are, so it can offer «back to your dashboard» to somebody already signed in. This
 * list is the surfaces where the answer is not merely unnecessary but meaningless.
 */
const SESSIONLESS_PREFIXES = ['/r/', '/reports/share/', '/reports/print/']

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

    void fetchCurrentUser().then((user) => {
      if (useAuth.getState().status === 'loading') setUser(user)
    })
  }, [setUser])

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
