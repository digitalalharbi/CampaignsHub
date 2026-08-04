import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegisterPage } from './RegisterPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('@/features/signup/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, apply: vi.fn(), rememberRegistration: vi.fn(), fetchPlans: vi.fn() }
})

import { apply, fetchPlans } from '@/features/signup/api'

/**
 * The catalogue the packages step reads. Priced, because there is no free tier any more
 * (PLAN-PAID-001) — a test whose catalogue still contained a 0 SAR plan would be rehearsing a
 * journey the product no longer offers.
 */
const aCatalogue = {
  plans: [
    {
      code: 'starter', name: 'Starter', name_ar: 'البداية',
      summary_ar: 'متابعة الحملات والتقارير.', summary_en: 'Campaign tracking and reports.',
      currency: 'SAR', price_monthly: '99.00', price_annual: '990.00',
      trial_days: 0, trial_fee: '0.00', features: {}, limits: {}, trial_limits: null,
      is_active: true, is_public: true, sort_order: 10,
    },
  ],
} as never

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
    const select = screen.getByLabelText('Account type') as HTMLSelectElement
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

  /**
   * A withdrawn module does not carry, even from a bookmarked link (INFL-OFF-001).
   *
   * The influencer cards are gone from the public site, but `/register?module=influencer-marketing`
   * is still a live URL that people have. Honouring it would open an application for a service with
   * no portal to serve it — so the form falls back to the paid-media path it can actually deliver,
   * rather than showing a service name and then being refused by the backend on submit.
   */
  it('does not carry a withdrawn module through from an old link', () => {
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=influencer-marketing', locale: 'en' })
    expect(screen.queryByText('Influencer & content campaigns')).not.toBeInTheDocument()
    expect(screen.getByText('Paid advertising management')).toBeInTheDocument()
  })
})

/**
 * The chosen path must reach the backend with the registration itself. Router state would be lost on a
 * refresh, and the onboarding wizard would then ask the visitor to pick the same path a second time.
 */
describe('RegisterPage — the journey is submitted, not just displayed', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); vi.mocked(fetchPlans).mockResolvedValue(aCatalogue) })
  afterEach(() => { signOut(); localStorage.clear() })

  const fill = async () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByTestId('phone'), { target: { value: '0501234567' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    // Step one collects the details; step two asks for the plan (PLAN-001e). The application is
    // submitted from the second, so every test that expects a POST has to walk both — including
    // choosing a plan, which is required now that nothing is free.
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(await screen.findByTestId('plan-starter'))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))
  }

  it('submits the agency path as account_type + service', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=multi-client&module=paid-media', locale: 'en' })
    await fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    expect(vi.mocked(apply).mock.calls[0][0]).toMatchObject({ account_type: 'agency', service: 'paid_media', phone: '+966501234567' })
  })

  it('submits the self-managed path with the selected account type', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register?journey=self-service&module=paid-media', locale: 'en' })
    fireEvent.change(screen.getByLabelText('Account type'), { target: { value: 'brand' } })
    await fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    expect(vi.mocked(apply).mock.calls[0][0]).toMatchObject({ account_type: 'brand', service: 'paid_media' })
  })

  it('presumes nothing when the visitor arrived without a journey', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })
    await fill()
    await waitFor(() => expect(apply).toHaveBeenCalled())
    const payload = vi.mocked(apply).mock.calls[0][0] as unknown as Record<string, unknown>
    expect(payload).not.toHaveProperty('account_type')
    expect(payload).not.toHaveProperty('service')
  })
})

describe('RegisterPage — error summary + draft', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); vi.mocked(fetchPlans).mockResolvedValue(aCatalogue) })
  afterEach(() => { signOut(); localStorage.clear() })

  it('shows an ErrorSummary on a failed submit and focuses the field on click', async () => {
    vi.mocked(apply).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { email: ['The email has already been taken.'] } } },
    })
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'dup@test.dev' } })
    fireEvent.change(screen.getByTestId('phone'), { target: { value: '0501234567' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(await screen.findByTestId('plan-starter'))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    /*
     * The refusal is about a field on the FIRST step, so the form goes back to it.
     *
     * Only the server can know the address is taken, and it only finds out at the submit — which
     * happens on the packages step. Rendering the message there would put an error about a field on
     * a screen the visitor has left, which is exactly what SIGNUP-STEP-001 removes.
     */
    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The email has already been taken.')
    expect(screen.getByTestId('register-panel-account')).toBeVisible()
    expect(screen.queryByTestId('register-panel-plan')).not.toBeInTheDocument()

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
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); vi.mocked(fetchPlans).mockResolvedValue(aCatalogue) })
  afterEach(() => { signOut(); localStorage.clear() })

  const fillDetails = () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByTestId('phone'), { target: { value: '0501234567' } })
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

  /**
   * A plan is required now (PLAN-PAID-001).
   *
   * It used to be optional — "you can pick one later, before activation" — which was true only while
   * a free tier existed to fall back to. An application naming no plan owes an amount nobody can
   * compute, so the form refuses rather than opening one that could never be activated.
   */
  it('refuses to apply until a plan is chosen', async () => {
    vi.mocked(apply).mockResolvedValue(anApplication)
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillDetails()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    await screen.findByTestId('plan-starter')
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    expect(await screen.findByTestId('register-plan-error')).toHaveTextContent(/Choose a plan/i)
    expect(apply).not.toHaveBeenCalled()

    // …and it applies as soon as one is picked, carrying the term as well as the code.
    fireEvent.click(screen.getByTestId('plan-starter'))
    fireEvent.click(screen.getByRole('button', { name: /Create account/i }))

    await waitFor(() => expect(apply).toHaveBeenCalled())
    expect(vi.mocked(apply).mock.calls[0][0]).toMatchObject({ plan_code: 'starter', billing_interval: 'monthly' })
  })

  /** The annual price is on screen before anybody is asked to pay it. */
  it('shows the annual amount when the annual term is chosen', async () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillDetails()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    await screen.findByTestId('plan-starter')
    expect(screen.getByTestId('plan-starter')).toHaveTextContent('99.00')

    fireEvent.click(screen.getByTestId('plan-interval-annual'))
    expect(screen.getByTestId('plan-starter')).toHaveTextContent('990.00')
  })
})

/**
 * SIGNUP-STEP-001 — the account step is a gate.
 *
 * The failure being tested against is specific and was real: a weak password was accepted by the
 * form, carried silently to the packages step, and surfaced there as an error beside a price list,
 * with the field it referred to on a screen the visitor had left.
 */
describe('RegisterPage — the account step is validated before the packages step', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); vi.mocked(fetchPlans).mockResolvedValue(aCatalogue) })
  afterEach(() => { signOut(); localStorage.clear() })

  const fillValid = () => {
    fireEvent.change(screen.getByLabelText(/Organization|Org/i), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText(/Full name/i), { target: { value: 'Tester' } })
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'new@test.dev' } })
    fireEvent.change(screen.getByTestId('phone'), { target: { value: '0501234567' } })
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
  }

  it('will not move to the packages step while a field is invalid', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    expect(screen.queryByTestId('register-panel-plan')).not.toBeInTheDocument()
    expect(screen.getByTestId('error-summary')).toBeInTheDocument()
  })

  it('names a weak password beside the password field, on the step that has one', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'short' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'short' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    expect(screen.getAllByText(/at least 8 characters/i).length).toBeGreaterThan(0)
    expect(screen.queryByTestId('register-panel-plan')).not.toBeInTheDocument()
    expect(screen.getByLabelText(/^Password/i)).toHaveFocus()
  })

  it('names a password with no digit, and a confirmation that does not match', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'letters-only' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'something-else' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    expect(screen.getAllByText(/at least one number/i).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/does not match/i).length).toBeGreaterThan(0)
  })

  it('names a malformed email address', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.change(screen.getByLabelText(/Email/i), { target: { value: 'not-an-address' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    expect(screen.getAllByText(/valid email address/i).length).toBeGreaterThan(0)
    expect(screen.queryByTestId('register-panel-plan')).not.toBeInTheDocument()
  })

  it('clears a field’s error as soon as it is corrected', () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'short' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    expect(screen.getAllByText(/at least 8 characters/i).length).toBeGreaterThan(0)

    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    expect(screen.queryByText(/at least 8 characters/i)).not.toBeInTheDocument()
  })

  /** Nothing from the account step is ever rendered on the packages step. */
  it('shows no account-step error on the packages step', async () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'short' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    expect(screen.getAllByText(/at least 8 characters/i).length).toBeGreaterThan(0)

    // Correct it and move on — the earlier complaint must not follow.
    fireEvent.change(screen.getByLabelText(/^Password/i), { target: { value: 'secret123' } })
    fireEvent.change(screen.getByLabelText(/Confirm password/i), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))

    expect(await screen.findByTestId('register-panel-plan')).toBeInTheDocument()
    expect(screen.queryByTestId('error-summary')).not.toBeInTheDocument()
    expect(screen.queryByText(/at least 8 characters/i)).not.toBeInTheDocument()
  })

  /** Going back and forward keeps everything, secrets included. */
  it('keeps the whole form across a round trip to the packages step', async () => {
    renderWithProviders(<RegisterPage />, { route: '/register', locale: 'en' })

    fillValid()
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    fireEvent.click(await screen.findByTestId('plan-starter'))
    fireEvent.click(screen.getByTestId('register-back'))

    expect((screen.getByLabelText(/Organization|Org/i) as HTMLInputElement).value).toBe('Acme')
    expect((screen.getByLabelText(/Email/i) as HTMLInputElement).value).toBe('new@test.dev')
    expect((screen.getByLabelText(/^Password/i) as HTMLInputElement).value).toBe('secret123')
    expect((screen.getByLabelText(/Confirm password/i) as HTMLInputElement).value).toBe('secret123')

    // …and the plan chosen before going back is still chosen on the way forward.
    fireEvent.click(screen.getByRole('button', { name: /Continue/i }))
    expect(await screen.findByTestId('plan-starter')).toHaveAttribute('data-selected', 'true')
  })
})
