import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ShareAnalyticsStrip } from './ShareAnalyticsStrip'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, shareAnalytics: vi.fn() }
})

import { shareAnalytics } from './api'

/** REPORT-LINK-ANALYTICS-001 — four Owner-only facts, counts and dates and nothing that identifies a person. */
describe('ShareAnalyticsStrip', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows total views, unique visitors, first and last viewed', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({ views: 12, unique_views: 4, first_viewed_at: '2026-10-10T09:00:00Z', last_viewed_at: '2026-10-16T18:30:00Z', downloads: 1 })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'en' })

    const strip = await screen.findByTestId('share-analytics')
    expect(strip).toHaveTextContent('Views')
    expect(strip).toHaveTextContent('12')
    expect(strip).toHaveTextContent('Unique devices (estimate)')
    expect(strip).toHaveTextContent('4')
    expect(strip).toHaveTextContent(/10\/10\/2026/)
    expect(strip).toHaveTextContent(/16\/10\/2026/)
  })

  it('says a never-opened link was never opened, with dashes rather than a made-up date', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({ views: 0, unique_views: 0, first_viewed_at: null, last_viewed_at: null, downloads: 0 })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'ar' })

    const strip = await screen.findByTestId('share-analytics')
    expect(strip).toHaveTextContent('مرات الفتح')
    expect(strip.textContent?.match(/—/g)).toHaveLength(2)
  })

  it('draws nothing when the viewer is not allowed to see analytics', async () => {
    vi.mocked(shareAnalytics).mockRejectedValue({ isAxiosError: true, response: { status: 403, data: {} } })
    const { container } = renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'en' })

    await vi.waitFor(() => expect(container.querySelector('.animate-pulse')).toBeNull())
    expect(container.querySelector('[data-testid="share-analytics"]')).toBeNull()
  })

  it('draws the trend with its grain switch, counts repeat opens, and says what it left out', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({
      views: 6, unique_views: 2, first_viewed_at: '2026-10-10T09:00:00Z', last_viewed_at: '2026-10-13T09:00:00Z', downloads: 0,
      repeat_visits: 4, bot_opens: 3, unique_basis: 'device_estimate', granularity: 'day',
      period: { from: '2026-10-10', to: '2026-10-12' },
      series: [{ period: '2026-10-10', views: 3, devices: 1 }, { period: '2026-10-11', views: 0, devices: 0 }, { period: '2026-10-12', views: 3, devices: 2 }],
    })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'en' })

    expect(await screen.findByTestId('share-trend')).toBeTruthy()
    expect(screen.getByTestId('share-analytics')).toHaveTextContent('Repeat opens')
    expect(screen.getByTestId('share-analytics')).toHaveTextContent('4')
    expect(screen.getByTestId('share-bot-note')).toHaveTextContent('3 automated opens left out')

    fireEvent.click(screen.getByTestId('share-grain-week'))
    await vi.waitFor(() => expect(shareAnalytics).toHaveBeenLastCalledWith('p', 'r', 's', 'week'))
    expect(screen.getByTestId('share-grain-week').getAttribute('aria-pressed')).toBe('true')
  })

  it('draws no chart for a link nobody has opened, and the Arabic strip names devices as an estimate', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({
      views: 0, unique_views: 0, first_viewed_at: null, last_viewed_at: null, downloads: 0, repeat_visits: 0, bot_opens: 0,
      series: [{ period: '2026-10-10', views: 0, devices: 0 }],
    })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'ar' })

    const strip = await screen.findByTestId('share-analytics')
    expect(strip).toHaveTextContent('أجهزة فريدة (تقديري)')
    expect(screen.queryByTestId('share-trend')).toBeNull()
  })
})
