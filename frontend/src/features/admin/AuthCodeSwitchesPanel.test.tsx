import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AuthCodeSwitchesPanel } from './AuthCodeSwitchesPanel'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchAuthCodeSwitches: vi.fn(), saveAuthCodeSwitches: vi.fn() }
})

import { fetchAuthCodeSwitches, saveAuthCodeSwitches } from './api'

const channel = (over = {}) => ({
  enabled: true, provider: 'configured' as const, last_24h: { issued: 3, sent: 2 }, sent_30d: 10,
  unit_cost: null, currency: null, estimated_cost_30d: null, cost_known: false, ...over,
})

const overview = (over = {}) => ({
  switches: {
    sign_in_sms_code: true, portal_email_code: true, portal_sms_code: true, recovery_sms_code: true, version: 2,
  },
  channels: {
    sign_in_sms_code: channel(), portal_email_code: channel(), portal_sms_code: channel(), recovery_sms_code: channel(),
  },
  portal_code_on_login: false,
  recovery_link: { available: true, switchable: false, open_question: 'LOGIN-CLIENT-CODE-UNIFIED-001' },
  limits: { code_ttl_minutes: 10, max_attempts: 5, resend_cooldown_seconds: 60 },
  lockout: { stranded_contacts: 0, accounts_only_sms_back: 0 },
  counted_since: null,
  changes: [],
  ...over,
})

/** AUTH-CODE-SWITCHES-001 — independent switches; the reset link is not one of them; the server's refusal is shown in its own words. */
describe('AuthCodeSwitchesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchAuthCodeSwitches).mockResolvedValue(overview())
    vi.mocked(saveAuthCodeSwitches).mockResolvedValue(overview())
  })
  afterEach(() => signOut())

  it('shows every switch on by default, and the reset link with no switch at all', async () => {
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    for (const name of ['sign_in_sms_code', 'portal_email_code', 'portal_sms_code', 'recovery_sms_code']) {
      expect(await screen.findByTestId(`code-switch-${name}`)).toBeChecked()
    }
    expect(screen.getByTestId('code-recovery-link')).toBeInTheDocument()
    expect(screen.getByTestId('code-recovery-link').querySelector('input')).toBeNull()
    expect(screen.getByTestId('code-switches-no-audit')).toBeInTheDocument()
  })

  it('shows no switch for the platform email code, whose route is gone', async () => {
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    await screen.findByTestId('code-switch-sign_in_sms_code')
    expect(screen.queryByTestId('code-switch-sign_in_email_code')).not.toBeInTheDocument()
  })

  it('states whether the server has the contact code door open, and offers no control over it', async () => {
    const { unmount } = renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    const closed = await screen.findByTestId('code-portal-door')
    expect(closed).toHaveAttribute('data-open', 'false')
    expect(closed).toHaveTextContent(/closed/)
    expect(closed.querySelector('input')).toBeNull()
    unmount()

    vi.mocked(fetchAuthCodeSwitches).mockResolvedValue(overview({ portal_code_on_login: true }))
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    expect(await screen.findByTestId('code-portal-door')).toHaveAttribute('data-open', 'true')
  })

  it('tells a channel that is on apart from a provider that cannot send, and invents no price', async () => {
    vi.mocked(fetchAuthCodeSwitches).mockResolvedValue(overview({
      channels: { ...overview().channels, sign_in_sms_code: channel({ provider: 'not_configured' }) },
    }))
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    expect(await screen.findByTestId('code-provider-sign_in_sms_code')).toHaveAttribute('data-provider', 'not_configured')
    expect(screen.getByTestId('code-switch-sign_in_sms_code')).toBeChecked()
    expect(screen.getByTestId('code-cost-sign_in_sms_code')).toHaveTextContent(/Unknown/)
  })

  it('needs a change, a reason and a confirmation before anything is saved', async () => {
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    const save = await screen.findByTestId('code-switches-save')
    expect(save).toBeDisabled()

    fireEvent.click(screen.getByTestId('code-switch-sign_in_sms_code'))
    fireEvent.change(screen.getByTestId('code-switches-reason'), { target: { value: 'Cost control' } })
    expect(save).toBeDisabled()

    fireEvent.click(screen.getByTestId('code-switches-confirm'))
    expect(save).toBeEnabled()

    fireEvent.click(save)
    await waitFor(() => expect(saveAuthCodeSwitches).toHaveBeenCalledWith({
      sign_in_sms_code: false, portal_email_code: true, portal_sms_code: true, recovery_sms_code: true,
      reason: 'Cost control', expected_version: 2, confirm_risk: true,
    }))
  })

  it('shows who would be stranded when the server refuses', async () => {
    vi.mocked(saveAuthCodeSwitches).mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { message: 'Invalid', errors: { portal_email_code: ['Refused: 3 client contact(s) would have no way in.'] } } },
    })
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('code-switch-portal_email_code'))
    fireEvent.change(screen.getByTestId('code-switches-reason'), { target: { value: 'Cost control' } })
    fireEvent.click(screen.getByTestId('code-switches-confirm'))
    fireEvent.click(screen.getByTestId('code-switches-save'))

    expect(await screen.findByTestId('code-switches-save-error')).toHaveTextContent('3 client contact(s)')
  })

  it('warns about a contact stranded after the switch went off, with Latin digits in Arabic', async () => {
    vi.mocked(fetchAuthCodeSwitches).mockResolvedValue(overview({ lockout: { stranded_contacts: 4, accounts_only_sms_back: 0 } }))
    renderWithProviders(<AuthCodeSwitchesPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('code-switches-stranded')).toHaveTextContent('4')
    expect(screen.getByTestId('code-limits')).toHaveTextContent('10')
  })
})
