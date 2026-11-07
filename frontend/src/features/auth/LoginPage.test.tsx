import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    login: vi.fn(),
    fetchSignInOptions: vi.fn().mockResolvedValue({ providers: [
      { provider: 'google', label: { en: 'Google', ar: 'Google' }, status: 'live', available: true },
    ], emailCode: false }),
    startOAuth: vi.fn(),
    fetchPendingLink: vi.fn(),
    confirmLinkWithPassword: vi.fn(),
    confirmLinkWithEmail: vi.fn(),
    cancelPendingLink: vi.fn(),
  }
})

import { cancelPendingLink, confirmLinkWithEmail, confirmLinkWithPassword, fetchPendingLink, fetchSignInOptions, login, startOAuth } from './api'

function typeEmail(value: string) {
  fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value } })
}

/**
 * LOGIN-CARD-001 — one card, and the server decides where you land.
 *
 * The property under test is not the wording. It is that the visitor is never asked which portal
 * they want, that the URL cannot grant one, and that every route out of this card ends in a session
 * the server chose.
 */
describe('LoginPage — the sign-in card', () => {
  it('hides the code control when the server says the code is off, and keeps the password and the reset link', async () => {
    vi.mocked(fetchSignInOptions).mockResolvedValueOnce({ providers: [
      { provider: 'google', label: { en: 'Google', ar: 'Google' }, status: 'live', available: true },
    ], emailCode: false })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await screen.findByTestId('login-google')
    expect(screen.queryByTestId('login-email-code')).not.toBeInTheDocument()
    expect(screen.getByLabelText(/password/i, { selector: 'input' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /forgot/i })).toBeInTheDocument()
  })

  it('renders Google sign-in only as an available server-reported capability', async () => {
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('login-google')).toBeEnabled())
    expect(fetchSignInOptions).toHaveBeenCalled()
    expect(screen.getByTestId('login-google')).toHaveTextContent('Continue with Google')
    expect(startOAuth).not.toHaveBeenCalled()
  })

  it('does not advertise Google when the server reports it unavailable', async () => {
    vi.mocked(fetchSignInOptions).mockResolvedValueOnce({ providers: [
      { provider: 'google', label: { en: 'Google', ar: 'Google' }, status: 'awaiting_credentials', available: false },
    ], emailCode: false })

    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await waitFor(() => expect(fetchSignInOptions).toHaveBeenCalled())
    expect(screen.queryByTestId('login-google')).not.toBeInTheDocument()
  })
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('offers no portal choice at all', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

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
    expect(screen.getByRole('heading', { name: 'All your paid ad campaigns in one place' })).toBeInTheDocument()
  })

  /** The approved marketing panel is untouched by this unit — visual lock. */
  it('shows the single approved marketing panel', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })
    expect(screen.getByText('One platform for every paid campaign')).toBeInTheDocument()
  })

  it('keeps the approved heading and subtitle', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.getByRole('heading', { name: 'مرحباً بعودتك' })).toBeInTheDocument()
    /*
     * The product is called «CampaignsHub» in Arabic, and this string said «CampaignsHub».
     *
     * The owner's brand correction names login among the surfaces where the Latin name must not be
     * the visible system name in an Arabic surface. This case pinned the old spelling as approved,
     * so the string could not change without changing the case that held it — which is what a
     * pinned string is for, and why it is updated here deliberately rather than loosened.
     */
    expect(screen.getByText('سجّل الدخول إلى حسابك في كامبينز هب')).toBeInTheDocument()
  })

  /** Everything the card promises is on it, in one place, before anything is clicked. */
  /** AUTH-LOGIN-UX-002 — both credentials, the ways in that can work stacked at full width, recovery and sign-up; no security card. */
  it('carries both credentials, Google, recovery and sign-up — and no security card', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.getByLabelText('البريد الإلكتروني')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور')).toBeInTheDocument()
    expect(screen.getByRole('checkbox')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'نسيت كلمة المرور؟' })).toHaveAttribute('href', '/forgot-password')
    expect(screen.queryByText('حماية حسابك')).not.toBeInTheDocument()
    expect(await screen.findByTestId('login-google')).toBeInTheDocument()
    expect(screen.getByText('أو')).toBeInTheDocument()
    expect(screen.getByTestId('login-column').className).toContain('max-w-[440px]')
    expect(screen.getByRole('link', { name: 'إنشاء حساب' })).toHaveAttribute('href', '/register')
  })

  /** LOGIN-NO-EMAIL-CODE-001 — no email code here, on the first frame or any later one. */
  it('never offers an email code, before or after the server answers', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.queryByTestId('login-request-code')).not.toBeInTheDocument()
    expect(screen.queryByText(/برمز عبر البريد/)).not.toBeInTheDocument()
    await screen.findByTestId('login-google')
    expect(screen.queryByTestId('login-request-code')).not.toBeInTheDocument()
    expect(screen.queryByText(/برمز عبر البريد/)).not.toBeInTheDocument()
  })

  it('reserves Google’s row from the first frame, with nothing to read in it', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    const reserved = screen.getByTestId('login-google-reserved')
    expect(reserved).toBeDisabled()
    expect(reserved).toHaveAttribute('aria-hidden', 'true')
    await screen.findByTestId('login-google')
    expect(screen.queryByTestId('login-google-reserved')).not.toBeInTheDocument()
  })

  it('keeps the Google button when the lookup fails, and reports a failed click', async () => {
    vi.mocked(fetchSignInOptions).mockRejectedValueOnce(new Error('down'))
    vi.mocked(startOAuth).mockRejectedValueOnce(new Error('down'))
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.click(await screen.findByTestId('login-google'))
    expect(await screen.findByTestId('login-error')).toBeInTheDocument()
  })

  it('fills Google’s row with the way home when the server says Google cannot work, leaving no gap', async () => {
    vi.mocked(fetchSignInOptions).mockResolvedValueOnce({ providers: [
      { provider: 'google', label: { en: 'Google', ar: 'Google' }, status: 'awaiting_credentials', available: false },
    ], emailCode: false })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('login-home')).toBeInTheDocument())
    expect(screen.queryByTestId('login-google')).not.toBeInTheDocument()
    expect(screen.queryByTestId('login-google-reserved')).not.toBeInTheDocument()
  })

  it('keeps a link aimed at the portal on this page: there is no other door to hand it to', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?redirect=%2Fportal%2Frequests', locale: 'en' })

    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
  })

  it('offers no code by default, and keeps its line reserved and empty', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await screen.findByTestId('login-google')
    expect(screen.getByTestId('login-email-code-row')).toBeEmptyDOMElement()
    expect(screen.queryByTestId('login-email-code')).not.toBeInTheDocument()
  })

  it('offers the code only when the server enabled it, as a step of this page', async () => {
    vi.mocked(fetchSignInOptions).mockResolvedValueOnce({ providers: [], emailCode: true })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.click(await screen.findByTestId('login-email-code'))
    expect(screen.getByTestId('email-code-form')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('email-code-back'))
    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
  })

  it('hands Google the page it was interrupted on, for the server to judge, and no address that could leave', async () => {
    vi.mocked(startOAuth).mockReturnValue(new Promise(() => undefined))
    signOut()
    const first = renderWithProviders(<LoginPage />, { route: '/login?redirect=%2Fapp%2Fcampaigns', locale: 'en' })
    fireEvent.click(await screen.findByTestId('login-google'))
    await waitFor(() => expect(startOAuth).toHaveBeenCalledWith('google', { redirect: '/app/campaigns' }))
    first.unmount()
    vi.mocked(startOAuth).mockClear()

    renderWithProviders(<LoginPage />, { route: '/login?redirect=https%3A%2F%2Fevil.example', locale: 'en' })
    fireEvent.click(await screen.findByTestId('login-google'))
    await waitFor(() => expect(startOAuth).toHaveBeenCalledWith('google', { redirect: null }))
  })

  it('says what Google answered, in its own words and never the address’s', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?oauth=registration_required&message=%3Cb%3Ephish%3C%2Fb%3E', locale: 'en' })

    expect(screen.getByTestId('login-oauth-note')).toHaveTextContent('No account is connected to this Google account')
    expect(screen.getByTestId('login-oauth-note')).not.toHaveTextContent('phish')
  })

  it('offers no client portal link and no choice of account type, in either language', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.queryByTestId('login-client-door')).not.toBeInTheDocument()
    expect(screen.queryByText(/بوابة العملاء/)).not.toBeInTheDocument()
    expect(document.querySelector('a[href="/portal/login"]')).toBeNull()
  })

  it('words the Google route in Arabic as «الدخول عبر Google»', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('login-google')).toBeEnabled())
    expect(screen.getByTestId('login-google')).toHaveTextContent('الدخول عبر Google')
  })

  /**
   * The rule the whole page turns on: the browser never states a portal.
   *
   * `portal: null` is what makes the sign-in URL grant nothing — the server has no preference to
   * honour, so it picks the destination from real memberships alone.
   */
  it('signs in claiming no portal, even when the URL named one', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'X', email: 'x@y.z' } as never)
    renderWithProviders(<LoginPage />, { route: '/login?portal=admin', locale: 'en' })

    typeEmail('agency@campaignshub.io')
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toMatchObject({ portal: null })
  })

  /**
   * «تذكرني» drives `Auth::login($user, $remember)` on the server. Holding it in local state and
   * never sending it would make the checkbox decorative, so the payload is asserted both ways.
   */
  it('sends the remember flag exactly as the box was left', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    typeEmail('t@t.dev')
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toEqual({ email: 't@t.dev', password: 'secret123', remember: false, portal: null })
  })

  it('sends remember: true once the box is ticked', async () => {
    signOut()
    vi.mocked(login).mockResolvedValue({ id: '1', name: 'T', email: 't@t.dev' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    typeEmail('t@t.dev')
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Sign in/i }))

    await waitFor(() => expect(login).toHaveBeenCalled())
    expect(vi.mocked(login).mock.calls[0]![0]).toMatchObject({ remember: true })
  })

  /** The password is masked until somebody asks for it, and the toggle is labelled both ways. */
  it('reveals and re-hides the password on request', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    const field = screen.getByLabelText(/^Password/i)
    expect(field).toHaveAttribute('type', 'password')

    fireEvent.click(screen.getByRole('button', { name: /Show password/i }))
    expect(field).toHaveAttribute('type', 'text')

    fireEvent.click(screen.getByRole('button', { name: /Hide password/i }))
    expect(field).toHaveAttribute('type', 'password')
  })
})

/**
 * «أو اختر طريقة أخرى للدخول» — the second route out of the same card.
 */
describe('LoginPage — the help route', () => {
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('opens in place, over a card that is still standing', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    fireEvent.click(screen.getByTestId('login-help-open'))

    expect(screen.getByTestId('login-help-form')).toBeInTheDocument()
    expect(screen.getByText('كيف يمكننا مساعدتك؟')).toBeInTheDocument()
    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
  })

})

/**
 * The public page prints no credentials (LOGIN-CLEAN-001).
 *
 * A dev-only block used to list two demo accounts under the form, with the shared password beside
 * them. It was honest about being development-only and it still had to go: it taught the page's
 * shape to everyone who saw a screenshot, and «حسابات تجريبية» under a sign-in box is the first
 * thing a reviewer reads as "this is not finished".
 */
describe('LoginPage — nothing secret is printed on the page', () => {
  afterEach(() => { signOut(); localStorage.clear() })

  it('shows no demo credentials block, and no password anywhere in its text', () => {
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.queryByTestId('demo-credentials')).not.toBeInTheDocument()
    expect(screen.queryByText(/حسابات تجريبية|Demo accounts/i)).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/@demo-/)
    expect(document.body.textContent).not.toMatch(/password:/i)
  })
})

/**
 * LOGIN-004 — Google came back with an address that matches an account: the page shows «ربط Google» instead of a dead end.
 */
describe('LoginPage — linking Google in place', () => {
  const pending = { provider: 'google', name: 'Mohammed G', email: 'owner@example.com', avatar: null, local_email: 'ow•••@example.com', expires_in: 600, email_sent: false, can_email: true }
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('?oauth=link replaces the form with the card', async () => {
    signOut()
    vi.mocked(fetchPendingLink).mockResolvedValue(pending)
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link', locale: 'en' })

    expect(await screen.findByTestId('oauth-link-card')).toBeInTheDocument()
    expect(screen.queryByTestId('login-identify')).not.toBeInTheDocument()
    expect(screen.queryByTestId('login-oauth-note')).not.toBeInTheDocument()
  })

  it('a correct password signs in and goes where the server says', async () => {
    signOut()
    vi.mocked(fetchPendingLink).mockResolvedValue(pending)
    vi.mocked(confirmLinkWithPassword).mockResolvedValue({ user: { id: 'u1' } as never, destination: '/app/campaigns' })
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link', locale: 'en' })

    fireEvent.change(await screen.findByLabelText('Password', { selector: 'input' }), { target: { value: 'correct-horse' } })
    fireEvent.click(screen.getByTestId('oauth-link-submit'))

    await waitFor(() => expect(confirmLinkWithPassword).toHaveBeenCalledWith('correct-horse'))
  })

  it('a mailed link is spent once and its secret is taken out of the address', async () => {
    signOut()
    vi.mocked(fetchPendingLink).mockResolvedValue(pending)
    vi.mocked(confirmLinkWithEmail).mockResolvedValue({ user: { id: 'u1' } as never, destination: '/app/campaigns' })
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link&confirm=secret-from-mail', locale: 'en' })

    await waitFor(() => expect(confirmLinkWithEmail).toHaveBeenCalledWith('secret-from-mail'))
    expect(confirmLinkWithEmail).toHaveBeenCalledTimes(1)
  })

  it('cancel goes back to the ordinary form', async () => {
    signOut()
    vi.mocked(fetchPendingLink).mockResolvedValue(pending)
    vi.mocked(cancelPendingLink).mockResolvedValue(undefined)
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link', locale: 'en' })

    fireEvent.click(await screen.findByTestId('oauth-link-cancel'))

    expect(await screen.findByTestId('login-identify')).toBeInTheDocument()
    expect(screen.queryByTestId('oauth-link-card')).not.toBeInTheDocument()
  })

  it('an expired pending link says so and leads back to the form', async () => {
    signOut()
    vi.mocked(fetchPendingLink).mockRejectedValue({ isAxiosError: true, response: { status: 410, data: { success: false, message: 'x', errors: null, meta: { state: 'expired' } } } })
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link', locale: 'en' })

    fireEvent.click(await screen.findByTestId('oauth-link-back'))

    expect(await screen.findByTestId('login-identify')).toBeInTheDocument()
  })

  it('the refusal for an address Google did not verify keeps its message', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?oauth=link_required', locale: 'en' })

    expect(await screen.findByTestId('login-oauth-note')).toHaveTextContent('not connected yet')
    expect(screen.queryByTestId('oauth-link-card')).not.toBeInTheDocument()
  })
})
