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
    growth: [
      { month: '2026-06', opened: 2, total: 8 },
      { month: '2026-07', opened: 1, total: 9 },
      { month: '2026-08', opened: 3, total: 12 },
    ],
    subscriptions: {
      by_status: { active: 7, past_due: 1, trialing: 4 },
      committed_monthly: [{ currency: 'SAR', monthly: 3493, subscriptions: 7 }],
      collection_status: 'not_implemented',
    },
    attention: [
      { key: 'registrations_pending', count: 2, to: '/admin/registrations', tone: 'warning' },
      { key: 'subscriptions_past_due', count: 0, to: '/admin/billing', tone: 'danger' },
      { key: 'tenants_suspended', count: 1, to: '/admin/tenants', tone: 'danger' },
      { key: 'users_without_membership', count: 0, to: '/admin/tenants', tone: 'info' },
    ],
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
        growth: [{ month: '2026-08', opened: 0, total: 0 }],
        subscriptions: { by_status: {}, committed_monthly: [], collection_status: 'not_implemented' },
        attention: [{ key: 'registrations_pending', count: 0, to: '/admin/registrations', tone: 'warning' }],
      }),
    )
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByText('No tenants yet.')).toBeInTheDocument())
    expect(screen.getByText('No subscriptions yet.')).toBeInTheDocument()
    // No committed value to show, and it says so rather than printing 0 SAR — which would read as
    // "seven subscribers worth nothing" instead of "there are no subscribers".
    expect(screen.getByText('No active subscriptions')).toBeInTheDocument()
  })

  /**
   * The money figure is a COMMITMENT and the page has to say so.
   *
   * CampaignsHub does not charge tenants yet: the collection side is not built, and the invoices in
   * this database are agency-to-client billing that belongs to the agency. A console that showed
   * this as money in the bank would be the most expensive untruth in the product.
   */
  it('calls committed subscription value what it is, and says collection is not live', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview())
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Committed monthly')).toBeInTheDocument())
    expect(screen.getByText('3,493 SAR')).toBeInTheDocument()
    expect(screen.getByTestId('collection-note')).toHaveTextContent('not live yet')
    expect(screen.getByTestId('collection-note')).toHaveTextContent('belong to the agency')
  })

  /**
   * Database codes are not words.
   *
   * `self_serve_company` and `past_due` are column values; they were being rendered raw into an
   * Arabic-first interface, so half the page silently stopped being Arabic.
   */
  it('names account types and subscription states instead of printing their codes', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview({
      tenants: {
        total: 3, active: 3, suspended: 0,
        by_account_type: { self_serve_company: 2, in_house_team: 1 },
        by_plan: { growth: 3 },
      },
    }))
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('tenants-by-type')).toBeInTheDocument())
    expect(screen.queryByText('self_serve_company')).not.toBeInTheDocument()
    expect(screen.queryByText('in_house_team')).not.toBeInTheDocument()
  })

  /** The attention list leads somewhere, and lists only what is actually outstanding. */
  it('lists what is outstanding, each linking to the page that answers it', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview())
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('attention')).toBeInTheDocument())
    expect(screen.getByTestId('attention-registrations_pending')).toHaveAttribute('href', '/admin/registrations')
    expect(screen.getByTestId('attention-tenants_suspended')).toHaveAttribute('href', '/admin/tenants')
    // Zeros are returned by the API on purpose and are NOT listed — a strip of zeros is wallpaper.
    expect(screen.queryByTestId('attention-subscriptions_past_due')).not.toBeInTheDocument()
  })

  /** Nothing outstanding is stated in words, not left as an empty box to interpret. */
  it('says so plainly when nothing needs attention', async () => {
    vi.mocked(fetchOverview).mockResolvedValue(overview({
      attention: [{ key: 'registrations_pending', count: 0, to: '/admin/registrations', tone: 'warning' }],
    }))
    renderWithProviders(<PlatformOverviewPage />, { route: '/admin', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('attention')).toBeInTheDocument())
    expect(screen.getByTestId('attention')).toHaveTextContent('Nothing pending')
  })
})
