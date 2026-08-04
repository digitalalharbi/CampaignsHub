import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, login: vi.fn(), signInMethod: vi.fn() }
})

vi.mock('@/features/requests/clientPortalApi', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, portalLoginStart: vi.fn(), portalLoginVerify: vi.fn() }
})

import { login, signInMethod } from './api'
import { portalLoginStart } from '@/features/requests/clientPortalApi'

const mockedMethod = vi.mocked(signInMethod)
const mockedStart = vi.mocked(portalLoginStart)

/** Type an identifier and press Continue — the only action step 1 offers. */
function identifyAs(value: string) {
  fireEvent.change(screen.getByLabelText(/Email address or mobile number/i), { target: { value } })
  fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
}

/** Get past step 1 as a password account, ready for the password field. */
async function reachPasswordStep(identifier = 'owner@demo-agency.local') {
  mockedMethod.mockResolvedValue({ method: 'password', channel: 'email' })
  identifyAs(identifier)
  await waitFor(() => expect(screen.getByTestId('login-password')).toBeInTheDocument())
}

/**
 * LOGIN-UNIFIED-001 — one door, and the server decides.
 *
 * The property under test is not the wording: it is that this page NEVER asks which portal the
 * visitor wants, and never claims one on their behalf. A page whose headings are rewritten should
 * keep passing; a page that puts a portal back in the visitor's hands is the regression.
 */
describe('LoginPage — one door (LOGIN-UNIFIED-001)', () => {
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('offers no portal choice at all', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    // The container the three tabs lived in, and each tab by name.
    expect(screen.queryByTestId('login-portals')).not.toBeInTheDocument()
    for (const key of ['default', 'agency', 'client', 'influencer', 'admin']) {
      expect(screen.queryByTestId(`login-portal-${key}`)).not.toBeInTheDocument()
    }
  })

  /**
   * `?portal=` used to rewrite the panel and travel with the sign-in as a preference. It is now
   * inert: the URL is not somewhere a portal can be requested from, so the page must look identical.
   */
  it('ignores a portal in the query string', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=agency', locale: 'en' })

    expect(screen.queryByTestId('login-portals')).not.toBeInTheDocument()
    // The single approved panel, unchanged by the parameter.
    expect(screen.getByRole('heading', { name: 'All your paid ad campaigns in one place' })).toBeInTheDocument()
  })

  it('shows the single approved marketing panel', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })
    expect(screen.getByText('One platform for every paid campaign')).toBeInTheDocument()
  })

  it('asks only for an identifier before the server has answered', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
    expect(screen.queryByTestId('login-password')).not.toBeInTheDocument()
    expect(screen.queryByTestId('login-code')).not.toBeInTheDocument()
  })

  it('shows the password step when the server says this account has one', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachPasswordStep()
    expect(screen.queryByTestId('login-code')).not.toBeInTheDocument()
  })

  /**
   * The case the portal chooser used to get wrong.
   *
   * A client who picked «إدارة الحملات» was shown a password field their account has never had.
   * Nobody picks anything now, and the code step is reached because the SERVER said so.
   */
  it('shows the code step for an account that signs in by one-time code', async () => {
    signOut()
    mockedMethod.mockResolvedValue({ method: 'code', channel: 'email' })
    mockedStart.mockResolvedValue({ verification_id: 'v1', dev_code: null } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    identifyAs('client@example.test')

    await waitFor(() => expect(screen.getByTestId('login-code')).toBeInTheDocument())
    expect(screen.queryByTestId('login-password')).not.toBeInTheDocument()
    expect(mockedStart).toHaveBeenCalledWith('email', 'client@example.test')
  })

  /**
   * The rule the whole task turns on: the browser never states a portal.
   *
   * `portal: null` is what makes the sign-in URL grant nothing — the server has no preference to
   * honour, so it picks the destination from real memberships alone.
   */
  it('signs in claiming no portal, even when the URL named one', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'X', email: 'x@y.z' } as never)
    renderWithProviders(<LoginPage />, { route: '/login?portal=admin', locale: 'en' })

    await reachPasswordStep()
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toMatchObject({ portal: null })
  })

  /**
   * "Keep me signed in" drives `Auth::login($user, $remember)` on the server. Holding it in local
   * state and never sending it would make the checkbox decorative, so the payload is asserted.
   */
  it('sends the remember flag with the credentials', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachPasswordStep('t@t.dev')
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toEqual({ email: 't@t.dev', password: 'secret123', remember: true, portal: null })
  })

  it('sends remember: false once the box is cleared', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachPasswordStep('t@t.dev')
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toEqual({ email: 't@t.dev', password: 'secret123', remember: false, portal: null })
  })

  /**
   * A mistyped address must be correctable without a reload.
   *
   * Step two hides the field it came from, so without this the only signal of a wrong address is a
   * password failure — which sends people looking for the wrong problem entirely.
   */
  it('lets somebody go back and use a different account', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachPasswordStep('typo@demo-agency.local')
    fireEvent.click(screen.getByTestId('login-change-identifier'))

    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
    expect(screen.queryByTestId('login-password')).not.toBeInTheDocument()
  })

  /** Zero dead links: every secondary action points at a route that exists. */
  it('links forgot-password and create-account to real routes', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    expect(screen.getByRole('link', { name: /Create an account/i })).toHaveAttribute('href', '/register')

    // «نسيت كلمة المرور» belongs to the password step — it is meaningless before the server has said
    // this account even has one.
    await reachPasswordStep()
    expect(screen.getByRole('link', { name: /Forgot/i })).toHaveAttribute('href', '/forgot-password')
  })
})
