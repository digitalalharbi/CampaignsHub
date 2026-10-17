import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ClientCampaignsPage } from './ClientCampaignsPage'
import type { PortalCampaign, PortalCampaignPage } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

// Keep the real formatting/helpers; mock only the network call.
vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return { ...actual, listClientCampaigns: vi.fn() }
})

import { listClientCampaigns } from './portalAccountApi'

const campaign: PortalCampaign = {
  id: 'c1', name: 'Spring Launch', status: 'active', objective: 'Traffic',
  starts_on: '2026-03-01', ends_on: '2026-03-31',
  metrics: { impressions: 12430, clicks: 512, conversions: 34, ctr: 0.0412 },
}

const page = (campaigns: PortalCampaign[], over: Partial<PortalCampaignPage> = {}): PortalCampaignPage => ({
  campaigns, total: campaigns.length, page: 1, per_page: 25, last_page: 1, names_searchable: true,
  facets: { status: { active: campaigns.length }, objective: { Traffic: campaigns.length }, projects: [] }, ...over,
})

describe('ClientCampaignsPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders campaigns with Latin-digit delivery metrics and NO cost fields', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue(page([campaign]))
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })

    expect(await screen.findByText('Spring Launch')).toBeInTheDocument()
    // Grouped Latin digits for impressions.
    expect(screen.getByText('12,430')).toBeInTheDocument()
    // CTR rendered as a percentage.
    expect(screen.getByText('4.12%')).toBeInTheDocument()
    // Objective + delivery-only metric labels are shown (client-safe shape carries no spend/ROAS/CPA figures).
    expect(screen.getAllByText('Traffic').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Impressions').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Results').length).toBeGreaterThan(0)
  })

  /**
   * The client reads their own language, not our storage values.
   *
   * `objective` and `status` arrive as enum keys — `awareness`, `paused` — and the card printed them
   * raw, so an Arabic portal said «الهدف: awareness» beside a status badge reading «paused». Caught
   * live the moment the demo client had campaigns on more than one objective to look at.
   */
  it('translates the objective and status rather than printing the enum key', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue(page([
      { ...campaign, id: 'c2', name: 'Brand Awareness', objective: 'awareness', status: 'paused' },
    ], { facets: { status: { paused: 1 }, objective: { awareness: 1 }, projects: [] } }))
    renderWithProviders(<ClientCampaignsPage />, { locale: 'ar' })

    expect(await screen.findByText('Brand Awareness')).toBeInTheDocument()
    expect(screen.getAllByText('الوعي').length).toBeGreaterThan(0)
    expect(screen.getAllByText('موقوفة').length).toBeGreaterThan(0)
    expect(screen.queryByText('awareness')).not.toBeInTheDocument()
    expect(screen.queryByText('paused')).not.toBeInTheDocument()
  })

  it('shows an honest empty state when there are no campaigns', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue(page([]))
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })
    expect(await screen.findByText('No campaigns yet.')).toBeInTheDocument()
  })

  /** CP-SCALE-001 — every filter, the search and the pager are the SERVER's job, never a filter over a full fetch. */
  it('sends the search, filters and page to the server rather than filtering in the browser', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue(page([campaign], {
      total: 60, last_page: 3, facets: { status: { active: 48, paused: 12 }, objective: { Traffic: 60 }, projects: [{ id: 'p1', name: 'Alpha', count: 30 }, { id: 'p2', name: 'Bravo', count: 30 }] },
    }))
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })
    await screen.findByText('Spring Launch')

    fireEvent.change(screen.getByLabelText('All statuses'), { target: { value: 'paused' } })
    await waitFor(() => expect(listClientCampaigns).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'paused', page: 1, per_page: 25 })))

    fireEvent.change(screen.getByLabelText('Search by campaign name'), { target: { value: 'ramadan' } })
    await waitFor(() => expect(listClientCampaigns).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'ramadan', status: 'paused' })), { timeout: 2000 })

    fireEvent.click(screen.getByRole('button', { name: /Next/ }))
    await waitFor(() => expect(listClientCampaigns).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
    expect(screen.getByTestId('campaign-pager')).toHaveTextContent('2 / 3')
  })

  it('offers no search box when the agency hides campaign names, and shows the objective instead of a missing name', async () => {
    vi.mocked(listClientCampaigns).mockResolvedValue(page([{ ...campaign, name: null }], { names_searchable: false }))
    renderWithProviders(<ClientCampaignsPage />, { locale: 'en' })

    await screen.findByTestId('campaign-list')
    expect(screen.queryByLabelText('Search by campaign name')).toBeNull()
    expect(screen.getAllByText('Traffic').length).toBeGreaterThan(0)
  })
})
