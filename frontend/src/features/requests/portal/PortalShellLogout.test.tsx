import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PortalShell } from './PortalShell'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import { isSigningOut, resetSignOutBarrier } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

const seen = vi.hoisted(() => ({ barrierUpWhenPortalSessionEnded: null as boolean | null }))
vi.mock('../clientPortalApi', async (orig) => ({
  ...(await orig<typeof import('../clientPortalApi')>()),
  portalLogout: vi.fn(async () => {
    const { isSigningOut } = await import('@/lib/api/client')
    seen.barrierUpWhenPortalSessionEnded = isSigningOut()
    return { ok: true }
  }),
}))
vi.mock('@/features/auth/api', async (orig) => ({ ...(await orig<typeof import('@/features/auth/api')>()), logout: vi.fn(() => Promise.resolve()) }))

import { portalLogout } from '../clientPortalApi'
import { logout } from '@/features/auth/api'

/**
 * OWNER DEFECT 2026-10-18 — signing out of the client portal landed on `/switch` showing
 * «تعذّر تحميل مساحات العمل».
 *
 * The portal's sign-out ended the SERVER session and navigated to `/login` through the router, but the
 * SPA still held the signed-in user in memory. `/login` bounces an authenticated visitor onward, a person
 * with more than one workspace lands on the switcher, and the switcher's request is answered 401 by the
 * session that no longer exists. The exit has to be the shared complete one: server, store, cache,
 * storage, and a hard navigation to the sign-in page.
 */
describe('signing out of the client portal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetSignOutBarrier()
    seen.barrierUpWhenPortalSessionEnded = null
    useAuth.setState({ user: { id: 1, name: 'Client', email: 'c@x.test', is_platform_admin: false, permissions: [] } as unknown as AuthUser, status: 'authenticated' })
  })

  it('clears the signed-in user and leaves by a hard navigation to /login, never through the router to /switch', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })

    renderWithProviders(<PortalShell title="t" nav showLogout><div>page</div></PortalShell>, { route: '/portal/razzah', locale: 'en' })
    fireEvent.click(await screen.findByRole('button', { name: /sign out|log out|خروج/i }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/login'))
    expect(portalLogout).toHaveBeenCalled()
    // The portal's own session is ended INSIDE the shared exit, after the barrier is up: a request still in
    // flight cannot then write the session back and bounce the visitor from /login into the portal.
    expect(seen.barrierUpWhenPortalSessionEnded).toBe(true)
    expect(isSigningOut()).toBe(true)
    expect(logout).toHaveBeenCalled()
    expect(useAuth.getState().user).toBeNull()
    expect(useAuth.getState().status).toBe('guest')
    vi.unstubAllGlobals()
  })
})
