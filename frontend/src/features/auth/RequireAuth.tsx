import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/**
 * Gate for authenticated routes. Waits for the initial session probe, then redirects guests to
 * `/login?redirect=<intended path>` so the login flow can return the user to where they were headed.
 */
export function RequireAuth() {
  const status = useAuth((s) => s.status)
  const location = useLocation()

  if (status === 'loading') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <span
          className="h-6 w-6 animate-spin rounded-full border-2 border-border border-t-brand-600"
          aria-label="Loading"
        />
      </div>
    )
  }

  if (status === 'guest') {
    const intended = `${location.pathname}${location.search}${location.hash}`
    // Don't bounce the root path through a redirect param — the dashboard is the default landing anyway.
    const to = intended && intended !== '/' ? `/login?redirect=${encodeURIComponent(intended)}` : '/login'
    return <Navigate to={to} replace />
  }

  return <Outlet />
}
