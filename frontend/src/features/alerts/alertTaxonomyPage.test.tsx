import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AlertsPage } from './AlertsPage'
import type { AlertEvent } from './api'
import type { Option } from '@/components/forms'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

const TAX: Record<string, Option[]> = {}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({ options: TAX[key] ?? [], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listAlertRules: vi.fn(), createAlertRule: vi.fn(), listAlertEvents: vi.fn() }
})
vi.mock('@/features/notifications/api', () => ({ listDeliveries: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn() }))

import { listAlertEvents, listAlertRules } from './api'
import { listDeliveries } from '@/features/notifications/api'
import { listProjects } from '@/features/projects/api'

/**
 * ALERT-TAXONOMY-001 — severity says how loud, and nothing about what.
 *
 * An expiring token and a campaign spending with no results are both «warning». They go to different
 * people and lead to opposite actions, and the page offered no way to tell them apart but reading
 * every message — so a reader scanning twenty alerts was sorting them in their head each time, and
 * could not narrow to the kind they were able to act on.
 *
 * ## Why this is a component test and not a browser one
 *
 * An E2E was written first and had to be deleted: the evaluator raises events on a SCHEDULE, so a
 * spec can create a rule and still find an empty ledger — and the version of it that survived that
 * passed through its own empty-state branch without ever reaching the taxonomy. A guard that cannot
 * reach the thing it is named for is worse than none. Feeding the page real event shapes is the
 * smallest thing that can actually fail.
 */
const event = (over: Partial<AlertEvent>): AlertEvent => ({
  id: 'a', project_id: null, rule_id: 'r1', type: 'budget_risk', entity_type: null, entity_id: null,
  status: 'open', severity: 'warning', context: { title: 'T', message: 'M' }, notification_id: null,
  task_id: null, last_triggered_at: '2026-08-20T09:00:00Z', snoozed_until: null, resolved_at: null,
  created_at: null, ...over,
} as AlertEvent)

const show = (events: AlertEvent[]) => {
  vi.mocked(listAlertEvents).mockResolvedValue({
    events, total: events.length,
    counts: { open: events.length, snoozed: 0, resolved: 0, open_critical: 0 },
  } as never)
  renderWithProviders(<AlertsPage />, { locale: 'en' })
}

describe('an alert says what kind of problem it is', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 } as never)
    vi.mocked(listDeliveries).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  it('files a budget alert under budget, in the reader’s language', async () => {
    show([event({ type: 'budget_risk' })])

    const chip = await screen.findByTestId('alert-category-a')
    expect(chip).toHaveAttribute('data-category', 'budget')
    expect(chip).toHaveTextContent('Budget')
  })

  /**
   * The two that share a severity and share nothing else.
   *
   * This is the case the whole unit exists for: both «warning», one for whoever owns the spend and
   * one for whoever owns the connection.
   */
  it('separates an expiring token from a campaign with no results', async () => {
    show([
      event({ id: 'a', type: 'token_expiry', severity: 'warning' }),
      event({ id: 'b', type: 'no_results', severity: 'warning' }),
    ])

    expect(await screen.findByTestId('alert-category-a')).toHaveAttribute('data-category', 'data')
    expect(screen.getByTestId('alert-category-b')).toHaveAttribute('data-category', 'performance')
  })

  it('tells the reader what to do about it', async () => {
    show([event({ id: 'a', type: 'token_expiry' })])

    expect(await screen.findByTestId('alert-action-a')).toHaveTextContent(/reconnect the account/i)
  })

  /**
   * The action is a property of the TYPE, so it is the same whatever the figures were.
   *
   * Writing it into each event's message would mean re-deciding it per event, and a message that
   * carries an instruction is one a reader stops reading for its evidence.
   */
  it('says the same thing for the same type whatever the numbers', async () => {
    show([
      event({ id: 'a', type: 'roas_drop', context: { title: 'A', message: 'Down 40%' } }),
      event({ id: 'b', type: 'roas_drop', context: { title: 'B', message: 'Down 12%' } }),
    ])

    const first = (await screen.findByTestId('alert-action-a')).textContent
    expect(screen.getByTestId('alert-action-b')).toHaveTextContent(first ?? '')
  })
})

describe('narrowing by kind', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 } as never)
    vi.mocked(listDeliveries).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  it('offers the kinds that are actually in the ledger', async () => {
    show([event({ id: 'a', type: 'budget_risk' }), event({ id: 'b', type: 'sync_failure' })])

    const select = await screen.findByTestId('alerts-category')
    const options = within(select).getAllByRole('option').map((o) => o.textContent)

    expect(options).toContain('Budget')
    expect(options).toContain('Data and integrations')
    /* Never a kind this account has not raised: a control whose every option returns the same list. */
    expect(options).not.toContain('Follow-up')
  })

  it('narrows the list to the chosen kind', async () => {
    show([event({ id: 'a', type: 'budget_risk' }), event({ id: 'b', type: 'sync_failure' })])

    fireEvent.change(await screen.findByTestId('alerts-category'), { target: { value: 'data' } })

    expect(screen.queryByTestId('alert-category-a')).toBeNull()
    expect(screen.getByTestId('alert-category-b')).toBeInTheDocument()
  })

  /** One kind is not a choice — the control is absent rather than present and inert. */
  it('offers no control when every alert is the same kind', async () => {
    show([event({ id: 'a', type: 'budget_risk' }), event({ id: 'b', type: 'budget_risk' })])

    await screen.findByTestId('alert-category-a')
    expect(screen.queryByTestId('alerts-category')).toBeNull()
  })
})
