import { useEffect, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fetchCurrentUser } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'
import { applyDocument, useUi } from '@/stores/ui'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

export function Providers({ children }: { children: ReactNode }) {
  const { theme, locale } = useUi()
  const setUser = useAuth((s) => s.setUser)

  // Apply theme + direction on first mount and whenever they change.
  useEffect(() => {
    applyDocument(theme, locale)
  }, [theme, locale])

  // Restore the session from the cookie on load (ADR 0001).
  useEffect(() => {
    fetchCurrentUser().then(setUser)
  }, [setUser])

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
