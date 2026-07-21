import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/** Gate for authenticated routes. Waits for the initial session probe, then redirects guests. */
export function RequireAuth() {
  const status = useAuth((s) => s.status)

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
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
