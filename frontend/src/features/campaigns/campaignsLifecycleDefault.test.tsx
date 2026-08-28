import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the workspace opens on what is RUNNING, and never hides the rest.
 *
 * It listed every campaign a project has ever had, newest first, so a project with two years of
 * history opened on whatever happened to be created last. An operator's first question is what is
 * running now, and the answer was somewhere in the list.
 */
vi.mock('./api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listUsers: vi.fn() }))

const metrics = vi.hoisted(() => ({ value: undefined as unknown }))

vi.mock('@/features/analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/analytics/api')>()
  const empty = { data: undefined, isPending: false, isLoading: false, isError: false }
  return {
    ...actual,
    useSummary: () => empty,
    useTimeseries: () => empty,
    usePlatforms: () => empty,
    useBudget: () => empty,
    useCampaigns: () => metrics.value,
  }
})

import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'

const campaign = (id: string, name: string, status: string): UnifiedCampaign => ({
  id, project_id: 'p1', name, objective: 'sales', status, total_budget: 1000, budget_currency: 'SAR',
  starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null,
  attribution_window: null, owner_id: null, target_kpi: null, audience: null, regions: null,
  external_campaigns_count: 2, created_at: null,
})

/** Today's date decides what counts as «recently active», so the row is anchored to it. */
const today = new Date().toISOString().slice(0, 10)

describe('the campaigns workspace, opened cold', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue([
      campaign('running', 'Still running', 'active'),
      campaign('finished', 'Last year', 'completed'),
    ])
    metrics.value = {
      data: [
        { campaign_id: 'running', spend: 10, last_active_on: today },
        { campaign_id: 'finished', spend: 90000, last_active_on: '2026-01-05' },
      ],
      isPending: false,
      isLoading: false,
      isError: false,
    }
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  const openList = async () => fireEvent.click(await screen.findByTestId('view-cards'))

  it('shows what is running and leaves the finished one out of the default view', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openList()

    expect(await screen.findByText('Still running')).toBeInTheDocument()
    // A finished campaign that outspent it by four orders of magnitude is not the first thing an
    // operator should be shown — it is not something they can act on.
    expect(screen.queryByText('Last year')).not.toBeInTheDocument()
  })

  /** Never hidden: the count is on screen and one click brings it back. */
  it('says how many are inactive, and shows them when asked', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openList()
    await screen.findByText('Still running')

    expect(await screen.findByTestId('lifecycle-count-inactive')).toHaveTextContent('1')

    fireEvent.click(screen.getAllByTestId('lifecycle-chip')[1])
    expect(await screen.findByText('Last year')).toBeInTheDocument()
  })

  /*
   * The card names the result the campaign was bought for, and never prints a coalesced zero as one.
   *
   * `sales` here, so the headline is what it sold. The running campaign's platform never sent
   * `purchases`, and «الطلبات 0» on the card an operator judges it by is not a measurement — it is
   * the absence of one, and it reads as a campaign that failed.
   */
  it('leads the card with the objective\'s result, and says when the platform never sent it', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openList()
    await screen.findByText('Still running')

    const headline = screen.getByTestId('campaign-headline-purchases')
    expect(headline).toHaveAttribute('data-state', 'not_provided')
    expect(headline).toHaveTextContent('Not provided')
    expect(headline).not.toHaveTextContent('0')
  })

  it('arrives on the lifecycle the link asked for', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/campaigns?lifecycle=all' })
    await openList()

    expect(await screen.findByText('Still running')).toBeInTheDocument()
    expect(await screen.findByText('Last year')).toBeInTheDocument()
  })

  /*
   * The claim that must never be made by accident: «nothing is running».
   *
   * Relevance is read from the metrics window. While that request is in flight every campaign looks
   * dark, and an «active only» view would render an empty workspace as a fact about the account
   * rather than about a request that has not answered.
   */
  it('shows everything, and says why, while the metrics have not arrived', async () => {
    metrics.value = { data: undefined, isPending: true, isLoading: true, isError: false }

    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openList()

    expect(await screen.findByText('Last year')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('lifecycle-degraded')).toBeInTheDocument())
  })

  it('does the same when the metrics request failed outright', async () => {
    metrics.value = { data: undefined, isPending: false, isLoading: false, isError: true, error: new Error('nope') }

    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await openList()

    expect(await screen.findByText('Last year')).toBeInTheDocument()
    expect(screen.getByTestId('lifecycle-degraded')).toBeInTheDocument()
  })
})
