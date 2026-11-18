import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AgencyTeamPage } from './AgencyTeamPage'
import type { AgencyTeam } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchAgencyTeam: vi.fn(), grantClientScopes: vi.fn(), withdrawClientScope: vi.fn() }
})

import { fetchAgencyTeam } from './api'

/** TEAM-CLIENT-COVERAGE-001 — «من يتابع كل عميل، والعميل بلا مدير مشروع يظهر بعلامة». */
describe('team page client coverage', () => {
  beforeEach(() => vi.clearAllMocks())

  const team: AgencyTeam = {
    members: [],
    can_manage: true,
    assignable_clients: [],
    coverage: [
      { id: 'c1', name: 'الاتفاق', managers: ['سارة'], followers: ['سارة', 'خالد'], has_manager: true },
      { id: 'c2', name: 'عجمان', managers: [], followers: [], has_manager: false },
    ],
  }

  it('names who follows each client and flags the one without a project manager, first', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue(team)
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'ar' })

    // Coverage is its own tab (NOV-FIT-001); the tab carries the count of clients without a manager.
    const tab = await screen.findByRole('tab', { name: /متابعة العملاء/ })
    expect(tab).toHaveTextContent('1')
    fireEvent.click(tab)

    const section = await screen.findByTestId('team-coverage')
    expect(within(section).getByTestId('coverage-flag-c2')).toHaveTextContent('بدون مدير')
    expect(within(section).queryByTestId('coverage-flag-c1')).not.toBeInTheDocument()
    expect(section).toHaveTextContent('خالد')
    // The unattended client leads the list.
    const rows = within(section).getAllByTestId(/^coverage-c\d+$/)
    expect(rows[0].dataset.testid).toBe('coverage-c2')
  })

  it('draws nothing when an older backend sends no coverage', async () => {
    vi.mocked(fetchAgencyTeam).mockResolvedValue({ ...team, coverage: undefined })
    renderWithProviders(<AgencyTeamPage />, { route: '/agency/team', locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('team-intro')).toBeInTheDocument())
    expect(screen.queryByTestId('team-coverage')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: /متابعة العملاء/ })).not.toBeInTheDocument()
  })
})
