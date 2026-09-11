import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
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
 * ALERTS-TRUTH-001 — the chips beside a budget alert carry the unit, and the reason.
 *
 * They printed «الحد: 1000» — a bare figure on the screen whose whole subject is how much of a
 * budget is gone — while the event's own context named the currency right beside it. And a budget
 * the money contract cannot compare to its spend now raises its own alert; a chip strip that simply
 * omitted the percentage would read as a missing figure rather than a refused one.
 */
const event = (context: Record<string, unknown>): AlertEvent => ({
  id: 'a', project_id: null, rule_id: 'r1', type: 'budget_risk', entity_type: null, entity_id: null,
  status: 'open', severity: 'warning', context, notification_id: null, task_id: null,
  last_triggered_at: '2026-08-20T09:00:00Z', snoozed_until: null, resolved_at: null, created_at: null,
} as AlertEvent)

const show = (context: Record<string, unknown>) => {
  vi.mocked(listAlertEvents).mockResolvedValue({
    events: [event(context)], total: 1,
    counts: { open: 1, snoozed: 0, resolved: 0, open_critical: 0 },
  } as never)
  renderWithProviders(<AlertsPage />)
}

describe('the chips beside a budget alert', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 } as never)
    vi.mocked(listDeliveries).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
    signInWith(['alerts.view', 'alerts.manage'])
  })
  afterEach(() => signOut())

  it('states the currency the budget is in', async () => {
    show({ basis_class: 'measured', spend: 950, budget: 1000, ratio: 0.95, budget_currency: 'SAR' })

    expect(await screen.findByText(/1000 SAR/)).toBeInTheDocument()
  })

  it('says why there is no percentage when the budget cannot be compared', async () => {
    show({ basis_class: 'unmeasurable', pacing_basis: 'currency_mismatch', budget: 1000, budget_currency: 'USD' })

    expect(await screen.findByText(/currency_mismatch/)).toBeInTheDocument()
  })
})
