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
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn() }))

import { createAlertRule, listAlertEvents, listAlertRules } from './api'
import { listProjects } from '@/features/projects/api'
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

/**
 * ANALYTICS-FILTER-TRUTH-001 — the alerts leg of the propagation clause.
 *
 * This ledger is workspace-scoped ON PURPOSE — `UnifiedFigureConsistencyTest` records the decision,
 * and the page's siblings (preferences, the delivery log) are account-level surfaces too. So the
 * defect was never the default. It was the SILENCE: an agency holding ten clients read ten clients'
 * alerts on one screen with nothing on any row to say whose, under an empty state that promised
 * «no active rule has fired for this project».
 *
 * Narrowing is a control the reader operates, and it narrows the BACKEND. Filtering the array
 * already fetched would be the defect this page was fixed for once before: the list is capped at 200
 * and the badges are counted over the whole ledger, so one client's page would carry every client's
 * count. Both halves are held here — the project travels in the REQUEST and in the query KEY.
 */
describe('AlertsPage — the queue says whose alert each row is, and can be narrowed to one', () => {
  const event = (id: string, projectId: string | null): AlertEvent => ({
    id, project_id: projectId, rule_id: 'r1', type: 'sync_failure', entity_type: null, entity_id: null,
    status: 'open', severity: 'warning', context: null, notification_id: null, task_id: null,
    last_triggered_at: '2026-08-20T09:00:00Z', snoozed_until: null, resolved_at: null, created_at: null,
  })

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 })
    vi.mocked(listProjects).mockResolvedValue([
      { id: 'p1', name: 'Henka' }, { id: 'p2', name: 'Aldairman' },
    ] as never)
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('mine', 'p1'), event('wide', null), event('theirs', 'p2')],
      total: 3,
      counts: { open: 3, snoozed: 0, resolved: 0, open_critical: 0 },
    })
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  /** Every project by default — the recorded decision — and that is what the ledger is asked for. */
  it('asks for the whole account until the reader narrows it', async () => {
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    await waitFor(() => expect(listAlertEvents).toHaveBeenCalledWith(undefined, null))
  })

  /*
   * The row that is deliberately NOT about one client says so, and the ones that are name theirs.
   *
   * A token expiring belongs to a connection, so it has no project and the server keeps it through
   * any narrowing. Unlabelled it reads as a leak the filter missed — and a reader who concludes the
   * filter is broken stops trusting the rows that are correct.
   */
  it('names the project on every row, and marks the account-wide one as account-wide', async () => {
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    expect(await screen.findByTestId('alert-scope-mine')).toHaveTextContent('Henka')
    expect(screen.getByTestId('alert-scope-theirs')).toHaveTextContent('Aldairman')
    expect(screen.getByTestId('alert-scope-wide')).toHaveTextContent('Account-wide')
  })

  it('narrows the ledger itself when a project is chosen, not the rows already fetched', async () => {
    renderWithProviders(<AlertsPage />, { locale: 'en' })
    await waitFor(() => expect(listAlertEvents).toHaveBeenCalledWith(undefined, null))

    await screen.findByRole('option', { name: 'Aldairman' })
    fireEvent.change(screen.getByTestId('alerts-project'), { target: { value: 'p2' } })

    await waitFor(() => expect(listAlertEvents).toHaveBeenCalledWith(undefined, 'p2'))
  })

  /*
   * Two scopes, two cache entries.
   *
   * Sending the project while keying on a constant is the `useEntities` defect again: React Query
   * would answer the second scope out of the first one's cache and the narrowing would look like it
   * had worked. Going BACK is where that shows — a key that does not carry the scope serves the
   * narrowed page under «all projects».
   */
  it('keeps the two scopes apart in the cache', async () => {
    renderWithProviders(<AlertsPage />, { locale: 'en' })
    // The options arrive with the project list; changing the select before then sets nothing.
    await screen.findByRole('option', { name: 'Aldairman' })
    const select = screen.getByTestId('alerts-project')

    fireEvent.change(select, { target: { value: 'p2' } })
    await waitFor(() => expect(listAlertEvents).toHaveBeenCalledWith(undefined, 'p2'))

    vi.mocked(listAlertEvents).mockClear()
    fireEvent.change(select, { target: { value: 'p1' } })

    await waitFor(() => expect(listAlertEvents).toHaveBeenCalledWith(undefined, 'p1'))
  })
})
