import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PhoneCredentialCard } from './PhoneCredential'
import { renderWithProviders } from '@/test/utils'
import type { PhoneCredential } from './api'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    getPhoneCredential: vi.fn(),
    startPhoneConfirmation: vi.fn(),
    confirmPhone: vi.fn(),
    revokePhoneCredential: vi.fn(),
  }
})

import { getPhoneCredential, startPhoneConfirmation, confirmPhone, revokePhoneCredential } from './api'

function credential(over: Partial<PhoneCredential> = {}): PhoneCredential {
  return {
    phone: '+966501234567',
    confirmed: false,
    confirmed_at: null,
    channels: { sms: true, whatsapp: false },
    ...over,
  }
}

/** Renders and waits for the query to settle, so no assertion races the loading skeleton. */
async function mount(data: PhoneCredential) {
  vi.mocked(getPhoneCredential).mockResolvedValue(data)
  // Arabic, because that is the language this product is read in and the copy under test is Arabic.
  renderWithProviders(<PhoneCredentialCard />, { locale: 'ar' })
  await screen.findByTestId('phone-state')
}

describe('AUTH-PHONE-001 — the mobile number in Account security', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(startPhoneConfirmation).mockResolvedValue({
      verification_id: 'v1', delivery_status: 'queued', resend_after: 60, dev_code: null,
    })
    vi.mocked(confirmPhone).mockResolvedValue({ phone: '+966501234567', confirmed: true })
    vi.mocked(revokePhoneCredential).mockResolvedValue({ confirmed: false })
  })

  // ── the honesty rules, which are the whole reason this panel is shaped the way it is ──────────

  /**
   * With no WhatsApp provider wired, WhatsApp is NOT offered.
   *
   * The rule that matters most here: a «send by WhatsApp» control over an unconfigured channel is a
   * button that cannot work, and somebody who pressed it would wait for a message nobody sent. The
   * state is read off `channels.whatsapp` — a fact on the response, not a claim in a document.
   */
  it('does not offer WhatsApp while no provider is configured', async () => {
    await mount(credential({ channels: { sms: true, whatsapp: false } }))

    expect(screen.getByTestId('phone-channel-choice-whatsapp').querySelector('input')).toBeDisabled()
    expect(screen.getByTestId('phone-whatsapp-unavailable')).toBeInTheDocument()
    expect(screen.getByTestId('phone-channel-whatsapp')).toHaveTextContent('بانتظار بيانات الاعتماد')
  })

  /** Configured means selectable — the same fact, read the other way. */
  it('offers WhatsApp once the provider is configured', async () => {
    await mount(credential({ channels: { sms: true, whatsapp: true } }))

    expect(screen.getByTestId('phone-channel-choice-whatsapp').querySelector('input')).toBeEnabled()
    expect(screen.queryByTestId('phone-whatsapp-unavailable')).not.toBeInTheDocument()
    expect(screen.getByTestId('phone-channel-whatsapp')).toHaveTextContent('مفعّلة')
  })

  /**
   * With NOTHING configured, the fact is stated before the button is pressed.
   *
   * The undelivered notice on the code step is true but late — by then somebody is already watching
   * a handset. And both options stay selectable, because disabling every one of them would leave a
   * radio group with no answer and would put the development and E2E path behind credentials that
   * by definition are absent.
   */
  it('warns up front when no channel is configured at all, without locking the flow', async () => {
    await mount(credential({ channels: { sms: false, whatsapp: false } }))

    expect(screen.getByText(/لن يصل الرمز إلى أي جهاز/)).toBeInTheDocument()
    expect(screen.getByTestId('phone-channel-choice-sms').querySelector('input')).toBeEnabled()
    expect(screen.getByTestId('phone-channel-choice-whatsapp').querySelector('input')).toBeEnabled()
    expect(screen.getByTestId('phone-send-code')).toBeEnabled()
  })

  /** With a working channel present, the warning has nothing to say. */
  it('says nothing about missing providers once one channel works', async () => {
    await mount(credential({ channels: { sms: true, whatsapp: false } }))

    expect(screen.queryByText(/لن يصل الرمز إلى أي جهاز/)).not.toBeInTheDocument()
  })

  /** A number nobody proved is drawn as a contact detail, not as a way in. */
  it('says an unproved number cannot sign anybody in', async () => {
    await mount(credential({ confirmed: false }))

    expect(screen.getByTestId('phone-state')).toHaveTextContent('غير موثّق')
    expect(screen.getByText(/لا يصلح لتسجيل الدخول/)).toBeInTheDocument()
  })

  it('shows a proved number as a working credential', async () => {
    await mount(credential({ confirmed: true, confirmed_at: '2026-08-01T10:00:00Z' }))

    expect(screen.getByTestId('phone-state')).toHaveTextContent('موثّق')
    expect(screen.getByText(/يصلح لتسجيل الدخول برمز/)).toBeInTheDocument()
    expect(screen.getByTestId('phone-current')).toHaveTextContent('+966501234567')
  })

  /**
   * `awaiting_provider_credentials` means NOTHING was sent, and the screen says so.
   *
   * «Check your phone» over an unconfigured channel is the product claiming a message it never made.
   */
  it('does not claim a code was sent when no provider is wired', async () => {
    vi.mocked(startPhoneConfirmation).mockResolvedValue({
      verification_id: 'v1', delivery_status: 'awaiting_provider_credentials', resend_after: 60, dev_code: null,
    })
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '0501234567' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    expect(await screen.findByTestId('phone-code-undelivered')).toBeInTheDocument()
  })

  it('stays quiet about delivery when the code really went out', async () => {
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '0501234567' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    await screen.findByTestId('phone-confirm-code')
    expect(screen.queryByTestId('phone-code-undelivered')).not.toBeInTheDocument()
  })

  // ── the flow itself ──────────────────────────────────────────────────────────────────────────

  /** The number is normalised to E.164 before it is sent, so `05…` and `+9665…` are one number. */
  it('sends the canonical number, not what was typed', async () => {
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '0501234567' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    await waitFor(() => expect(startPhoneConfirmation).toHaveBeenCalledWith('+966501234567', 'sms'))
  })

  it('refuses to send a number that is not readable', async () => {
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '12' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    expect(await screen.findByText('أدخل رقم جوال صحيحاً.')).toBeInTheDocument()
    expect(startPhoneConfirmation).not.toHaveBeenCalled()
  })

  it('confirms the number with the code that was entered', async () => {
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '0501234567' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    fireEvent.change(await screen.findByTestId('phone-otp-0'), { target: { value: '123456' } })

    await waitFor(() => expect(confirmPhone).toHaveBeenCalledWith('v1', '123456'))
  })

  /** Withdrawing is only offered where there is something to withdraw. */
  it('offers withdrawal only for a proved number', async () => {
    await mount(credential({ confirmed: false }))
    expect(screen.queryByTestId('phone-revoke')).not.toBeInTheDocument()
  })

  it('withdraws the number as a sign-in method', async () => {
    await mount(credential({ confirmed: true }))

    fireEvent.click(screen.getByTestId('phone-revoke'))

    await waitFor(() => expect(revokePhoneCredential).toHaveBeenCalled())
    expect(await screen.findByText('لم يعد الرقم وسيلة لتسجيل الدخول.')).toBeInTheDocument()
  })

  /** A refusal from the server is shown as the server worded it, not swallowed. */
  it('surfaces the server refusal for a wrong code', async () => {
    vi.mocked(confirmPhone).mockRejectedValue({ response: { status: 422, data: { message: 'الرمز غير صحيح.' } } })
    await mount(credential())

    fireEvent.change(screen.getByTestId('phone-credential-number'), { target: { value: '0501234567' } })
    fireEvent.click(screen.getByTestId('phone-send-code'))

    fireEvent.change(await screen.findByTestId('phone-otp-0'), { target: { value: '000000' } })

    expect(await screen.findByText('الرمز غير صحيح.')).toBeInTheDocument()
  })
})
