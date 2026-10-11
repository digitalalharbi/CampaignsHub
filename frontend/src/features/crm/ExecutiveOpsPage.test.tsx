import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ExecutiveOpsPage } from './ExecutiveOpsPage'
import type { ExecutiveOperationsResult } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchExecutiveOperations: vi.fn(),
}))
import { fetchExecutiveOperations } from './api'

/**
 * EXECUTIVE-OPS-DASHBOARD-001 — the surface for `GET /leads/executive`: spend, leads, cost per lead,
 * the follow-up after arrival, and what needs a person, each drilling into the screen that owns it.
 */
const payload = (over: Partial<ExecutiveOperationsResult> = {}): ExecutiveOperationsResult => ({
  window: { from: '2026-09-12', to: '2026-10-11' },
  spend: { amount: 12000, currency: 'SAR', reason: null },
  leads: { received: 240, unassigned: 6, contacted: 180, not_contacted: 54, qualified: 90, appointments: 30, won: 12, lost: 20, invalid: 6 },
  team: {
    contact_rate: 0.75, qualification_rate: 0.375, appointment_rate: 0.125, win_rate: 0.05,
    first_response: { median_minutes: 42, measured: 170, of: 180 }, overdue: 9, overdue_scope: 'all_open',
  },
  cost_per_lead: { amount: 51.28, currency: 'SAR', reason: null },
  cost_per_qualified_lead: { amount: 133.33, currency: 'SAR', reason: null },
  attention: [
    { kind: 'unassigned_leads', domain: 'leads', count: 6 },
    { kind: 'overdue_follow_up', domain: 'team', count: 9 },
  ],
  ...over,
})

describe('ExecutiveOpsPage', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['leads.view']); useProject.setState({ currentProjectId: 'p1' }) })
  afterEach(() => signOut())

  it('joins spend, leads and the team, and asks the server for the selected project', async () => {
    vi.mocked(fetchExecutiveOperations).mockResolvedValue(payload())
    renderWithProviders(<ExecutiveOpsPage />, { route: '/app/leads/executive', locale: 'en' })

    expect(await screen.findByTestId('executive-spend')).toHaveTextContent('12K SAR')
    expect(screen.getByTestId('executive-leads')).toHaveTextContent('240')
    expect(screen.getByTestId('executive-cpl')).toHaveTextContent('51')
    expect(screen.getByTestId('executive-funnel-won')).toHaveTextContent('12')
    expect(screen.getByTestId('executive-team-first_response')).toHaveTextContent('42')
    expect(screen.getByTestId('executive-attention-overdue_follow_up')).toHaveTextContent('Follow-ups past their date: 9')
    expect(screen.getByTestId('executive-team-link')).toHaveAttribute('href', '/app/leads/workspace')
    expect(vi.mocked(fetchExecutiveOperations)).toHaveBeenCalledWith(expect.objectContaining({ project_id: 'p1' }))
  })

  it('states why a money figure is missing instead of printing a number or zero', async () => {
    vi.mocked(fetchExecutiveOperations).mockResolvedValue(payload({
      spend: { amount: null, currency: null, reason: 'mixed_currency' },
      cost_per_lead: { amount: null, currency: null, reason: 'mixed_currency' },
      cost_per_qualified_lead: { amount: null, currency: null, reason: 'mixed_currency' },
      attention: [{ kind: 'spend_not_comparable', domain: 'money', count: 1 }],
    }))
    renderWithProviders(<ExecutiveOpsPage />, { route: '/app/leads/executive', locale: 'en' })

    const spend = await screen.findByTestId('executive-spend')
    expect(spend).toHaveTextContent('—')
    expect(spend).toHaveTextContent('currencies that do not add up')
    expect(screen.getByTestId('executive-cpl')).not.toHaveTextContent(/0\s?SAR/)
    expect(screen.getByTestId('executive-attention-spend_not_comparable')).toBeInTheDocument()
  })
})
