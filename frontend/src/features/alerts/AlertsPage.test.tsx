import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AlertsPage } from './AlertsPage'
import type { Option } from '@/components/forms'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

// The alert rule form's type / severity / channel controls must be fed by the taxonomy engine.
const TAX: Record<string, Option[]> = {
  'alert.type': [
    { value: 'budget_risk', label_en: 'Budget risk', label_ar: 'خطر الميزانية' },
    { value: 'roas_drop', label_en: 'ROAS drop', label_ar: 'انخفاض ROAS' },
  ],
  'alert.severity': [
    { value: 'info', label_en: 'Info', label_ar: 'معلومة' },
    { value: 'warning', label_en: 'Warning', label_ar: 'تحذير' },
    { value: 'critical', label_en: 'Critical', label_ar: 'حرِج' },
  ],
  'alert.channel': [
    { value: 'in_app', label_en: 'In-app', label_ar: 'داخل التطبيق' },
    { value: 'email', label_en: 'Email', label_ar: 'البريد' },
    { value: 'whatsapp', label_en: 'WhatsApp', label_ar: 'واتساب' },
  ],
}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({
    options: TAX[key] ?? [],
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listAlertRules: vi.fn(), createAlertRule: vi.fn(), listAlertEvents: vi.fn() }
})
vi.mock('@/features/notifications/api', () => ({ listDeliveries: vi.fn() }))

import { createAlertRule, listAlertEvents, listAlertRules } from './api'
import type { AlertEvent } from './api'

async function openRulesTab() {
  fireEvent.click(screen.getByRole('button', { name: /Rules/i }))
  return screen.findByRole('combobox', { name: 'Type' })
}

describe('AlertsPage — engine-fed rule form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 })
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  it('feeds type / severity / channels from the taxonomy engine', async () => {
    renderWithProviders(<AlertsPage />, { locale: 'en' })
    const typeSelect = await openRulesTab()

    // Labels for the default keys come only from the mocked engine hook.
    expect(typeSelect).toHaveTextContent('Budget risk')
    expect(screen.getByRole('combobox', { name: 'Severity' })).toHaveTextContent('Warning')
    // Default channels render as chips fed by alert.channel.
    const channels = screen.getByRole('combobox', { name: 'Channels' })
    expect(channels).toHaveTextContent('In-app')
    expect(channels).toHaveTextContent('Email')
  })

  it('submits the engine option KEYS unchanged (no 422)', async () => {
    vi.mocked(createAlertRule).mockResolvedValue({} as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })
    await openRulesTab()

    fireEvent.change(screen.getByLabelText('Rule name'), { target: { value: 'Guard budget' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(createAlertRule).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Guard budget',
          type: 'budget_risk',
          severity: 'warning',
          channels: ['in_app', 'email'],
        }),
      ),
    )
  })

  it('surfaces a server validation error in the ErrorSummary and focuses the field', async () => {
    vi.mocked(createAlertRule).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { name: ['The name has already been taken.'] } } },
    })
    renderWithProviders(<AlertsPage />, { locale: 'en' })
    await openRulesTab()

    fireEvent.change(screen.getByLabelText('Rule name'), { target: { value: 'Dup' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The name has already been taken.')
    fireEvent.click(screen.getByRole('button', { name: 'The name has already been taken.' }))
    expect(screen.getByLabelText('Rule name')).toHaveFocus()
  })
})

/**
 * The badges over an alert queue are the one thing on the page nobody re-reads.
 *
 * They used to be computed by filtering the array this page had just fetched — which is the SERVER'S
 * CAPPED PAGE, not the ledger. A workspace past the cap was shown «3 open» while forty alerts were
 * open, and nothing on screen admitted that a number had been derived from a truncated list. The
 * counts now arrive from the server, computed over everything.
 */
describe('AlertsPage — the queue counts what exists, not what fitted', () => {
  const event = (id: string, status: AlertEvent['status'], severity: AlertEvent['severity']): AlertEvent => ({
    id, project_id: null, rule_id: 'r1', type: 'sync_failure', entity_type: null, entity_id: null,
    status, severity, context: null, notification_id: null, task_id: null,
    last_triggered_at: '2026-08-20T09:00:00Z', snoozed_until: null, resolved_at: null, created_at: null,
  })

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 })
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  it('shows the ledger-wide counts, not the counts of the page it was sent', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('a', 'open', 'critical'), event('b', 'open', 'warning'), event('c', 'open', 'info')],
      total: 253,
      counts: { open: 40, snoozed: 3, resolved: 210, open_critical: 5 },
    })

    renderWithProviders(<AlertsPage />, { locale: 'en' })

    const card = (which: string) => screen.getByTestId(`alert-summary-${which}`).textContent ?? ''

    // Three rows were returned; the badges must describe the forty that exist.
    await waitFor(() => expect(card('open')).toContain('40'))
    expect(card('critical')).toContain('5')
    expect(card('resolved')).toContain('210')
    expect(card('snoozed')).toContain('3')
  })

  it('says the list is capped, and which end was cut', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('a', 'open', 'critical')],
      total: 253,
      counts: { open: 40, snoozed: 3, resolved: 210, open_critical: 5 },
    })

    renderWithProviders(<AlertsPage />, { locale: 'en' })

    const notice = await screen.findByTestId('alert-events-capped')
    expect(notice).toHaveTextContent('1 of 253')
    expect(notice).toHaveTextContent(/oldest resolved/i)
  })

  it('says nothing about a cap when nothing was capped', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('a', 'open', 'critical'), event('b', 'open', 'warning')],
      total: 2,
      counts: { open: 2, snoozed: 0, resolved: 0, open_critical: 1 },
    })

    renderWithProviders(<AlertsPage />, { locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('alert-summary-open').textContent).toContain('2'))
    expect(screen.queryByTestId('alert-events-capped')).toBeNull()
  })
})
