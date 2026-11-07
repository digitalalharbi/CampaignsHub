import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { PlanMigrationPage } from './PlanMigrationPage'
import { renderWithProviders } from '@/test/utils'
import type { MigrationWorkspace } from './api'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchPlanMigration: vi.fn(), schedulePlanMigration: vi.fn(), withdrawPlanMigration: vi.fn(), bulkPlanMigration: vi.fn() }
})

import { bulkPlanMigration, fetchPlanMigration, schedulePlanMigration } from './api'

const metrics = (projects: number, cap: number) => [
  { metric: 'projects' as const, usage: projects, current_limit: 25, new_limit: cap, fits: projects <= cap },
  { metric: 'clients' as const, usage: 1, current_limit: 5, new_limit: cap, fits: true },
  { metric: 'team_members' as const, usage: 2, current_limit: 15, new_limit: 3, fits: true },
  { metric: 'ad_accounts' as const, usage: 4, current_limit: 25, new_limit: 20, fits: true },
]

const legacy: MigrationWorkspace = {
  tenant_id: 't1', tenant_name: 'Legacy Co', tenant_slug: 'legacy-co', status: 'active', current_period_end: '2026-11-13T00:00:00Z',
  from: { plan: 'growth', plan_name: 'Growth', amount: '49.00', currency: 'USD', interval: 'monthly' },
  suggested: { plan: 'team', plan_name: 'Team', amount: '699.00', currency: 'SAR', interval: 'monthly', fits: true, metrics: metrics(3, 5) },
  scheduled: null,
}

const outgrown: MigrationWorkspace = {
  ...legacy, tenant_id: 't2', tenant_name: 'Big Co', tenant_slug: 'big-co', suggested: null,
}

const catalogue = { currency: 'SAR', plans: [{ code: 'solo', name: 'Solo' }, { code: 'team', name: 'Team' }, { code: 'agency_2026', name: 'Agency' }] }

/** PRICING-MIGRATION-001 — both prices in their own currencies, usage against the new caps, and a move that must be typed back. */
describe('PlanMigrationPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows both prices unconverted and usage against the suggested plan', async () => {
    vi.mocked(fetchPlanMigration).mockResolvedValue({ workspaces: [legacy, outgrown], catalogue })
    renderWithProviders(<PlanMigrationPage />, { locale: 'en' })

    const row = await screen.findByTestId('migration-row-legacy-co')
    expect(row).toHaveTextContent('49.00 USD')
    expect(row).toHaveTextContent('699.00 SAR')
    expect(screen.getByTestId('migration-metric-legacy-co-projects')).toHaveTextContent('3 / 5')
    expect(screen.getByTestId('migration-status-legacy-co')).toHaveTextContent('Ready')
    expect(screen.getByTestId('migration-status-big-co')).toHaveTextContent('Needs a conversation')
    expect(screen.getByTestId('migration-select-big-co')).toBeDisabled()
  })

  it('schedules one move only once the slug is typed back and a reason given', async () => {
    vi.mocked(fetchPlanMigration).mockResolvedValue({ workspaces: [legacy], catalogue })
    vi.mocked(schedulePlanMigration).mockResolvedValue({ workspace: legacy })
    renderWithProviders(<PlanMigrationPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('migration-open-legacy-co'))
    const submit = screen.getByTestId('migration-submit-legacy-co')
    fireEvent.change(screen.getByTestId('migration-reason-legacy-co'), { target: { value: 'Catalogue 2026-11 move' } })
    fireEvent.change(screen.getByTestId('migration-confirm-legacy-co'), { target: { value: 'legacy' } })
    expect(submit).toBeDisabled()
    fireEvent.change(screen.getByTestId('migration-confirm-legacy-co'), { target: { value: 'legacy-co' } })
    fireEvent.click(submit)

    await waitFor(() => expect(schedulePlanMigration).toHaveBeenCalledWith('t1', { plan_code: 'team', reason: 'Catalogue 2026-11 move', confirm: 'legacy-co' }))
  })

  it('moves a selection in bulk behind «MOVE n»', async () => {
    vi.mocked(fetchPlanMigration).mockResolvedValue({ workspaces: [legacy], catalogue })
    vi.mocked(bulkPlanMigration).mockResolvedValue({ scheduled: ['t1'], skipped: [] })
    renderWithProviders(<PlanMigrationPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('migration-select-legacy-co'))
    const bar = screen.getByTestId('migration-bulk')
    fireEvent.change(within(bar).getByTestId('migration-bulk-reason'), { target: { value: 'Catalogue move' } })
    expect(within(bar).getByTestId('migration-bulk-submit')).toBeDisabled()
    fireEvent.change(within(bar).getByTestId('migration-bulk-confirm'), { target: { value: 'MOVE 1' } })
    fireEvent.click(within(bar).getByTestId('migration-bulk-submit'))

    await waitFor(() => expect(bulkPlanMigration).toHaveBeenCalledWith({ tenant_ids: ['t1'], reason: 'Catalogue move', confirm: 'MOVE 1' }))
  })
})
