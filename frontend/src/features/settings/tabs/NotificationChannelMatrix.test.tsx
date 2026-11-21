import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { NotificationsTab } from './NotificationsTab'
import { renderWithProviders } from '@/test/utils'
import type { NotifPrefs } from '../api'

vi.mock('../api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useNotifPrefs: vi.fn(), useSaveNotifPrefs: vi.fn(), useTestChannel: vi.fn(), useWhatsAppOptIn: vi.fn() }
})

import { useNotifPrefs, useSaveNotifPrefs, useTestChannel, useWhatsAppOptIn } from '../api'

const save = vi.fn()
const test = vi.fn()

/** The shape the server sends for NOTIFICATION-CHANNELS-001: per-type channels, per-type whatsapp, and the person's own opt-in state. */
function prefs(over: Partial<NotifPrefs> = {}): NotifPrefs {
  return {
    channels: { in_app: true, email: true },
    categories: {},
    types: {
      automation_rule: { email: true, in_app: true, rhythm: 'immediate', whatsapp: false },
      falling_rate: { email: false, in_app: true, rhythm: 'immediate', whatsapp: false },
      daily_digest: { email: false, in_app: false, rhythm: 'immediate', whatsapp: false },
      password_reset: { email: true, in_app: true, rhythm: 'immediate', whatsapp: false },
      security_alert: { email: true, in_app: true, rhythm: 'immediate', whatsapp: false },
    },
    catalogue: [
      { key: 'operations', types: [{ key: 'automation_rule', mandatory: false, rhythms: ['immediate'], digest_switch: null, sent_by: 'x', channels: { in_app: true, email: 'immediate', whatsapp: 'planned' } }] },
      { key: 'performance', types: [{ key: 'falling_rate', mandatory: false, rhythms: ['immediate', 'daily'], digest_switch: null, sent_by: 'x', channels: { in_app: false, email: 'immediate', whatsapp: 'never' } }] },
      { key: 'reports', types: [{ key: 'daily_digest', mandatory: false, rhythms: [], digest_switch: 'daily', sent_by: 'x', channels: { in_app: false, email: 'digest', whatsapp: 'never' } }] },
      {
        key: 'account',
        types: [
          { key: 'password_reset', mandatory: true, rhythms: ['immediate'], digest_switch: null, sent_by: 'x', channels: { in_app: false, email: 'mandatory', whatsapp: 'never' } },
          { key: 'security_alert', mandatory: true, rhythms: ['immediate'], digest_switch: null, sent_by: 'x', channels: { in_app: false, email: 'mandatory', whatsapp: 'planned' } },
        ],
      },
    ],
    whatsapp: { provider_configured: false, opted_in: false, opted_out: false, number_hint: null },
    quiet_hours: { enabled: false, start: '22:00', end: '08:00' },
    frequency: 'realtime',
    project_ids: null,
    projects: [],
    digests: { daily: false, weekly: false, monthly: false, alerts: false, recommendations: false },
    available_digests: ['daily', 'weekly', 'monthly', 'alerts'],
    digest_weekday: 1,
    digest_monthday: 1,
    timezone: 'Asia/Riyadh',
    locale: 'ar',
    digest_hour: 8,
    available_timezones: ['Asia/Riyadh'],
    available_categories: [],
    ...over,
  }
}

function open(data: NotifPrefs = prefs(), locale: 'ar' | 'en' = 'ar') {
  vi.mocked(useNotifPrefs).mockReturnValue({ data, isLoading: false } as ReturnType<typeof useNotifPrefs>)
  return renderWithProviders(<NotificationsTab />, { locale })
}

/**
 * NOTIFICATION-CHANNELS-001 — the matrix: «داخل النظام», «بريد», «واتساب», one chip per cell.
 */
describe('the notification channel matrix', () => {
  beforeEach(() => {
    save.mockReset().mockResolvedValue(undefined)
    test.mockReset().mockResolvedValue({ channel: 'in_app', state: 'sent' })
    vi.mocked(useSaveNotifPrefs).mockReturnValue({ mutateAsync: save, isPending: false } as unknown as ReturnType<typeof useSaveNotifPrefs>)
    vi.mocked(useTestChannel).mockReturnValue({ mutateAsync: test, isPending: false } as unknown as ReturnType<typeof useTestChannel>)
    vi.mocked(useWhatsAppOptIn).mockReturnValue({
      start: { mutateAsync: vi.fn(), isPending: false }, confirm: { mutateAsync: vi.fn(), isPending: false }, withdraw: { mutate: vi.fn(), isPending: false },
    } as unknown as ReturnType<typeof useWhatsAppOptIn>)
  })

  it('has the three columns the Owner named', () => {
    open()

    for (const name of ['داخل النظام', 'بريد', 'واتساب']) {
      expect(screen.getAllByRole('columnheader', { name }).length).toBeGreaterThan(0)
    }
  })

  it('shows a chip that is ON for a message that reaches the inbox, and switches it OFF', async () => {
    open()

    const email = screen.getByRole('switch', { name: 'تطابق قاعدة أتمتة — بريد' })
    expect(email).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(email)
    expect(email).toHaveAttribute('aria-checked', 'false')

    fireEvent.click(screen.getByText('حفظ التفضيلات'))
    await waitFor(() => expect(save).toHaveBeenCalled())
    expect(save.mock.calls[0]?.[0].types.automation_rule.email).toBe(false)
  })

  it('shows an OFF chip as off, and a dash where the channel does not exist', () => {
    open()

    expect(screen.getByRole('switch', { name: 'انخفاض معدل التحويل — بريد' })).toHaveAttribute('aria-checked', 'false')
    // No bell item for an analysis note: a dash, not a switch that controls nothing.
    expect(screen.queryByRole('switch', { name: 'انخفاض معدل التحويل — داخل النظام' })).not.toBeInTheDocument()
  })

  it('locks an account message in every channel it has, and never offers to switch it', () => {
    open()

    expect(screen.getByRole('img', { name: /إعادة تعيين كلمة المرور — بريد: دائم/ })).toBeInTheDocument()
    expect(screen.queryByRole('switch', { name: /إعادة تعيين كلمة المرور/ })).not.toBeInTheDocument()
  })

  it('says «قريبًا» in the WhatsApp column until a provider is configured, and is not pressable', () => {
    open()

    const cell = screen.getByTestId('whatsapp-soon-automation_rule')
    expect(cell).toHaveTextContent('قريبًا')
    expect(cell.tagName).not.toBe('BUTTON')
    // Never for a type the policy does not send there.
    expect(screen.queryByTestId('whatsapp-soon-password_reset')).not.toBeInTheDocument()
    expect(screen.getByTestId('whatsapp-soon')).toHaveTextContent('قريبًا')
  })

  it('is disabled for a person with no opt-in even when the provider exists', () => {
    open(prefs({ whatsapp: { provider_configured: true, opted_in: false, opted_out: false, number_hint: null } }))

    expect(screen.getByTestId('whatsapp-automation_rule')).toBeDisabled()
    expect(screen.queryByTestId('whatsapp-soon')).not.toBeInTheDocument()
  })

  it('becomes a real switch once the person has an opt-in, and submits the choice', async () => {
    open(prefs({ whatsapp: { provider_configured: true, opted_in: true, opted_out: false, number_hint: '•••••4567' } }))

    const chip = screen.getByTestId('whatsapp-automation_rule')
    expect(chip).toBeEnabled()
    expect(chip).toHaveAttribute('aria-checked', 'false')
    fireEvent.click(chip)
    expect(chip).toHaveAttribute('aria-checked', 'true')
    // A locked security message can still gain WhatsApp: a second place to be told, never a way to be told less.
    expect(screen.getByTestId('whatsapp-security_alert')).toBeEnabled()

    fireEvent.click(screen.getByText('حفظ التفضيلات'))
    await waitFor(() => expect(save).toHaveBeenCalled())
    expect(save.mock.calls[0]?.[0].types.automation_rule.whatsapp).toBe(true)
    expect(screen.getByTestId('whatsapp-number')).toHaveTextContent('4567')
  })

  it('has a test button per channel, runs it, and prints the answer in short words', async () => {
    open()

    fireEvent.click(screen.getByTestId('test-in_app'))
    await waitFor(() => expect(test).toHaveBeenCalledWith('in_app'))
    await waitFor(() => expect(screen.getByTestId('test-result-in_app')).toHaveTextContent('تم الإرسال'))

    test.mockResolvedValue({ channel: 'email', state: 'sandbox' })
    fireEvent.click(screen.getByTestId('test-email'))
    await waitFor(() => expect(test).toHaveBeenCalledWith('email'))
    await waitFor(() => expect(screen.getByTestId('test-result-email')).toHaveTextContent('تجريبي'))
  })

  it('keeps the WhatsApp test disabled until the channel can actually send', () => {
    open()
    expect(screen.getByTestId('test-whatsapp')).toBeDisabled()
  })

  it('enables the WhatsApp test for an opted-in person', () => {
    open(prefs({ whatsapp: { provider_configured: true, opted_in: true, opted_out: false, number_hint: '•••••4567' } }))
    expect(screen.getByTestId('test-whatsapp')).toBeEnabled()
  })

  it('reads in English with the same cells', () => {
    open(prefs(), 'en')

    const panel = screen.getByTestId('channels-panel')
    expect(within(panel).getAllByText('Test').length).toBe(3)
    expect(screen.getByTestId('whatsapp-soon')).toHaveTextContent('Soon')
  })
})
