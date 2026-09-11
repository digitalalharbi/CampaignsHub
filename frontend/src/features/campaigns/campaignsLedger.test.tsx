import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import { campaignPage } from '@/test/campaignPage'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listCampaigns: vi.fn() }))

import { listCampaigns } from './api'

/**
 * CAMPAIGNS-LEDGER-001 — the page is a page, and it says so.
 *
 * The endpoint handed over every campaign in the project. Campaigns are the one list here that grows
 * without a ceiling, and the counts, the donut and the «what is running» ordering were all derived
 * from the array the browser held.
 */
const campaign = (id: string, status = 'active'): UnifiedCampaign => ({
  id, project_id: 'p1', name: `Campaign ${id}`, objective: 'sales', status,
  total_budget: 1000, budget_currency: 'SAR', starts_on: null, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 0,
  created_at: null,
} as UnifiedCampaign)

describe('the campaigns ledger', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'campaigns.manage'])
  })
  afterEach(() => signOut())

  /* A donut of the first twenty-five looks exactly like a donut of the project. */
  it('counts the project, not the rows that fitted', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(
      campaignPage([campaign('a')], { total: 214, lastPage: 9, counts: { active: 180, paused: 34 } }),
    )
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    expect(await screen.findByText('214')).toBeInTheDocument()
  })

  it('says which page of how many it is showing', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(
      campaignPage([campaign('a')], { total: 214, lastPage: 9, counts: { active: 214 } }),
    )
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    const pager = await screen.findByTestId('campaigns-pager')
    expect(pager.textContent).toContain('of 214')
    expect(pager.textContent).toMatch(/page 1 of 9/i)
  })

  /* The next page is the server's — the browser holds one page and cannot slice its way to another. */
  it('asks the server for the next page', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(
      campaignPage([campaign('a')], { total: 214, lastPage: 9, counts: { active: 214 } }),
    )
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Next/i }))

    await waitFor(() => {
      expect(vi.mocked(listCampaigns).mock.calls.some(([, p]) => p?.page === 2)).toBe(true)
    })
  })

  it('offers no pager when the whole project fits', async () => {
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([campaign('a')]))
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    expect(await screen.findByText('Campaign a')).toBeInTheDocument()
    expect(screen.queryByTestId('campaigns-pager')).toBeNull()
  })
})
