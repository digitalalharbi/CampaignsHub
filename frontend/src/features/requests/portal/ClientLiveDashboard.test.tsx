import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api/client')>()
  return { ...actual, getData: vi.fn(), getEnvelope: vi.fn() }
})
vi.mock('./portalOverviewApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalOverviewApi')>()
  return { ...actual, fetchPortalOverview: vi.fn() }
})

import { getData, getEnvelope } from '@/lib/api/client'
import { fetchPortalOverview } from './portalOverviewApi'
import { ClientLiveDashboard, toClientMetrics } from './ClientLiveDashboard'
import { api } from '@/lib/api/client'

const overview = (projects: Array<{ id: string; name: string | null }>) => ({
  space: { slug: 'nasel', name: 'NASEL', mode: 'managed' },
  period: { from: '2026-11-01', to: '2026-11-20', days: 20 },
  currency: null,
  freshness: { state: 'fresh', last_sync_at: null },
  kpis: {},
  platforms: [],
  projects: [],
  campaigns: null,
  dashboard_projects: projects,
})

/**
 * CLIENT-DASHBOARD-PARITY-001 — the portal draws the agency dashboard through the client's own metrics route, for the
 * client's own projects, and draws no card for a figure the server kept back.
 */
describe('ClientLiveDashboard', () => {
  const urls: string[] = []

  beforeEach(() => {
    vi.clearAllMocks()
    urls.length = 0
    const answer = (url: string) => {
      urls.push(url)
      if (url.includes('/metrics/summary')) {
        return { current: { impressions: 1000, clicks: 50, ctr: 5, conversions: 7 }, previous: {}, deltas: {}, currency: null, rows_in_scope: true }
      }
      if (url.includes('/metrics/platforms')) return [{ provider: 'snapchat', impressions: 900, clicks: 40, conversions: 6, ctr: 4.4, spend_share: null }, { provider: 'meta', impressions: 0, clicks: 0, conversions: 0, ctr: null, spend_share: null }]
      if (url.includes('/metrics/campaigns')) return [{ campaign_id: 'c-1', campaign_name: 'Riwaq Snap', provider: 'snapchat', impressions: 900, clicks: 40, conversions: 6, ctr: 4.4 }]

      return []
    }
    vi.mocked(getData).mockImplementation(async (url: string) => answer(url) as never)
    vi.mocked(getEnvelope).mockImplementation(async (url: string) => ({ success: true, data: answer(url), meta: null }) as never)
  })
  afterEach(() => vi.clearAllMocks())

  it('reads every figure through the client route, for the first project', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(overview([{ id: 'p-1', name: 'Riwaq' }]) as never)
    renderWithProviders(<ClientLiveDashboard />, { locale: 'en', route: '/portal/nasel' })

    expect(await screen.findByTestId('client-overview')).toBeInTheDocument()
    await waitFor(() => expect(urls.some((u) => toClientMetrics(u).startsWith('/client/projects/p-1/metrics/summary'))).toBe(true))
    expect(urls.filter((u) => u.includes('/metrics/')).every((u) => toClientMetrics(u).startsWith('/client/projects/p-1/metrics/'))).toBe(true)
  })

  it('sends agency metrics requests to the client route only while it is on screen', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(overview([{ id: 'p-1', name: 'Riwaq' }]) as never)
    const handlers = () => ((api.interceptors.request as unknown as { handlers: Array<unknown | null> }).handlers ?? []).filter(Boolean).length
    const before = handlers()
    const { unmount } = renderWithProviders(<ClientLiveDashboard />, { locale: 'en', route: '/portal/nasel' })
    await screen.findByTestId('client-overview')
    expect(handlers()).toBe(before + 1)
    unmount()
    expect(handlers()).toBe(before)

    expect(toClientMetrics('/projects/0b1c2d3e-4f50-6172-8394-a5b6c7d8e9f0/metrics/summary?from=1')).toBe('/client/projects/0b1c2d3e-4f50-6172-8394-a5b6c7d8e9f0/metrics/summary?from=1')
    expect(toClientMetrics('/projects/0b1c2d3e-4f50-6172-8394-a5b6c7d8e9f0/creatives')).toBe('/projects/0b1c2d3e-4f50-6172-8394-a5b6c7d8e9f0/creatives')
  })

  it('offers the client its projects and moves the dashboard to the one picked', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(overview([{ id: 'p-1', name: 'Riwaq' }, { id: 'p-2', name: null }]) as never)
    renderWithProviders(<ClientLiveDashboard />, { locale: 'en', route: '/portal/nasel' })

    fireEvent.click(await screen.findByTestId('client-live-project-p-2'))
    expect(screen.getByTestId('client-live-project-p-2')).toHaveTextContent('Project 2')
    await waitFor(() => expect(urls.some((u) => u.startsWith('/projects/p-2/metrics/summary'))).toBe(true))
  })

  it('draws nothing when the space has no dashboard', async () => {
    vi.mocked(fetchPortalOverview).mockRejectedValue(new Error('403'))
    const { container } = renderWithProviders(<ClientLiveDashboard />, { locale: 'en', route: '/portal/nasel' })

    await waitFor(() => expect(container.querySelector('[data-testid="client-live-dashboard"]')).toBeNull())
    expect(urls).toHaveLength(0)
  })

  it('shows delivery and results without a money block when the agency keeps money from the client', async () => {
    vi.mocked(fetchPortalOverview).mockResolvedValue(overview([{ id: 'p-1', name: 'Riwaq' }]) as never)
    renderWithProviders(<ClientLiveDashboard />, { locale: 'en', route: '/portal/nasel' })

    expect(await screen.findByTestId('client-results')).toBeInTheDocument()
    expect(screen.getByTestId('client-platform-snapchat').closest('tr')).toHaveTextContent('6')
    expect(screen.queryByTestId('client-platform-meta')).toBeNull()
    expect(screen.getByTestId('client-campaign-c-1')).toHaveTextContent('Riwaq Snap')
    expect(screen.getByTestId('client-overview').textContent).not.toMatch(/\$\s?0/)
  })
})
