import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, login: vi.fn(), signInMethod: vi.fn(), emailCodeStart: vi.fn(), emailCodeVerify: vi.fn() }
})

vi.mock('@/features/requests/clientPortalApi', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, portalLoginStart: vi.fn(), portalLoginVerify: vi.fn() }
})

import { emailCodeStart, emailCodeVerify, login, signInMethod } from './api'
import { portalLoginStart } from '@/features/requests/clientPortalApi'

const mockedMethod = vi.mocked(signInMethod)
const mockedPortalStart = vi.mocked(portalLoginStart)
const mockedEmailStart = vi.mocked(emailCodeStart)

/** What `/auth/email-code/start` answers when a code has genuinely gone out. */
const DELIVERED = { verification_id: 'v1', delivery_status: 'queued', resend_after: 60, dev_code: null }

function typeEmail(value: string) {
  fireEvent.change(screen.getByLabelText(/Email address/i), { target: { value } })
}

/** Ask for a code as an ordinary platform account, and wait for the step to arrive. */
async function reachCodeStep(identifier = 'agency@campaignshub.io', start: unknown = DELIVERED) {
  mockedMethod.mockResolvedValue({ method: 'password', channel: 'email' })
  mockedEmailStart.mockResolvedValue(start as never)
  typeEmail(identifier)
  fireEvent.click(screen.getByTestId('login-request-code'))
  await waitFor(() => expect(screen.getByTestId('login-code')).toBeInTheDocument())
}

/**
 * LOGIN-CARD-001 — one card, two ways in, and the server decides where you land.
 *
 * The property under test is not the wording. It is that the visitor is never asked which portal
 * they want, that the URL cannot grant one, and that both routes out of this card end in a session
 * the server chose.
 */
describe('LoginPage — the sign-in card', () => {
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
    expect(screen.getByText('سجّل الدخول إلى حسابك في CampaignsHub')).toBeInTheDocument()
  })

  /** Everything the card promises is on it, in one place, before anything is clicked. */
  it('carries both credentials, both secondary actions and the security note', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'ar' })

    expect(screen.getByLabelText('البريد الإلكتروني')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور')).toBeInTheDocument()
    expect(screen.getByRole('checkbox')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'نسيت كلمة المرور؟' })).toHaveAttribute('href', '/forgot-password')
    expect(screen.getByTestId('login-request-code')).toBeInTheDocument()
    expect(screen.getByText('دخول آمن ومشفّر لحماية بياناتك')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'إنشاء حساب' })).toHaveAttribute('href', '/register')
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
 * «أو الدخول بدون كلمة مرور» — the second route out of the same card.
 */
describe('LoginPage — the code route', () => {
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('sends the code through the platform engine and shows the code step', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep('agency@campaignshub.io')

    expect(mockedEmailStart).toHaveBeenCalledWith('agency@campaignshub.io')
    expect(screen.getByTestId('login-otp')).toBeInTheDocument()
    expect(screen.getByTestId('login-code-destination')).toHaveTextContent('agency@campaignshub.io')
  })

  /** A code has to go somewhere. Asked for with an empty field, this says so instead of failing. */
  it('asks for the address before it asks the server for anything', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.click(screen.getByTestId('login-request-code'))

    expect(mockedMethod).not.toHaveBeenCalled()
    expect(screen.getByText(/Enter your email address first/i)).toBeInTheDocument()
  })

  /**
   * A client contact reaches the SAME step through a different engine.
   *
   * `portalLoginStart` opens a portal session for a contact; `emailCodeStart` opens a platform
   * session for a user. Using the portal's for a platform account would sign them into `/portal`,
   * where they hold nothing, and the page would look like it had worked.
   */
  it('routes a client contact through the portal engine, not the platform one', async () => {
    signOut()
    mockedMethod.mockResolvedValue({ method: 'code', channel: 'email' })
    mockedPortalStart.mockResolvedValue({ verification_id: 'v1', dev_code: null } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    typeEmail('client@example.test')
    fireEvent.click(screen.getByTestId('login-request-code'))

    await waitFor(() => expect(screen.getByTestId('login-code')).toBeInTheDocument())
    expect(mockedPortalStart).toHaveBeenCalledWith('email', 'client@example.test')
    expect(mockedEmailStart).not.toHaveBeenCalled()
  })

  /**
   * Honest about delivery.
   *
   * `awaiting_provider_credentials` means no mail provider is configured and NOTHING was sent.
   * Saying «check your inbox» over that would be the product claiming a message it never made.
   */
  it('says plainly when no code was actually sent', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep('agency@campaignshub.io', {
      verification_id: 'v1', delivery_status: 'awaiting_provider_credentials', resend_after: 60, dev_code: null,
    })

    expect(screen.getByTestId('login-code-undelivered')).toBeInTheDocument()
  })

  it('does not warn about delivery when the code was queued', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep()
    expect(screen.queryByTestId('login-code-undelivered')).not.toBeInTheDocument()
  })

  /**
   * The resend is closed while the cooldown runs.
   *
   * The countdown is a courtesy — the server enforces the same window — but a button that can be
   * pressed every second while the server refuses is a control that lies about what it does.
   */
  it('closes the resend until the cooldown has run', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep()
    await waitFor(() => expect(screen.getByTestId('login-resend')).toBeDisabled())
    // Latin digits, in both languages — the platform rule, and a countdown is a number.
    expect(screen.getByTestId('login-resend')).toHaveTextContent(/\d+/)
  })

  it('will not submit a partial code', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep()

    const submit = screen.getByTestId('login-code').querySelector('button[type="submit"]')!
    expect(submit).toBeDisabled()

    fireEvent.change(screen.getByTestId('login-otp-0'), { target: { value: '123456' } })
    await waitFor(() => expect(submit).not.toBeDisabled())
  })

  it('verifies the code it was given', async () => {
    signOut()
    vi.mocked(emailCodeVerify).mockResolvedValue({ id: '1', name: 'X', email: 'x@y.z' } as never)
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep('agency@campaignshub.io')
    fireEvent.change(screen.getByTestId('login-otp-0'), { target: { value: '424242' } })

    await waitFor(() => expect(emailCodeVerify).toHaveBeenCalledWith('v1', '424242', false))
  })

  /**
   * A mistyped address must be correctable without a reload.
   *
   * The code step hides the field it came from, so without this the only signal of a wrong address
   * is a code that never arrives — which sends people looking for the wrong problem entirely.
   */
  it('lets somebody go back and use a different address', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep('typo@demo-agency.local')
    fireEvent.click(screen.getByTestId('login-change-identifier'))

    expect(screen.getByTestId('login-identify')).toBeInTheDocument()
    expect(screen.queryByTestId('login-code')).not.toBeInTheDocument()
  })
})

/**
 * LOGIN-HELP-001 — «تحتاج مساعدة في البدء؟», answered without leaving the page.
 *
 * It is a detour, not a journey: opening it must not cost the URL somebody arrived on, and it must
 * not be mistakable for a second way to sign up.
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

  it('is not offered beside a code that is already on its way', async () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    await reachCodeStep()
    expect(screen.queryByTestId('login-help-open')).not.toBeInTheDocument()
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
