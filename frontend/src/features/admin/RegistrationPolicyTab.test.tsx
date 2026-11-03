import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RegistrationPolicyTab } from './RegistrationPolicyTab'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchRegistrationVerificationPolicy: vi.fn(), saveRegistrationVerificationPolicy: vi.fn() }
})

import { fetchRegistrationVerificationPolicy, saveRegistrationVerificationPolicy } from './api'

const channel = (over = {}) => ({
  gate: 'enabled' as const, provider: 'configured' as const,
  last_24h: { attempted: 3, delivered: 2, blocked: 1 }, delivered_30d: 10,
  cap: null, alert: 'no_cap' as const, unit_cost: null, currency: null, estimated_cost_30d: null, cost_known: false,
  ...over,
})

const overview = (over = {}) => ({
  policy: {
    email_enabled: true, mobile_enabled: true, email_daily_cap: null, sms_daily_cap: null, per_application_daily_cap: null,
    email_unit_cost: null, sms_unit_cost: null, cost_currency: null, budget_alert_percent: 80, unverified_signups_daily_cap: 200, version: 4,
  },
  channels: { email: channel(), mobile: channel() },
  open_applications: { at_email_gate: 1, at_mobile_gate: 2 },
  unverified_signups_last_24h: 0,
  counted_since: '2026-11-03T00:00:00+00:00',
  changes: [],
  ...over,
})

/** SIGNUP-VERIFY-POLICY-001 — two independent switches; turning one off needs a reason and an explicit yes. */
describe('RegistrationPolicyTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchRegistrationVerificationPolicy).mockResolvedValue(overview())
    vi.mocked(saveRegistrationVerificationPolicy).mockResolvedValue(overview())
  })
  afterEach(() => signOut())

  it('shows two independent switches, both on by default, and no invented price', async () => {
    renderWithProviders(<RegistrationPolicyTab />)

    expect(await screen.findByTestId('policy-switch-email')).toBeChecked()
    expect(screen.getByTestId('policy-switch-mobile')).toBeChecked()
    expect(screen.getByTestId('policy-cost-mobile')).toHaveTextContent(/Unknown/)
    expect(screen.getByTestId('policy-no-audit')).toBeInTheDocument()
  })

  it('tells a missing provider apart from a disabled gate', async () => {
    vi.mocked(fetchRegistrationVerificationPolicy).mockResolvedValue(overview({
      channels: { email: channel(), mobile: channel({ provider: 'not_configured' }) },
    }))
    renderWithProviders(<RegistrationPolicyTab />)

    expect(await screen.findByTestId('policy-provider-mobile')).toHaveAttribute('data-provider', 'not_configured')
    expect(screen.getByTestId('policy-switch-mobile')).toBeChecked()
  })

  it('says when a channel is at its cap and shows only a cost it can compute', async () => {
    vi.mocked(fetchRegistrationVerificationPolicy).mockResolvedValue(overview({
      channels: {
        email: channel(),
        mobile: channel({ cap: 5, alert: 'at_cap', cost_known: true, estimated_cost_30d: '2.5000', currency: 'USD' }),
      },
    }))
    renderWithProviders(<RegistrationPolicyTab />)

    expect(await screen.findByTestId('policy-alert-mobile')).toHaveTextContent(/At the cap/)
    expect(screen.getByTestId('policy-cost-mobile')).toHaveTextContent('2.5000 USD')
  })

  it('refuses to disable a channel until there is a reason and a confirmation', async () => {
    renderWithProviders(<RegistrationPolicyTab />)

    fireEvent.click(await screen.findByTestId('policy-switch-email'))
    expect(screen.getByTestId('policy-risk')).toBeInTheDocument()
    expect(screen.getByTestId('policy-save')).toBeDisabled()

    fireEvent.change(screen.getByTestId('policy-reason'), { target: { value: 'SMTP cost review' } })
    expect(screen.getByTestId('policy-save')).toBeDisabled()

    fireEvent.click(screen.getByTestId('policy-confirm'))
    expect(screen.getByTestId('policy-save')).toBeEnabled()

    fireEvent.click(screen.getByTestId('policy-save'))

    await waitFor(() => expect(saveRegistrationVerificationPolicy).toHaveBeenCalled())
    expect(vi.mocked(saveRegistrationVerificationPolicy).mock.calls[0][0]).toMatchObject({
      email_enabled: false, mobile_enabled: true, reason: 'SMTP cost review', confirm_risk: true, expected_version: 4,
    })
  })

  it('warns harder when both are off', async () => {
    renderWithProviders(<RegistrationPolicyTab />)

    fireEvent.click(await screen.findByTestId('policy-switch-email'))
    const single = screen.getByTestId('policy-risk').textContent
    fireEvent.click(screen.getByTestId('policy-switch-mobile'))

    expect(screen.getByTestId('policy-risk').textContent).not.toBe(single)
    expect(screen.getByTestId('policy-risk')).toHaveTextContent(/Payment and approval still apply/)
  })

  it('says it in Arabic with Latin digits', async () => {
    renderWithProviders(<RegistrationPolicyTab />, { locale: 'ar' })

    expect(await screen.findByText('سياسة التسجيل والتحقق')).toBeInTheDocument()
    expect(screen.getByTestId('policy-channel-email').textContent).toMatch(/3/)
    expect(screen.getByTestId('policy-channel-email').textContent).not.toMatch(/[٠-٩]/)
  })

  it('shows the server refusing a stale decision', async () => {
    vi.mocked(saveRegistrationVerificationPolicy).mockRejectedValue(new Error('The policy was changed by someone else.'))
    renderWithProviders(<RegistrationPolicyTab />)

    fireEvent.change(await screen.findByTestId('policy-reason'), { target: { value: 'Set a cap only' } })
    fireEvent.click(screen.getByTestId('policy-save'))

    expect(await screen.findByTestId('policy-save-error')).toBeInTheDocument()
  })
})
