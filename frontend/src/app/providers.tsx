import { useEffect, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { applyDocument, useUi } from '@/stores/ui'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

export function Providers({ children }: { children: ReactNode }) {
  const { theme, locale } = useUi()

  // Apply theme + direction on first mount.
  useEffect(() => {
    applyDocument(theme, locale)
  }, [theme, locale])

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
