import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { PlatformAlertsPanel } from './PlatformAlertsPanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchPlatformAlerts: vi.fn(), acknowledgePlatformAlert: vi.fn() }
})

import { acknowledgePlatformAlert, fetchPlatformAlerts, type PlatformAlertList, type PlatformAlertRow } from './api'

const abandoned: PlatformAlertRow = {
  id: '0b4f6a52-6f0e-4a8f-9c55-1f7b2d0c9a11',
  kind: 'checkout_abandoned',
  severity: 'warning',
  card: {
    severity: 'warning',
    title: 'عميل وصل للدفع وما كمّل',
    context: 'Ward Co',
    line: 'طلب الدفع الأربعاء 11 نوفمبر 14:05 وما وصل تأكيد من ميسر خلال 30 دقيقة.',
    more: ['الباقة: النمو (شهري) · المبلغ: 199.00 SAR · اشتراك', 'آخر خطوة وصلها: صفحة الدفع'],
    steps: 'تواصل معه ← أعد إرسال رابط الدفع',
    viz: { type: 'steps', steps: [
      { label: 'التسجيل', state: 'ok' }, { label: 'التحقق', state: 'ok' }, { label: 'صفحة الدفع', state: 'watch' }, { label: 'الاشتراك', state: 'none' },
    ] },
    links: [{ label: 'التسجيلات', path: '/admin/registrations' }],
  },
  occurrences: 1,
  first_seen_at: '2026-11-11T11:36:00Z',
  last_seen_at: '2026-11-11T11:36:00Z',
  emailed_at: '2026-11-11T11:36:00Z',
  resolved_at: null,
  acknowledged_at: null,
  contact: { email: 'ward@customer.test', phone: '+966500000012' },
}

const blocking: PlatformAlertRow = {
  ...abandoned,
  id: '7d1e2c33-1a2b-4c5d-8e9f-0a1b2c3d4e5f',
  kind: 'customer_blocking_error',
  card: {
    ...abandoned.card,
    title: 'رمز التحقق ما وصل لعملاء',
    context: 'الأربعاء 11 نوفمبر',
    line: '2 عميلان يوم الأربعاء 11 نوفمبر، آخرهم Beta الساعة 14:05 بتوقيت الرياض.',
    more: ['Beta · الجوال · الحالة «failed» · الأربعاء 11 نوفمبر 14:05'],
    steps: 'تواصل معهم ← أعد إرسال الرمز',
    viz: { type: 'count', n: 2, blocks: 2, more: false, label: 'عميلان', state: 'watch' },
    links: [],
  },
  occurrences: 2,
  contact: null,
}

const list: PlatformAlertList = {
  alerts: [abandoned, blocking],
  counts: { open: 2, critical: 0, by_kind: { checkout_abandoned: 1, customer_blocking_error: 1 } },
  settlement_check: 'BLOCKED_EXTERNAL_CREDENTIALS',
}

/** PLATFORM-PAYMENT-ALERTS-001 — the owner reads each stuck customer as a drawn card with its time and next step. */
describe('PlatformAlertsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPlatformAlerts).mockResolvedValue(list)
    vi.mocked(acknowledgePlatformAlert).mockResolvedValue({ alert: { ...abandoned, acknowledged_at: '2026-11-11T12:00:00Z' } })
  })

  it('draws every card, states its time and gives the next step in order', async () => {
    renderWithProviders(<PlatformAlertsPanel />, { locale: 'ar', route: `/admin/requests?alert=${abandoned.id}` })

    const card = await screen.findByTestId(`platform-alert-${abandoned.id}`)
    expect(card).toHaveTextContent('عميل وصل للدفع وما كمّل')
    expect(within(card).getByTestId('platform-alert-line')).toHaveTextContent('الأربعاء 11 نوفمبر 14:05')
    expect(within(card).getByTestId('platform-alert-viz')).toHaveAttribute('data-viz', 'steps')
    expect(within(card).getAllByRole('listitem').filter((li) => li.hasAttribute('data-step-state')).map((li) => li.getAttribute('data-step-state')))
      .toEqual(['ok', 'ok', 'watch', 'none'])
    expect(within(card).getByTestId('platform-alert-steps')).toHaveTextContent('1 تواصل معه')
    expect(within(card).getByTestId('platform-alert-steps')).toHaveTextContent('2 أعد إرسال رابط الدفع')
    expect(within(card).getByTestId('platform-alert-contact')).toHaveTextContent('ward@customer.test')
    expect(card.className).toContain('ring-2')

    const group = screen.getByTestId(`platform-alert-${blocking.id}`)
    expect(within(group).getByTestId('platform-alert-viz')).toHaveAttribute('data-viz', 'count')
    expect(within(group).getByTestId('platform-alert-viz')).toHaveTextContent('2 عميلان')

    expect(screen.getByTestId('platform-alerts-count-open')).toHaveTextContent('2')
    expect(screen.getByTestId('platform-alerts-settlement')).toHaveTextContent('ما فيه مفتاح ميسر محفوظ')
    expect(document.body.textContent ?? '').not.toMatch(/[٠-٩]/)
  })

  it('marks an alert handled through the server and asks again', async () => {
    renderWithProviders(<PlatformAlertsPanel />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId(`platform-alert-ack-${abandoned.id}`))

    await waitFor(() => expect(acknowledgePlatformAlert).toHaveBeenCalledWith(abandoned.id, true))
    await waitFor(() => expect(fetchPlatformAlerts).toHaveBeenCalledTimes(2))
  })

  it('says plainly when Moyasar refused the key for settlements', async () => {
    vi.mocked(fetchPlatformAlerts).mockResolvedValue({ ...list, settlement_check: 'refused_by_gateway' })
    renderWithProviders(<PlatformAlertsPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('platform-alerts-settlement')).toHaveTextContent('ميسر رفضت المفتاح لقراءة التسويات')
  })

  it('says once that Moyasar has no settlements yet, instead of an alert per payment', async () => {
    vi.mocked(fetchPlatformAlerts).mockResolvedValue({ ...list, alerts: [], settlement_check: 'no_settlement_history' })
    renderWithProviders(<PlatformAlertsPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('platform-alerts-settlement')).toHaveTextContent('غير متاح — لا توجد تسويات في ميسر')
  })

  it('says so plainly when nobody is stuck', async () => {
    vi.mocked(fetchPlatformAlerts).mockResolvedValue({ alerts: [], counts: { open: 0, critical: 0, by_kind: {} }, settlement_check: 'available' })
    renderWithProviders(<PlatformAlertsPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('platform-alerts-empty')).toHaveTextContent('ما فيه عميل متعثر الحين.')
    expect(screen.queryByTestId('platform-alerts-settlement')).toBeNull()
  })
})
