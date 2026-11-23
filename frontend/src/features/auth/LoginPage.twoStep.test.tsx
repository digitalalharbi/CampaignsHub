import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    login: vi.fn(),
    fetchSignInOptions: vi.fn().mockResolvedValue({ providers: [], emailCode: false }),
    fetchTwoStep: vi.fn(),
    sendTwoStepCode: vi.fn(),
    verifyTwoStep: vi.fn(),
    cancelTwoStep: vi.fn().mockResolvedValue(undefined),
  }
})

import { fetchTwoStep, login, sendTwoStepCode, TwoStepRequired, verifyTwoStep, type TwoStepInfo } from './api'

const TOTP_ONLY: TwoStepInfo = {
  required: true, methods: ['totp'], default: 'totp', email_hint: null, phone_hint: null, recovery_available: true, resend_after: 60,
}

/**
 * TWO-STEP-001 — a right password for an account with a second step on is not a sign-in.
 *
 * The page must show the challenge instead of navigating, must not set a user, and must only finish once the server opens the
 * session after a right answer.
 */
describe('LoginPage — the second step', () => {
  afterEach(() => { signOut(); vi.clearAllMocks() })

  it('holds a right password at the challenge and finishes only after the code', async () => {
    vi.mocked(login).mockRejectedValueOnce(new TwoStepRequired(TOTP_ONLY))
    vi.mocked(verifyTwoStep).mockResolvedValueOnce({ user: { id: '1', name: 'A', email: 'a@t.test' } as never, destination: '/app/dashboard' })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })

    fireEvent.change(await screen.findByLabelText(/Email address/i), { target: { value: 'a@t.test' } })
    fireEvent.change(screen.getByLabelText(/password/i, { selector: 'input' }), { target: { value: 'secret-pass' } })
    fireEvent.submit(screen.getByTestId('login-identify').querySelector('form') ?? screen.getByTestId('login-identify'))

    expect(await screen.findByTestId('two-step')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    fireEvent.change(screen.getByTestId('two-step-otp-0'), { target: { value: '123456' } })
    await waitFor(() => expect(verifyTwoStep).toHaveBeenCalledWith('totp', '123456'))
  })

  it('asks the server what the pending step accepts after a redirect (Google)', async () => {
    vi.mocked(fetchTwoStep).mockResolvedValueOnce({ ...TOTP_ONLY, methods: ['totp', 'email'], email_hint: 'a•••@t.test' })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?two_step=1', locale: 'en' })

    // The method in use is named and explained; the others sit behind «Try another way», each saying where its code goes.
    expect(await screen.findByTestId('two-step-panel-totp')).toHaveTextContent('Authenticator app')
    expect(screen.getByTestId('two-step-explain')).toHaveTextContent(/Google Authenticator/)
    fireEvent.click(screen.getByTestId('two-step-other'))
    expect(screen.getByTestId('two-step-method-email')).toHaveTextContent('a•••@t.test')
    expect(screen.getByTestId('two-step-method-recovery')).toBeInTheDocument()
    expect(fetchTwoStep).toHaveBeenCalled()
  })

  it('says where an SMS code went and how long it lasts (TWO-STEP-UX-002)', async () => {
    vi.mocked(fetchTwoStep).mockResolvedValueOnce({ ...TOTP_ONLY, methods: ['sms', 'email'], default: 'sms', phone_hint: '+966 5•• ••• 567', email_hint: 'a•••@t.test' })
    vi.mocked(sendTwoStepCode).mockResolvedValue({ delivery_status: 'sent', resend_after: 60, dev_code: null })
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?two_step=1', locale: 'en' })

    // The default (SMS) is sent on arrival and the screen says so, with the masked number and the code's lifetime.
    expect(await screen.findByTestId('two-step-sent')).toHaveTextContent('Sent to +966 5•• ••• 567')
    expect(screen.getByTestId('two-step-sent')).toHaveTextContent('valid for 10 minutes')
    expect(sendTwoStepCode).toHaveBeenCalledWith('sms')

    fireEvent.click(screen.getByTestId('two-step-other'))
    fireEvent.click(screen.getByTestId('two-step-method-email'))
    expect(screen.getByTestId('two-step-panel-email')).toHaveTextContent('Enter the code sent to your email')
    fireEvent.click(screen.getByTestId('two-step-send'))
    await waitFor(() => expect(sendTwoStepCode).toHaveBeenCalledWith('email'))
  })
})
