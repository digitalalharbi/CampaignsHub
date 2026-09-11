import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * SURFACE-SEPARATION-001 — the Dashboard and Analytics are different products again.
 *
 * `ANALYTICS-AS-DASHBOARD-001` merged them because they had converged: the same filters over the
 * same KPI strip, twice. The merge fixed the duplication and produced a different problem — both
 * routes rendered the SAME twelve tabs, so «the dashboard» and «the analysis» were one screen with
 * its first panel swapped, and neither was concise nor deep.
 *
 * The Owner's direction resolves it: the Dashboard answers what is happening, what changed, what
 * needs attention and what to open next. Analytics answers WHY — which platform, which objective,
 * which campaign, which content, over which trend, against which budget.
 *
 * The depth lives in the tabs, so the tab bar belongs to Analytics. What is NOT duplicated is the
 * arithmetic: both surfaces compose the same canonical components over the same hooks, and the
 * separation is one of composition, never of a second metric pipeline.
 */
function route() {
  const body = (url: string) => {
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('budget-explanation') || url.includes('disclaimer')) return null

    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never,
  )
}

describe('the dashboard and the analysis are different surfaces', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'analytics.view', 'budget.view'])
  })
  afterEach(() => signOut())

  /* The depth is the tabs. A dashboard that offers all twelve is the analysis wearing another name. */
  it('the dashboard offers no analysis tab bar', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    expect(await screen.findByTestId('dashboard-intro')).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: /Platforms/i })).toBeNull()
    expect(screen.queryByRole('tab', { name: /Budget/i })).toBeNull()
  })

  it('analytics keeps the whole tab bar', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })

    expect(await screen.findByRole('tab', { name: /Platforms/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Budget/i })).toBeInTheDocument()
  })

  /* «What should I open next» is a door, not a dead end. */
  it('the dashboard points at the analysis for the depth it does not carry', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    const link = await screen.findByTestId('dashboard-to-analytics')
    expect(link).toHaveAttribute('href', expect.stringContaining('/app/analytics'))
  })

  /* Both surfaces keep their filters — «what is happening now» still has to be scoped. */
  it('the dashboard keeps its own filters', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    expect(await screen.findByTestId('dashboard-period')).toBeInTheDocument()
  })

  /**
   * OWNER CORRECTION 2026-09-11 — the dashboard keeps its operational picture.
   *
   * #362 moved the curve, the rate trends and the store ledger to the analysis because they answer
   * «why». The Owner's correction is that they are ALSO how an operator reads what is happening now,
   * and that «open Analytics for the reason» is not a substitute for information that belongs here.
   *
   * This test previously asserted the opposite. It is rewritten rather than deleted, because the
   * requirement changed and a test that no longer describes the product is worse than no test.
   */
  it('the dashboard keeps the curve and the rate trends', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    const overview = await screen.findByTestId('dashboard-overview')

    expect(overview.textContent).toMatch(/Spend, results and revenue/i)
    expect(overview.textContent).toMatch(/CTR/)
  })

  /* And the analysis keeps them — nothing was deleted, only un-duplicated. */
  it('the analysis still draws the curve and the rate trends', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })

    const overview = await screen.findByTestId('analytics-overview')

    expect(overview.textContent).toMatch(/Spend, results and revenue/i)
    expect(overview.textContent).toMatch(/CTR/)
  })

  /**
   * Rich is not the same as identical — the Owner asked for both.
   *
   * «Do NOT simply make Dashboard identical to Analytics.» The blocks are restored AND the surfaces
   * still differ, and the difference is depth: the analysis carries the tabbed decomposition that
   * drills to account, campaign, ad set, ad and content. Asserted as a property of the two
   * compositions rather than a list of block names, so it keeps holding as either page gains one.
   */
  it('the two surfaces are still different products', async () => {
    const { unmount } = renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })
    const dashboard = (await screen.findByTestId('dashboard-overview')).textContent ?? ''
    const dashboardHasTabs = screen.queryAllByRole('tab').length

    unmount()

    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    const analytics = (await screen.findByTestId('analytics-overview')).textContent ?? ''

    expect(dashboardHasTabs).toBe(0)
    expect(screen.queryAllByRole('tab').length).toBeGreaterThan(5)
    expect(dashboard).not.toBe(analytics)
  })
})
