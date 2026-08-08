import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { NotificationsTab } from './NotificationsTab'
import { renderWithProviders } from '@/test/utils'
import type { NotifPrefs } from '../api'

vi.mock('../api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useNotifPrefs: vi.fn(), useSaveNotifPrefs: vi.fn() }
})

import { useNotifPrefs, useSaveNotifPrefs } from '../api'

const mutateAsync = vi.fn()

/** A payload shaped exactly like the server's, including the effective per-type map. */
function prefs(over: Partial<NotifPrefs> = {}): NotifPrefs {
  return {
    channels: { in_app: true, email: true },
    categories: {},
    types: {
      budget_pace: { email: true, in_app: true, rhythm: 'immediate' },
      daily_digest: { email: false, in_app: false, rhythm: 'immediate' },
      subscription: { email: true, in_app: true, rhythm: 'immediate' },
      password_reset: { email: true, in_app: true, rhythm: 'immediate' },
    },
    catalogue: [
      {
        key: 'budget',
        types: [{ key: 'budget_pace', mandatory: false, rhythms: ['immediate', 'daily', 'weekly'], digest_switch: null, sent_by: 'x' }],
      },
      {
        key: 'reports',
        types: [{ key: 'daily_digest', mandatory: false, rhythms: [], digest_switch: 'daily', sent_by: 'x' }],
      },
      {
        key: 'billing',
        types: [{ key: 'subscription', mandatory: false, rhythms: ['immediate'], digest_switch: null, sent_by: 'x' }],
      },
      {
        key: 'account',
        types: [{ key: 'password_reset', mandatory: true, rhythms: ['immediate'], digest_switch: null, sent_by: 'x' }],
      },
    ],
    quiet_hours: { enabled: false, start: '22:00', end: '08:00' },
    frequency: 'realtime',
    project_ids: null,
    projects: [
      { id: 'p1', name: 'Q3 Launch', client_name: 'Acme' },
      { id: 'p2', name: 'Q3 Launch', client_name: 'Globex' },
    ],
    digests: { daily: false, weekly: false, alerts: false },
    available_digests: ['daily', 'weekly', 'alerts'],
    timezone: 'Asia/Riyadh',
    locale: 'ar',
    digest_hour: 8,
    available_timezones: ['Asia/Riyadh', 'Europe/London'],
    available_categories: [],
    ...over,
  }
}

function open(data: NotifPrefs = prefs()) {
  vi.mocked(useNotifPrefs).mockReturnValue({ data, isLoading: false } as ReturnType<typeof useNotifPrefs>)
  return renderWithProviders(<NotificationsTab />, { locale: 'ar' })
}

/**
 * MAIL-011 — the preferences centre.
 *
 * The assertions are about the three ways this screen could lie: showing a switch for something the
 * person cannot decide, showing a rhythm nothing implements, and silently clearing a setting it does
 * not render.
 */
describe('the notification preferences centre', () => {
  beforeEach(() => {
    mutateAsync.mockReset().mockResolvedValue(undefined)
    vi.mocked(useSaveNotifPrefs).mockReturnValue({ mutateAsync, isPending: false } as unknown as ReturnType<typeof useSaveNotifPrefs>)
  })

  /**
   * A message with no off switch says so, and offers no checkbox to press.
   *
   * A disabled checkbox reads as a bug. The sentence is the difference between «this product is
   * broken» and «this is the only warning you would get that somebody else is in your account».
   */
  it('shows an account message as always sent, with no switch', () => {
    open()

    expect(screen.getByText('تُرسل دائمًا عند الحاجة إليها.')).toBeInTheDocument()
    expect(screen.queryByLabelText(/إعادة تعيين كلمة المرور — بريد/)).not.toBeInTheDocument()
  })

  /** A rhythm select appears only where more than one rhythm exists. */
  it('offers a rhythm for a finding the digest carries, and none for an event', () => {
    open()

    expect(screen.getByLabelText('سرعة استهلاك الميزانية — التوقيت')).toBeInTheDocument()
    expect(screen.queryByLabelText('الاشتراك والفواتير — التوقيت')).not.toBeInTheDocument()
  })

  /**
   * Choosing a rhythm whose summary is switched off says so, in the same breath.
   *
   * Otherwise a person moves a message into a digest they do not receive, and it simply stops
   * arriving — with a setting on screen that appears to explain where it went.
   */
  it('warns when a message is routed into a summary the person does not receive', () => {
    open(prefs({ types: { ...prefs().types, budget_pace: { email: true, in_app: true, rhythm: 'daily' } } }))

    expect(screen.getByText(/لن تصلك هذه الرسالة لأن الملخص اليومي غير مفعّل/)).toBeInTheDocument()
  })

  /** The digest row writes to the digest opt-in, not to a per-type switch. */
  it('sends the daily summary as a digest opt-in when it is ticked', async () => {
    open()

    fireEvent.click(screen.getByLabelText('الملخص اليومي'))
    fireEvent.click(screen.getByText('حفظ التفضيلات'))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalled())
    const body = mutateAsync.mock.calls[0]?.[0]
    expect(body.digests.daily).toBe(true)
    expect(body.types).not.toHaveProperty('daily_digest')
    // And nothing mandatory is ever submitted, because nothing here may switch it.
    expect(body.types).not.toHaveProperty('password_reset')
  })

  /**
   * **The regression.** Every setting this screen holds is submitted, not a fixed subset.
   *
   * The screen this replaced PUT a body without `digests`, `timezone`, `locale` or `digest_hour`,
   * and the server wrote them from defaults anyway — so saving a checkbox cleared a digest chosen on
   * the other screen.
   */
  it('submits the timing and language settings it renders, so saving cannot clear them', async () => {
    open(prefs({ digests: { daily: true, weekly: false, alerts: true }, timezone: 'Europe/London', digest_hour: 6, locale: 'en' }))

    fireEvent.click(screen.getByText('حفظ التفضيلات'))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalled())
    const body = mutateAsync.mock.calls[0]?.[0]
    expect(body.digests).toEqual({ daily: true, weekly: false, alerts: true })
    expect(body.timezone).toBe('Europe/London')
    expect(body.digest_hour).toBe(6)
    expect(body.locale).toBe('en')
  })

  /** Two clients each own a «Q3 Launch»; an unqualified list would offer the same words twice. */
  it('names the client beside each project', () => {
    open()

    fireEvent.click(screen.getByLabelText('كل المشاريع التي أصل إليها'))

    expect(screen.getByLabelText('Acme · Q3 Launch')).toBeInTheDocument()
    expect(screen.getByLabelText('Globex · Q3 Launch')).toBeInTheDocument()
  })

  /** Email off is off, and the screen stops pretending a per-message switch would help. */
  it('disables the per-message email boxes when email is switched off entirely', () => {
    open(prefs({ channels: { in_app: true, email: false } }))

    expect(screen.getByLabelText('سرعة استهلاك الميزانية — بريد')).toBeDisabled()
    expect(screen.getByText(/البريد مغلق كليًا/)).toBeInTheDocument()
  })
})
