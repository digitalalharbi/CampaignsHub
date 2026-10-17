import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import { PortfolioPerformance } from './PortfolioPerformance'
import { PortalNav } from './portalNav'
import { ProviderMark } from '@/components/ProviderMark'
import { renderWithProviders } from '@/test/utils'
import type { PortalOverview } from './portalOverviewApi'

vi.mock('./portalOverviewApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalOverviewApi')>()
  return { ...actual, fetchPortalOverview: vi.fn(), fetchPortalVisibility: vi.fn() }
})

import { fetchPortalOverview, fetchPortalVisibility } from './portalOverviewApi'

/**
 * CP-DASH-001, REPORT-KPI-HIERARCHY-001, CP-VISIBILITY-001 — the portfolio is a few large figures and a
 * quiet strip, money is drawn only when the server sent it, and navigation follows the agency's switches.
 */
const base: PortalOverview = {
  space: { slug: 'razzahavenu', name: 'Razzah', mode: 'managed' },
  period: { from: '2026-09-18', to: '2026-10-17', days: 30 },
  currency: null,
  freshness: { state: 'fresh', last_sync_at: '2026-10-17T08:00:00Z' },
  kpis: { impressions: 7_225_186, clicks: 50_229, conversions: 684, ctr: 0.00695 },
  platforms: [
    { provider: 'snapchat', impressions: 6_713_638, clicks: 40_000, conversions: 600 },
    { provider: 'meta', impressions: 511_548, clicks: 10_229, conversions: 84 },
  ],
  projects: [{ id: 'p1', name: 'Razzah Avenue', status: 'active' }],
  campaigns: { total: 48, by_status: { active: 40, paused: 8 } },
}

describe('PortfolioPerformance', () => {
  beforeEach(() => vi.clearAllMocks())

  it('leads with three large figures and puts the rest in a compact strip', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base)
    renderWithProviders(<PortfolioPerformance />, { locale: 'en' })

    expect(await screen.findByTestId('portfolio-kpi-impressions')).toBeInTheDocument()
    expect(screen.getByTestId('portfolio-kpi-clicks')).toBeInTheDocument()
    expect(screen.getByTestId('portfolio-kpi-conversions')).toBeInTheDocument()
    expect(screen.queryByTestId('portfolio-kpi-ctr')).toBeNull()
    expect(within(screen.getByTestId('portfolio-secondary')).getByText(/CTR/)).toBeInTheDocument()
  })

  it('lists platforms in the product-wide order, whatever order the server sent them in', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue({
      ...base,
      platforms: [
        { provider: 'google', impressions: 1, clicks: 1, conversions: 1 },
        { provider: 'meta', impressions: 1, clicks: 1, conversions: 1 },
        { provider: 'snapchat', impressions: 1, clicks: 1, conversions: 1 },
        { provider: 'tiktok', impressions: 1, clicks: 1, conversions: 1 },
      ],
    })
    renderWithProviders(<PortfolioPerformance />, { locale: 'en' })

    const marks = await screen.findAllByTestId('provider-mark')
    expect(marks.map((m) => m.getAttribute('data-provider'))).toEqual(['snapchat', 'tiktok', 'meta', 'google'])
  })

  it('draws no money unless the server sent it', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base)
    renderWithProviders(<PortfolioPerformance />, { locale: 'en' })
    await screen.findByTestId('portfolio-performance')

    expect(screen.queryByText(/Spend/)).toBeNull()
    expect(screen.queryByText(/ROAS/)).toBeNull()
  })

  it('leads with spend, results and cost per result when money is on, and says a withheld figure is unavailable', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue({ ...base, currency: 'USD', kpis: { ...base.kpis, spend: 11_979.96, cpa: null } })
    renderWithProviders(<PortfolioPerformance />, { locale: 'en' })

    const spend = await screen.findByTestId('portfolio-kpi-spend')
    expect(spend).toHaveTextContent('11,979.96 USD')
    expect(screen.getByTestId('portfolio-kpi-cpa')).toHaveTextContent('—')
  })

  it('shows platforms with a mark and campaigns as counts, never as a card each', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(base)
    renderWithProviders(<PortfolioPerformance />, { locale: 'en' })

    const platforms = await screen.findByTestId('portfolio-platforms')
    expect(within(platforms).getAllByTestId('provider-mark')).toHaveLength(2)
    const campaigns = screen.getByTestId('portfolio-campaigns')
    expect(campaigns).toHaveTextContent('48')
    expect(campaigns).toHaveTextContent('Active')
    expect(campaigns.querySelectorAll('article,li')).toHaveLength(0)
  })

  it('renders nothing when the agency has switched the dashboard off', async () => {
    vi.mocked(fetchPortalOverview).mockRejectedValue({ isAxiosError: true, response: { status: 403, data: {} } })
    const { container } = renderWithProviders(<PortfolioPerformance />, { locale: 'en' })

    await waitFor(() => expect(screen.queryByTestId('portfolio-loading')).toBeNull())
    expect(container.querySelector('[data-testid="portfolio-performance"]')).toBeNull()
  })
})

describe('the portal navigation follows the agency’s visibility switches', () => {
  it('leaves out a section that is switched off, and keeps the rest', async () => {
    vi.mocked(fetchPortalVisibility).mockResolvedValue([
      { slug: 'razzahavenu', mode: 'managed', capabilities: {}, visibility: { invoices: false, messages: false, reports: true, requests: true, campaigns: true, files: true } },
    ])
    renderWithProviders(<PortalNav ar={false} />, { locale: 'en', route: '/portal/razzahavenu' })

    await waitFor(() => expect(screen.queryByText('Invoices')).toBeNull())
    expect(screen.queryByText('Quotes')).toBeNull()
    expect(screen.queryByText('Messages')).toBeNull()
    expect(screen.getByText('Reports')).toBeInTheDocument()
    expect(screen.getByText('Requests')).toBeInTheDocument()
    expect(screen.getByText('Profile')).toBeInTheDocument()
  })
})

describe('ProviderMark', () => {
  it('is decorative, canonicalises the provider and never throws on an unknown one', () => {
    const { container } = renderWithProviders(<><ProviderMark provider="google_ads" /><ProviderMark provider="mystery" /></>, { locale: 'en' })
    const marks = container.querySelectorAll('[data-testid="provider-mark"]')

    expect(marks[0]).toHaveAttribute('data-provider', 'google')
    expect(marks[0]).toHaveAttribute('aria-hidden', 'true')
    expect(marks).toHaveLength(2)
  })
})
