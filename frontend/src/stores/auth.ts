import { create } from 'zustand'
import { setAuthToken } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

/**
 * In-memory auth state. The token is intentionally NOT persisted to localStorage (XSS-safe);
 * Phase 2 will migrate to Sanctum cookie-session auth so a refresh keeps the session server-side.
 */
interface AuthState {
  user: AuthUser | null
  token: string | null
  setSession: (user: AuthUser, token: string) => void
  clear: () => void
  hasPermission: (key: string) => boolean
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  token: null,
  setSession: (user, token) => {
    setAuthToken(token)
    set({ user, token })
  },
  clear: () => {
    setAuthToken(null)
    set({ user: null, token: null })
  },
  hasPermission: (key) => {
    const user = get().user
    if (!user) return false
    return user.is_platform_admin || user.permissions.includes(key)
  },
}))
