import type { ReactElement, ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render } from '@testing-library/react'
import { useAuth } from '@/stores/auth'
import { useUi, type Locale } from '@/stores/ui'
import type { AuthUser } from '@/lib/api/types'

/** A signed-in user carrying exactly the given permissions (no platform-admin bypass). */
export function signInWith(permissions: string[]): void {
  const user: AuthUser = {
    id: 'u1',
    name: 'Tester',
    email: 't@test.dev',
    tenant_id: 'tenant-1',
    is_platform_admin: false,
    permissions,
    created_at: null,
  }
  useAuth.getState().setUser(user)
}

export function signOut(): void {
  useAuth.getState().setUser(null)
}

/** Render a component inside a fresh QueryClient + MemoryRouter. */
export function renderWithProviders(
  ui: ReactElement,
  { route = '/', locale = 'en', path }: { route?: string; locale?: Locale; path?: string } = {},
): ReturnType<typeof render> {
  // Deterministic language for assertions (app default is 'ar').
  useUi.setState({ locale })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[route]}>
          {/* When `path` is given, mount under a matching route so useParams() resolves. */}
          {path ? <Routes><Route path={path} element={children} /></Routes> : children}
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
  return render(ui, { wrapper: Wrapper })
}
