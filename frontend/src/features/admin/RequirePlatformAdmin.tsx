import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/**
 * The gate on the owner's console, in the browser (ADMIN-001).
 *
 * A courtesy, not the boundary: every `/api/v1/admin/*` endpoint is gated server-side by
 * `is_platform_admin`, and editing anything here changes nothing about what the API will answer.
 * What it adds is not showing a customer a console they cannot use.
 *
 * It reads the flag the session already carries, so there is no extra request and no loading state
 * to flash — and it redirects rather than explaining, because a tenant user landing here has simply
 * typed a URL that was never for them.
 */
export function RequirePlatformAdmin() {
  const user = useAuth((s) => s.user)
  const status = useAuth((s) => s.status)

  if (status === 'loading') return null
  if (!user?.is_platform_admin) return <Navigate to="/app/dashboard" replace />

  return <Outlet />
}
