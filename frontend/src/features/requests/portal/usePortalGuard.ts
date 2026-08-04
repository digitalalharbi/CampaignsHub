import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { toApiError } from '@/lib/api/client'

/**
 * Shared portal auth guard. Every portal page loads its data with `retry: false`; a 401 means the
 * httpOnly session cookie is missing/expired, so we bounce to the portal login. Mirrors the inline pattern
 * the original portal pages used, centralised so every new section behaves identically.
 */
export function usePortalGuard(isError: boolean, error: unknown): void {
  const navigate = useNavigate()
  const location = useLocation()

  useEffect(() => {
    if (!isError || toApiError(error).status !== 401) return

    // Carry where they were heading, so signing in returns them to THAT client space rather than
    // dropping them at the merged view and making them find their way back.
    const intended = `${location.pathname}${location.search}`
    const to = intended.startsWith('/portal/')
      ? `/login?redirect=${encodeURIComponent(intended)}`
      : '/login'

    navigate(to, { replace: true })
  }, [isError, error, navigate, location.pathname, location.search])
}
