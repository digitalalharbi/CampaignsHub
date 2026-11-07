import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { NotifPrefs } from '../api'

const digestNow = vi.fn()
const sampleAlert = vi.fn()

vi.mock('../api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    // One object for every render: the tab copies `data` into state in an effect, so a fresh object each call loops forever.
    useNotifPrefs: () => ({ data: fixture, isLoading: false }),
    useSaveNotifPrefs: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useSendDigestNow: () => ({ mutateAsync: digestNow }),
    useSendSampleAlert: () => ({ mutateAsync: sampleAlert }),
    useTestChannel: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useWhatsAppOptIn: () => ({ start: { mutateAsync: vi.fn() }, confirm: { mutateAsync: vi.fn() }, withdraw: { mutate: vi.fn() } }),
  }
})

import { NotificationsTab } from './NotificationsTab'

function prefs(): NotifPrefs {
  return {
    channels: { in_app: true, email: true },
    categories: {},
    types: {},
    catalogue: [{
      key: 'reports',
      types: ['daily_digest', 'weekly_digest', 'monthly_digest'].map((key, i) => ({
        key, mandatory: false, rhythms: [], digest_switch: (['daily', 'weekly', 'monthly'] as const)[i], sent_by: 'x',
        channels: { in_app: false, email: 'digest' as const, whatsapp: 'never' as const },
      })),
    }],
    quiet_hours: { enabled: false, start: '22:00', end: '08:00' },
    frequency: 'realtime', project_ids: null, projects: [],
    digests: { daily: true, weekly: true, monthly: false, alerts: true, recommendations: true },
    available_digests: ['daily', 'weekly', 'monthly', 'alerts'],
    timezone: 'Asia/Riyadh', locale: 'ar', digest_hour: 8, digest_weekday: 1, digest_monthday: 1,
    available_timezones: ['Asia/Riyadh'], available_categories: [],
  } as unknown as NotifPrefs
}

const fixture = prefs()

/**
 * NOTIFICATION-ENROLMENT-001 — Owner: «هل هي فعّالة أو لا؟». The settings screen answers by doing it: one chip per digest
 * kind and one for a sample alert, each calling its own endpoint for THIS person and printing one short word.
 */
describe('the notification settings send-now chips', () => {
  beforeEach(() => { vi.clearAllMocks() })

  it('has one «send me now» chip per digest kind, each asking for its own kind', async () => {
    digestNow.mockResolvedValue({ kind: 'daily', state: 'sent', reason: null })
    renderWithProviders(<NotificationsTab />, { locale: 'ar' })

    for (const kind of ['daily', 'weekly', 'monthly']) {
      expect(screen.getByTestId(`send-now-${kind}`)).toHaveTextContent('أرسل لي الآن')
    }

    fireEvent.click(screen.getByTestId('send-now-weekly'))
    await waitFor(() => expect(screen.getByTestId('send-now-weekly-result')).toHaveTextContent('تم الإرسال'))
    expect(digestNow).toHaveBeenCalledTimes(1)
    expect(digestNow).toHaveBeenCalledWith('weekly')
    expect(screen.getByTestId('send-now-daily-result')).toHaveTextContent('')
  })

  it('prints the server answer in English, with its reason when it has one', async () => {
    digestNow.mockResolvedValue({ kind: 'monthly', state: 'skipped', reason: 'no_activity' })
    renderWithProviders(<NotificationsTab />, { locale: 'en' })

    expect(screen.getByTestId('send-now-monthly')).toHaveTextContent('Send me now')
    fireEvent.click(screen.getByTestId('send-now-monthly'))
    await waitFor(() => expect(screen.getByTestId('send-now-monthly-result')).toHaveTextContent('No activity in the period'))
  })

  it('says «throttled» for a 429 instead of failing silently', async () => {
    digestNow.mockRejectedValue({ response: { status: 429 } })
    renderWithProviders(<NotificationsTab />, { locale: 'ar' })

    fireEvent.click(screen.getByTestId('send-now-daily'))
    await waitFor(() => expect(screen.getByTestId('send-now-daily-result')).toHaveTextContent('حاول بعد دقيقة'))
  })

  it('has a sample-alert chip that says plainly when the person is not enrolled', async () => {
    sampleAlert.mockResolvedValue({ state: 'not_enrolled' })
    renderWithProviders(<NotificationsTab />, { locale: 'ar' })

    expect(screen.getByTestId('send-sample-alert')).toHaveTextContent('أرسل تنبيهًا تجريبيًا')
    fireEvent.click(screen.getByTestId('send-sample-alert'))
    await waitFor(() => expect(screen.getByTestId('send-sample-alert-result')).toHaveTextContent('احفظ تفضيلاتك أولًا'))
  })
})
