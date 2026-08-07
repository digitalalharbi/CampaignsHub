import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { DashboardPage } from './DashboardPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

/**
 * UX-DASH-001 — the filters are on the page, and the KPI row follows the objective.
 *
 * The page these replace opened with one `Customise` button and a sentence saying what was applied.
 * Nothing was broken about it; it was simply a product that looked thinner than it is, because the
 * questions it can answer were all one click out of sight. Each test here fails if a daily filter
 * goes back behind a dialog, if a narrowed page stops naming what narrowed it, or if the KPI row
 * stops depending on what the money was for.
 */

const EMPTY = { data: undefined, isLoading: false, isError: false }

vi.mock('../analytics/hooks', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../analytics/hooks')>()
  return {
    ...actual,
    useSummary: vi.fn(),
    useTimeseries: vi.fn(() => EMPTY),
    usePlatforms: vi.fn(() => EMPTY),
    useCampaigns: vi.fn(() => EMPTY),
    useFunnel: vi.fn(() => EMPTY),
    useBudget: vi.fn(() => EMPTY),
    useFreshness: vi.fn(() => EMPTY),
  }
})

vi.mock('./savedViews', () => ({ useSavedViews: () => ({ data: [], isLoading: false, isError: false }) }))
vi.mock('./SavedViewsBar', () => ({ SavedViewsBar: () => null }))

import { useCampaigns, useSummary } from '../analytics/hooks'

const TOTALS = {
  impressions: 40000, clicks: 800, conversions: 12, spend: 5000, revenue: 30000,
  reach: 0, video_views: 0, video_completions: 0, landing_page_views: 0, leads: 0,
  qualified_leads: 0, purchases: 24, installs: 0, registrations: 0, in_app_events: 0,
  engagements: 0, roas: 6, cpa: 416.67, ctr: 0.02, cpc: 6.25, cpm: 125, frequency: null,
  cpl: null, cpi: null, cpe: null, aov: 1250, conversion_rate: 0.015,
  engagement_rate: null, video_completion_rate: null,
}

/**
 * Reach and landing-page views were NEVER sent; clicks were sent and are real.
 *
 * Both read as a number in `current` — the sums coalesce — so this map is the only thing that can
 * tell them apart, and the whole «no zero for a missing metric» rule rests on it.
 */
const REPORTED: Record<string, boolean> = {
  spend: true, impressions: true, clicks: true, conversions: true, revenue: true, purchases: true,
  reach: false, landing_page_views: false, video_views: false, video_completions: false,
  leads: false, qualified_leads: false, installs: false, registrations: false,
  in_app_events: false, engagements: false,
}

function summary() {
  return {
    data: { current: TOTALS, previous: TOTALS, delta: { spend: 0.12, cpa: -0.2 }, reported: REPORTED, commerce: null },
    isLoading: false,
    isError: false,
  }
}

describe('the dashboard filter bar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(useSummary).mockReturnValue(summary() as never)
    vi.mocked(useCampaigns).mockReturnValue(EMPTY as never)
  })
  afterEach(() => signOut())

  /** The claim, plainly: nothing has to be opened to reach the filters this product is used through. */
  it('puts the period, platform, path and objective controls on the page', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en' })

    await screen.findByTestId('dashboard-intro')

    expect(screen.getByTestId('dashboard-period')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-platform')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-path')).toBeInTheDocument()
    expect(screen.getByTestId('dashboard-objective')).toBeInTheDocument()
    // And no dialog was involved.
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  /** A narrowed page names what narrowed it, and the chip removes exactly that. */
  it('names an applied filter as a chip and removes only that one', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'sales' } })

    const chip = screen.getByTestId('dashboard-applied-objective:sales')
    expect(chip).toHaveTextContent('Sales')

    fireEvent.click(screen.getByRole('button', { name: /Remove Objective: Sales/ }))
    expect(screen.queryByTestId('dashboard-applied-objective:sales')).not.toBeInTheDocument()
  })

  /**
   * §14.6, on the dashboard: the cards are the ones this money is judged on.
   *
   * A sales campaign leads with what it sold and what that cost. An awareness campaign leads with
   * how many people it reached — and a cost per order on it is not an extra column, it is an
   * inflated number a client would set next month's budget on.
   */
  it('leads with the metrics the objective is judged on', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'sales' } })
    expect(screen.getByTestId('metric-purchases')).toBeInTheDocument()
    expect(screen.getByTestId('metric-roas')).toBeInTheDocument()
    // Reach is a secondary concern for a sales campaign — folded, not deleted.
    expect(screen.queryByTestId('metric-reach')).not.toBeInTheDocument()

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'awareness' } })
    expect(screen.getByTestId('metric-reach')).toBeInTheDocument()
    // …and no cost per order on money that was never meant to sell anything.
    expect(screen.queryByTestId('metric-cpa')).not.toBeInTheDocument()
  })

  /**
   * The rule that survives every redesign: **a metric nobody reported is not a zero.**
   *
   * `current.reach` is 0 here because the sums coalesce, and `reported.reach` is false because no
   * platform ever sent it. The card must read «Not provided». A `?? 0` anywhere between the payload
   * and the card turns this into «Reach 0» beside forty thousand impressions.
   */
  it('says a metric was never reported instead of printing its coalesced zero', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'awareness' } })

    const reach = screen.getByTestId('metric-reach')
    expect(reach).toHaveAttribute('data-state', 'not_provided')
    expect(reach).toHaveTextContent('Not provided')
    expect(reach).not.toHaveTextContent('0')

    // A metric that WAS sent still shows its figure, zero or not.
    expect(screen.getByTestId('metric-impressions')).toHaveAttribute('data-state', 'value')
  })

  /** Choosing a path narrows the objectives on offer, so the two controls cannot contradict. */
  it('offers only the objectives that belong to the chosen path', async () => {
    renderWithProviders(<DashboardPage />, { locale: 'en' })
    await screen.findByTestId('dashboard-intro')

    fireEvent.change(screen.getByTestId('dashboard-path'), { target: { value: 'traffic' } })

    const objectives = Array.from(
      screen.getByTestId('dashboard-objective').querySelectorAll('option'),
    ).map((o) => o.getAttribute('value'))

    expect(objectives).toContain('traffic')
    expect(objectives).not.toContain('sales')
  })
})
