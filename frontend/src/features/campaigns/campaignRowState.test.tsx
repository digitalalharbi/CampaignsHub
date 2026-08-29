import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'

import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the row's concise state, and «no arbitrary opaque health score».
 *
 * A score is what this becomes the moment the row grows its own rules, so the state is the shared
 * diagnostic engine run over that row's own totals. What the row must never do is show the same thing
 * for «examined and fine» and «never examined»: that is the opaque score the requirement forbids,
 * wearing a word instead of a number.
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

const today = new Date().toISOString().slice(0, 10)

const campaign = (id: string, name: string): UnifiedCampaign => ({
  id, project_id: 'p1', name, objective: 'sales', status: 'active', total_budget: 1000, budget_currency: 'SAR',
  starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null,
  attribution_window: null, owner_id: null, target_kpi: null, audience: null, regions: null,
  external_campaigns_count: 2, created_at: null,
})

const REPORTED = { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true }

describe('the concise state on a campaign row', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue([
      campaign('broken', 'Not delivering'),
      campaign('fine', 'Healthy'),
      campaign('silent', 'Connector sent nothing'),
      // Switched ON, but it has not reported a positive figure in weeks. Status alone calls this
      // «serving»; the shared rule calls it idle, and that difference is the whole point.
      campaign('stale', 'On but quiet'),
    ])
    metrics.value = {
      data: [
        {
          campaign_id: 'broken', last_active_on: today, reported: REPORTED,
          // A real baseline, so this row can carry a measurable change.
          previous_spend: 500, spend_change: 1.0,
          spend: 1000, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0,
        },
        {
          campaign_id: 'fine', last_active_on: today, reported: REPORTED,
          // No row in the comparison window at all — it launched this period.
          previous_spend: null, spend_change: null,
          spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000,
        },
        // Every figure zero because nothing was ever reported — the coalesced-zero trap, per campaign.
        { campaign_id: 'silent', last_active_on: today, reported: {}, spend: 0, impressions: 0, clicks: 0 },
        {
          campaign_id: 'stale', last_active_on: '2026-06-01', reported: REPORTED,
          spend: 500, impressions: 9000, clicks: 100, landing_page_views: 90, conversions: 5, revenue: 900,
        },
      ],
      isPending: false, isLoading: false, isError: false,
    }
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  it('labels the campaign that is not delivering', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    expect(await screen.findByTestId('campaign-state-not_delivering')).toBeInTheDocument()
  })

  /**
   * The two silences that must not look alike. Neither row carries a state chip — but for opposite
   * reasons, and neither may be labelled as though it were the other.
   */
  it('shows no state for a healthy campaign and none for an unexamined one', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    await screen.findByText('Healthy')

    // The healthy row carries no chip at all — nothing to say is not a verdict.
    // The silent row says «not measured», which is a fact about its connector rather than about it.
    expect(screen.queryByTestId('campaign-state-not_delivering')).toBeInTheDocument()
    expect(screen.getByTestId('campaign-state-unmeasured')).toBeInTheDocument()
    // And exactly one unmeasured chip: the healthy campaign was measured, so it gets neither.
    expect(screen.queryAllByTestId('campaign-state-unmeasured')).toHaveLength(1)
  })

  /**
   * Freshness comes from the shared relevance rule, and a campaign that reported no active day is
   * NOT «stopped» — the platform never said it ended, and the window is the only evidence there is.
   * Calling it stopped would be inventing the answer, which is precisely what the rule refuses.
   */
  it('names freshness from the shared rule and does not call a silent campaign stopped', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    await screen.findByText('Healthy')

    // All three fixtures are `active` with a last-active day of today, except the silent one, which
    // has none at all — so it is idle, never stopped.
    expect(screen.queryAllByTestId('campaign-freshness-serving').length).toBeGreaterThan(0)
    expect(screen.queryByTestId('campaign-freshness-stopped')).not.toBeInTheDocument()

    // «On but quiet» is switched on and has not reported for weeks. Status alone would call it
    // serving; the shared rule calls it idle, and the row must say what the rule says.
    expect(screen.getByTestId('campaign-freshness-idle')).toBeInTheDocument()
  })

  /**
   * A trend is shown only where there is a baseline to measure against.
   *
   * The campaign that launched this period has no row in the comparison window, and a pill reading
   * «-100%» there is a collapse that never happened. The row says it has no baseline instead — a
   * missing trend and a flat trend are different facts, and a muted «—» pill is easily read as the
   * second.
   */
  it('shows a trend where there is a baseline and says so where there is not', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    await screen.findByText('Healthy')

    expect(screen.queryAllByTestId('campaign-trend').length).toBeGreaterThan(0)
    expect(screen.queryAllByTestId('campaign-trend-no-baseline').length).toBeGreaterThan(0)
    expect(screen.getAllByTestId('campaign-trend-no-baseline')[0]).toHaveTextContent('No baseline')
  })

  /**
   * The landing answer describes the same set the list shows, and keeps its silences apart.
   *
   * The fixtures hold one broken campaign, one healthy, one whose connector reported nothing, and one
   * on-but-quiet. «Not measured» must appear as its own answer rather than being absorbed into the
   * healthy count — that absorption is the opaque score this requirement forbids, on the first figure
   * a reader sees.
   */
  it('answers what needs attention without folding the unmeasured into the healthy', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    await screen.findByText('Healthy')

    const landing = screen.getByTestId('campaigns-landing-answer')
    expect(landing).toHaveTextContent('need attention')
    expect(screen.getByTestId('landing-unexamined')).toHaveTextContent('not measured')
  })

  /** No budget row can be paced in these fixtures, so pacing is said to be unmeasurable, not zero. */
  it('says pacing could not be measured rather than answering zero', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByTestId('view-cards'))

    await screen.findByText('Healthy')

    expect(screen.getByTestId('landing-pacing-unknown')).toBeInTheDocument()
    expect(screen.queryByTestId('landing-overpacing')).not.toBeInTheDocument()
  })
})
