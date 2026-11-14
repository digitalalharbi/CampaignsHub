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

import { fetchTwoStep, login, TwoStepRequired, verifyTwoStep, type TwoStepInfo } from './api'

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

    expect(await screen.findByTestId('two-step-method-email')).toBeInTheDocument()
    expect(screen.getByTestId('two-step-method-recovery')).toBeInTheDocument()
    expect(fetchTwoStep).toHaveBeenCalled()
  })
})
