import { create } from 'zustand'
import type { AuthUser } from '@/lib/api/types'

/**
 * Auth state for the SPA. Authentication itself lives in an HttpOnly session cookie (ADR 0001);
 * this store only mirrors the current user for the UI. `status` drives the initial session probe.
 */
/*
 * `unreachable` (WORKSPACE-SWITCH-001): the session could not be read — the server failed or did not answer. It is not
 * `guest`. Reading it as guest sent a signed-in person to the sign-in page during an outage, told them nothing, and
 * invited them to retype a password that was never the problem.
 */
type Status = 'loading' | 'authenticated' | 'guest' | 'unreachable'

interface AuthState {
  user: AuthUser | null
  status: Status
  setUser: (user: AuthUser | null) => void
  setStatus: (status: Status) => void
  hasPermission: (key: string) => boolean
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  status: 'loading',
  setUser: (user) => set({ user, status: user ? 'authenticated' : 'guest' }),
  setStatus: (status) => set({ status }),
  hasPermission: (key) => {
    const user = get().user
    if (!user) return false
    return user.is_platform_admin || user.permissions.includes(key)
  },
}))
