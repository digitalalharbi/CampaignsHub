import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegisterPage } from './RegisterPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('@/features/signup/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, apply: vi.fn(), rememberRegistration: vi.fn() }
})

import { apply } from '@/features/signup/api'

/** What POST /auth/register answers with now: an application, never a user (SIGNUP-002). */
const anApplication = {
  registration: {
    id: 'reg-1', state: 'email_verification_required', label: 'Awaiting email confirmation',
    email: 'new@test.dev', requested_portal: null, plan_code: null,
    email_verified: false, mobile_verified: false, next_step: 'Confirm your email address.',
    reason: null, provisioned: false,
  },
  policy: { requires_mobile: false, requires_approval: false, requires_payment: false },
} as never

describe('RegisterPage — journey handoff', () => {
  afterEach(() => { signOut(); localStorage.clear() })

  it('presets the agency path from ?journey=multi-client and keeps it editable', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client', locale: 'en' })
    expect(screen.getByText('Your selected path')).toBeInTheDocument()
    // Agency card is the selected path (not a forced re-pick).
    expect(screen.getByRole('button', { name: /I manage several clients/i })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /I run my own campaigns/i })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText(/Clients and requests enabled for the agency workspace/i)).toBeInTheDocument()
  })

  it('presets the self-managed path from ?journey=self-service with an editable account type', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service', locale: 'en' })
    expect(screen.getByRole('button', { name: /I run my own campaigns/i })).toHaveAttribute('aria-pressed', 'true')
    // Account-type select is offered (freelancer/brand/in-house), defaulting to freelancer.
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('freelancer')
    expect(screen.getByRole('option', { name: 'In-house team' })).toBeInTheDocument()
  })

  it('behaves as today when no ?journey param is present (no preset panel)', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    expect(screen.queryByText('Your selected path')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /I run my own campaigns/i })).not.toBeInTheDocument()
  })

  /** A public page shows the service by name; `paid-media` is a slug for us, not language for a visitor. */
  it('names the chosen service instead of echoing the raw slug', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid-media', locale: 'en' })
    expect(screen.getByText(/Selected service/i)).toBeInTheDocument()
    expect(screen.getByText('Paid advertising management')).toBeInTheDocument()
    expect(screen.queryByText('paid-media')).not.toBeInTheDocument()
  })

  it('carries the influencer module through as its own service', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=influencer-marketing', locale: 'en' })
    expect(screen.getByText('Influencer & content campaigns')).toBeInTheDocument()
  })
})

/**
 * The chosen path must reach the backend with the registration itself. Router state would be lost on a
 * refresh, and the onboarding wizard would then ask the visitor to pick the same path a second time.
 */
describe('RegisterPage — the journey is submitted, not just displayed', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  const fill = () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    // Step one collects the details; step two asks for the plan (PLAN-001e). The application is
    // submitted from the second, so every test that expects a POST has to walk both.
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))
  }

  it('submits the agency path as account_type + service', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid-media', locale: 'en' })
    fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    expect(vi.mocked(apply).mock.calls[0][0]).toMatchObject({ account_type: 'agency', service: 'paid_media' })
  })

  it('submits the self-managed path with the selected account type', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=paid-media', locale: 'en' })
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'brand' } })
    fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    expect(vi.mocked(apply).mock.calls[0][0]).toMatchObject({ account_type: 'brand', service: 'paid_media' })
  })

  it('presumes nothing when the visitor arrived without a journey', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    const payload = vi.mocked(apply).mock.calls[0][0] as unknown as Record<string, unknown>
    expect(payload).not.toHaveProperty('account_type')
    expect(payload).not.toHaveProperty('service')
  })
})

describe('RegisterPage — error summary + draft', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  it('shows an ErrorSummary on a failed submit and focuses the field on click', async () => {
    vi.mocked(apply).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { email: ['The email has already been taken.'] } } },
    })
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'dup@test.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The email has already been taken.')

    fireEvent.click(screen.getByRole('button', { name: 'The email has already been taken.' }))
    expect(screen.getByLabelText(/Email/i)).toHaveFocus()
  })

  it('restores a saved draft (non-secret fields) on mount', () => {
    localStorage.setItem('chub:draft:register', JSON.stringify({ tenant_name: 'Restored Co', name: 'Draft User', email: 'draft@test.dev' }))
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    expect((screen.getByLabelText(/Organization|Org/i) as HTMLInputElement).value).toBe('Restored Co')
    expect((screen.getByLabelText(/Email/i) as HTMLInputElement).value).toBe('draft@test.dev')
  })
})

/**
 * The two-step form (PLAN-001e).
 *
 * The details and the plan are separate questions asked on separate screens, because one screen
 * could not hold both and still fit the page's height budget. What matters is that the split loses
 * nothing: the fields survive going back, and moving on is not the same as applying.
 */
describe('RegisterPage — details, then plan', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear() })
  afterEach(() => { signOut(); localStorage.clear() })

  const fillDetails = () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
  }

  it('does not submit the application from the first step', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillDetails()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    // Moving on is not applying: nothing has been sent.
    expect(apply).not.toHaveBeenCalled()
    expect(await screen.findByTestId('register-panel-plan')).toBeInTheDocument()
  })

  /** Going back must not empty the form — the fields stay mounted across the step. */
  it('keeps what was typed when the visitor goes back', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillDetails()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(screen.getByTestId('register-back'))

    expect((screen.getByLabelText(/Email/i) as HTMLInputElement).value).toBe('new@test.dev')
    expect((screen.getByLabelText(/^Password/i) as HTMLInputElement).value).toBe('secret123')
  })

  /** An application carries no plan unless one was chosen — an empty string is not a plan. */
  it('sends no plan when none was chosen', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillDetails()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    await waitFor(() => expect(apply).toHaveBeenCalled())
    const payload = vi.mocked(apply).mock.calls[0][0] as unknown as Record<string, unknown>
    expect(payload).not.toHaveProperty('plan_code')
    expect(payload).not.toHaveProperty('billing_interval')
  })
})
