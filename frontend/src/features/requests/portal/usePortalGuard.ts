import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { toApiError } from '@/lib/api/client'

/**
 * Shared portal auth guard. Every portal page loads its data with `retry: false`; a 401 means the
 * httpOnly session cookie is missing/expired, so we bounce to the portal login. Mirrors the inline pattern
 * the original portal pages used, centralised so every new section behaves identically.
 */
export function usePortalGuard(isError: boolean, error: unknown): void {
  const navigate = useNavigate()
  useEffect(() => {
    if (isError && toApiError(error).status === 401) navigate('/client/login', { replace: true })
  }, [isError, error, navigate])
}
