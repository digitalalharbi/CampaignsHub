import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LoginPage } from './LoginPage'
import { fetchMemberships } from './memberships'
import { useAuth } from '@/stores/auth'

vi.mock('./api', async (orig) => ({
  ...(await orig<typeof import('./api')>()),
  fetchOAuthProviders: vi.fn(async () => []),
  fetchSignInMethods: vi.fn(async () => ({ email_code: { available: false } })),
}))
vi.mock('./memberships', async (orig) => ({
  ...(await orig<typeof import('./memberships')>()),
  fetchMemberships: vi.fn(),
}))

const open = (entry: string) => render(
  <QueryClientProvider client={new QueryClient()}>
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/switch" element={<p>switcher</p>} />
      </Routes>
    </MemoryRouter>
  </QueryClientProvider>,
)

/** WORKSPACE-SWITCH-001 — sign-in ↔ switcher can never chase each other. */
describe('LoginPage after a session ended', () => {
  afterEach(() => {
    useAuth.setState({ status: 'loading', user: null })
    window.localStorage.clear()
    vi.clearAllMocks()
  })

  it('stays on the form and says why, even while the store still believes in the session', async () => {
    useAuth.setState({ status: 'authenticated', user: { id: 'u' } as never })
    open('/login?reason=session&redirect=%2Fswitch')

    expect(await screen.findByTestId('login-session-ended')).toBeInTheDocument()
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(screen.queryByText('switcher')).toBeNull()
    expect(fetchMemberships).not.toHaveBeenCalled()
  })

  it('still bounces a signed-in visitor who simply opened /login', async () => {
    useAuth.setState({ status: 'authenticated', user: { id: 'u' } as never })
    vi.mocked(fetchMemberships).mockResolvedValue({ destination: '/switch', requested_portal_held: null } as never)
    open('/login')

    expect(await screen.findByText('switcher')).toBeInTheDocument()
  })

  it('points an applicant at the application this browser remembers, and invents none', async () => {
    useAuth.setState({ status: 'guest', user: null })
    window.localStorage.setItem('chub:registration', 'r-1')
    open('/login')

    expect(await screen.findByTestId('login-registration-status')).toHaveAttribute('href', '/signup/status?request=r-1')
  })

  it('shows no such link when there is no remembered application', async () => {
    useAuth.setState({ status: 'guest', user: null })
    open('/login')

    await screen.findByTestId('login-column')
    expect(screen.queryByTestId('login-registration-status')).toBeNull()
  })
})
