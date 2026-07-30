import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { PlatformOverviewPage } from './PlatformOverviewPage'
import type { PlatformOverview } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchOverview: vi.fn() }
})

import { fetchOverview } from './api'

function overview(over: Partial<PlatformOverview> = {}): PlatformOverview {
  return {
    tenants: { total: 12, active: 11, suspended: 1, by_account_type: { agency: 5, brand: 7 }, by_plan: { trial: 9, growth: 3 } },
    people: { users: 40, platform_admins: 1, memberships: 38, without_membership: 0 },
    workload: { client_workspaces: 22, open_requests: 4, unpaid_invoices: 2 },
    ...over,
  }
}

describe('PlatformOverviewPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('counts across every tenant', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview())
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Tenants')).toBeInTheDocument())
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('11 active · 1 suspended')).toBeInTheDocument()
  })

  /**
   * The console must never make reading a customer's work easy — owning the platform is not a reason
   * to open their campaigns, and a dashboard that put it one click away would see it happen without
   * anyone deciding to.
   */
  it('shows no customer work anywhere on the page', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview())
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Tenants')).toBeInTheDocument())
    // Word-bounded: "suspended" contains "spend", and a substring match here would fail on
    // the tenant status line rather than on anything resembling a customer's data.
    for (const forbidden of [/\bcampaigns?\b/i, /\breports?\b/i, /\bspend\b/i, /\brevenue\b/i]) {
      expect(screen.queryByText(forbidden)).not.toBeInTheDocument()
    }
  })

  /**
   * Users with no workspace is a DEFECT signal, not a statistic — it is how invitees ended up signing
   * in to nothing. Above zero it must read as a problem; at zero it must not be on the page at all,
   * or the warning becomes wallpaper.
   */
  it('warns about people who belong to no workspace, and only when there are any', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview())
    const { unmount } = renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })
    await waitFor(() => expect(screen.getByText('Tenants')).toBeInTheDocument())
    expect(screen.queryByTestId('stranded-users')).not.toBeInTheDocument()
    unmount()

    vi.mocked(fetchOverview).mockResolvedValue(
      overview({ people: { users: 40, platform_admins: 1, memberships: 36, without_membership: 3 } }),
    )
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('stranded-users')).toBeInTheDocument())
    expect(screen.getByTestId('stranded-users')).toHaveAttribute('role', 'alert')
    expect(screen.getByTestId('stranded-users')).toHaveTextContent('sign in to nothing')
  })

  /** An empty platform reports zeros — never a sample figure. */
  it('reports zeros for an empty platform rather than sample data', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(
      overview({
        tenants: { total: 0, active: 0, suspended: 0, by_account_type: {}, by_plan: {} },
        people: { users: 1, platform_admins: 1, memberships: 0, without_membership: 0 },
        workload: { client_workspaces: 0, open_requests: 0, unpaid_invoices: 0 },
      }),
    )
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByText('No tenants yet.')).toBeInTheDocument())
    expect(screen.getByText('No subscriptions yet.')).toBeInTheDocument()
  })
})
