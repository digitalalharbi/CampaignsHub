import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AccountStatusPage } from './AccountStatusPage'
import type { RegistrationEnvelope, RegistrationState } from '@/features/signup/api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('@/features/signup/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchRegistration: vi.fn(),
    verifyRegistrationEmail: vi.fn(),
    verifyRegistrationMobile: vi.fn(),
    resendRegistrationChallenge: vi.fn(),
  }
})

import {
  fetchRegistration, resendRegistrationChallenge, verifyRegistrationEmail, verifyRegistrationMobile,
} from '@/features/signup/api'

const envelope = (
  state: RegistrationState,
  over: Partial<RegistrationEnvelope['registration']> = {},
  policy: Partial<RegistrationEnvelope['policy']> = {},
): RegistrationEnvelope => ({
  registration: {
    id: 'reg-1', state, label: `state:${state}`, email: 'applicant@a.test',
    requested_portal: null, plan_code: null,
    email_verified: state !== 'email_verification_required',
    mobile_verified: false, next_step: null, reason: null, provisioned: state === 'active',
    ...over,
  },
  policy: { requires_mobile: false, requires_approval: false, requires_payment: false, ...policy },
})

/**
 * The screen an applicant sees before they have an account (SIGNUP-002).
 *
 * The claim under test throughout is that the page never invents agency the applicant does not have:
 * it offers an action exactly when there is one, and says so plainly when there is not.
 */
describe('AccountStatusPage', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  it('offers the confirmation actions while the email is outstanding', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(
      envelope('email_verification_required', { next_step: 'Confirm your email address.' }),
    )

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    const status = await screen.findByTestId('registration-status')
    expect(status).toHaveAttribute('data-state', 'email_verification_required')
    expect(screen.getByTestId('registration-next-step')).toHaveTextContent('Confirm your email address.')
    expect(screen.getByTestId('registration-resend-email')).toBeInTheDocument()
  })

  /**
   * The case the whole page exists for: an application waiting on US.
   *
   * There must be no next step and no action, because there is nothing the applicant can do — and a
   * button here would be the interface misrepresenting who is holding things up.
   */
  it('says nothing is required of an applicant who is waiting on a review', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(
      envelope('pending_approval', {}, { requires_approval: true }),
    )

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    await screen.findByTestId('registration-status')
    expect(screen.queryByTestId('registration-next-step')).not.toBeInTheDocument()
    expect(screen.getByTestId('registration-waiting')).toHaveTextContent(/nothing for you to do/i)
    expect(screen.queryByTestId('registration-resend-email')).not.toBeInTheDocument()
  })

  /** No pay button while no gateway exists, and the activation rule stated rather than implied. */
  it('does not offer a payment it cannot take', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(
      envelope('approved_awaiting_payment', { next_step: 'Complete payment to activate your workspace.' }, { requires_payment: true }),
    )

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    await screen.findByTestId('registration-status')
    expect(screen.getByTestId('registration-payment-note')).toHaveTextContent(/awaiting credentials/i)
    expect(screen.getByTestId('registration-payment-note')).toHaveTextContent(/only once the provider confirms/i)
  })

  /** The steps shown are the ones this plan actually requires — not a fixed list. */
  it('shows only the activation steps the policy asks for', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(
      envelope('mobile_verification_required', {}, { requires_mobile: true, requires_approval: true }),
    )

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    await screen.findByTestId('registration-steps')
    expect(screen.getByTestId('registration-step-mobile')).toBeInTheDocument()
    expect(screen.getByTestId('registration-step-approval')).toBeInTheDocument()
    expect(screen.queryByTestId('registration-step-payment')).not.toBeInTheDocument()
    // The email step is already behind them.
    expect(screen.getByTestId('registration-step-email')).toHaveAttribute('data-done', 'true')
  })

  it('answers the mobile challenge with the code', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(
      envelope('mobile_verification_required', {}, { requires_mobile: true }),
    )
    vi.mocked(verifyRegistrationMobile).mockResolvedValue(envelope('active', { provisioned: true }))

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    fireEvent.change(await screen.findByLabelText(/code we sent/i), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: /Confirm code/i }))

    await waitFor(() => expect(verifyRegistrationMobile).toHaveBeenCalledWith('reg-1', '123456'))
  })

  it('consumes a confirmation link exactly once', async () => {
    vi.mocked(verifyRegistrationEmail).mockResolvedValue(envelope('pending_approval', {}, { requires_approval: true }))

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1&token=abc123', locale: 'en' })

    // `expect.anything()` for the second argument: react-query hands a mutationFn its own context.
    await waitFor(() => expect(verifyRegistrationEmail).toHaveBeenCalledWith('abc123', expect.anything()))
    // A single-use token fired twice turns a success into "already used".
    expect(vi.mocked(verifyRegistrationEmail)).toHaveBeenCalledTimes(1)
    expect(fetchRegistration).not.toHaveBeenCalled()
  })

  it('surfaces a refusal instead of a blank screen', async () => {
    vi.mocked(verifyRegistrationEmail).mockRejectedValue({
      response: { status: 422, data: { message: 'This verification link has expired.' } },
    })

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1&token=stale', locale: 'en' })

    expect(await screen.findByTestId('registration-error')).toHaveTextContent(/expired/i)
  })

  it('sends the visitor to sign-up when there is no application to show', () => {
    renderWithProviders(<AccountStatusPage />, { route: '/signup/status', locale: 'en' })

    expect(screen.getByTestId('registration-missing')).toBeInTheDocument()
    expect(fetchRegistration).not.toHaveBeenCalled()
  })

  /** Closing the tab is recoverable: the id is remembered, so a bare URL still finds the application. */
  it('recovers the application id from local storage', async () => {
    localStorage.setItem('chub:registration', 'reg-1')
    vi.mocked(fetchRegistration).mockResolvedValue(envelope('email_verification_required'))

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status', locale: 'en' })

    await waitFor(() => expect(fetchRegistration).toHaveBeenCalledWith('reg-1'))
  })

  it('offers a fresh challenge when the applicant asks for one', async () => {
    vi.mocked(fetchRegistration).mockResolvedValue(envelope('email_verification_required'))
    vi.mocked(resendRegistrationChallenge).mockResolvedValue({
      ...envelope('email_verification_required'),
      verification: {
        channel: 'email', delivery_status: 'awaiting_provider_credentials',
        dev_link: '/signup/status?request=reg-1&token=fresh', dev_code: null,
        expires_at: '2026-08-01T00:00:00+00:00',
      },
    })

    renderWithProviders(<AccountStatusPage />, { route: '/signup/status?request=reg-1', locale: 'en' })

    fireEvent.click(await screen.findByTestId('registration-resend-email'))

    await waitFor(() => expect(resendRegistrationChallenge).toHaveBeenCalledWith('reg-1', 'email'))
    // The dev link is what stands in for an email nobody can send yet.
    expect(await screen.findByTestId('registration-dev-verify')).toHaveAttribute('href', '/signup/status?request=reg-1&token=fresh')
  })
})
