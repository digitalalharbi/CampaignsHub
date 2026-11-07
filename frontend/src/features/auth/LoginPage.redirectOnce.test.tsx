import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    login: vi.fn(),
    fetchSignInOptions: vi.fn().mockResolvedValue({ providers: [], emailCode: false }),
  }
})
vi.mock('./postAuthDestination', () => ({
  // Slow enough that an effect started by the status change would still be in flight alongside the mutation's own.
  resolvePostAuthOutcome: vi.fn(() => new Promise((resolve) => setTimeout(() => resolve({ destination: '/app/dashboard', requestedPortalHeld: true }), 20))),
}))

import { login } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'

/**
 * REDIRECT-ONCE-001 — the destination is decided once per sign-in.
 *
 * Signing in sets the session, the session wakes the «already signed in» effect, and that effect used to ask the
 * server where to go and navigate too: two `/auth/memberships` requests and two `replace` calls to the same address,
 * milliseconds apart. Firefox and WebKit abort whatever navigation the browser began between them.
 */
describe('LoginPage — one redirect per sign-in', () => {
  it('asks for the destination once after a password sign-in, not once per path that notices the session', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'X', email: 'x@y.z' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value: 'x@y.z' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(resolvePostAuthOutcome).toHaveBeenCalled())
    await new Promise((resolve) => setTimeout(resolve, 150))
    expect(resolvePostAuthOutcome).toHaveBeenCalledTimes(1)
  })
})
