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
   * The dashboard is CONCISE: it carries the four operational answers and not the analysis.
   *
   * The spend curve, the ROAS/CPA/CTR trio and the store ledger answer «why», and both surfaces drew
   * all three — which is what made them one screen with its first panel swapped.
   */
  it('the dashboard drops the analytical blocks', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    const overview = await screen.findByTestId('dashboard-overview')

    expect(overview.textContent).not.toMatch(/Spend, results and revenue/i)
    /*
      CTR, not ROAS. «ROAS» is also a COLUMN in the best-campaigns table, which is operational content
      the dashboard keeps — asserting on it would fail for the right page showing the right thing.
      CTR appears only as a rate-trend panel, so its absence is exactly the claim being made.
    */
    expect(overview.textContent).not.toMatch(/CTR/)
  })

  /* And the analysis keeps them — nothing was deleted, only un-duplicated. */
  it('the analysis still draws the curve and the rate trends', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })

    const overview = await screen.findByTestId('analytics-overview')

    expect(overview.textContent).toMatch(/Spend, results and revenue/i)
    expect(overview.textContent).toMatch(/CTR/)
  })
})
