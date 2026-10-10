import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { RecommendationsPage } from '@/features/recommendations/RecommendationsPage'
import { SpendLimitsPage } from '@/features/budget/SpendLimitsPage'
import { agencyNavLeafPaths } from '@/layouts/agencyNav'

/**
 * AGENCY-DECISION-SURFACES-001 — Recommendations and Spend limits reach the agency operator.
 *
 * Both pages existed only under `/app`. Mounted under `/agency` they open, like every other
 * project-scoped page, on a choice when nothing is selected (PROJECT-FIRST-VISIT-001) — not on
 * «no limits yet» over a query that never ran, and not on an empty state pointing at the switcher.
 */
vi.mock('@/features/projects/api', () => ({
  // Two reachable projects: exactly one would be chosen without a click, and the chooser would
  // never render — which is the single-project rule, not the thing under test here.
  listProjects: vi.fn().mockResolvedValue([
    { id: 'p1', name: 'Q3 Launch', client_workspace_id: 'c1', status: 'active', summary: { campaigns_total: 4, data_last_synced_at: '2026-10-09T00:00:00Z' } },
    { id: 'p2', name: 'Always-on', client_workspace_id: 'c1', status: 'active', summary: { campaigns_total: 2, data_last_synced_at: null } },
  ]),
  listClientWorkspaces: vi.fn().mockResolvedValue([{ id: 'c1', name: 'Acme', status: 'active' }]),
  listUsers: vi.fn().mockResolvedValue([]),
}))
vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn().mockResolvedValue({ data: [], meta: {} }),
  getData: vi.fn().mockResolvedValue([]),
}))

beforeEach(() => {
  useProject.setState({ currentProjectId: null })
  signInWith(['campaigns.view'])
})
afterEach(() => signOut())

describe('with no project chosen', () => {
  it('Recommendations puts the choice on the page', async () => {
    renderWithProviders(<RecommendationsPage />, { locale: 'ar' })
    expect(await screen.findByTestId('project-chooser')).toBeInTheDocument()
    // The old empty state's body — the sentence that pointed the reader at the switcher — is gone.
    expect(screen.queryByText('التوصيات مرتبطة بحملات المشروع — اختر مشروعًا لعرضها.')).not.toBeInTheDocument()
  })

  it('Spend limits puts the choice on the page, never «no limits yet» over a query it did not run', async () => {
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })
    expect(await screen.findByTestId('project-chooser')).toBeInTheDocument()
    expect(screen.queryByText('No limits yet')).not.toBeInTheDocument()
  })
})

describe('the agency rail', () => {
  it('carries both decision surfaces', () => {
    expect(agencyNavLeafPaths).toContain('/agency/recommendations')
    expect(agencyNavLeafPaths).toContain('/agency/spend-limits')
  })
})
