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
    void fetchCurrentUser().then((user) => {
      if (useAuth.getState().status === 'loading') setUser(user)
    })
  }, [setUser])

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
