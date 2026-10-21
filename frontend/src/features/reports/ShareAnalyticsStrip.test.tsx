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

  /** REPORT-LINK-ANALYTICS-003 — the link's own state beside its figures, and the window against the one before. */
  it('names a paused link as paused since when, and compares the window with the one before it', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({
      views: 3, unique_views: 2, first_viewed_at: '2026-10-10T09:00:00Z', last_viewed_at: '2026-10-13T09:00:00Z', downloads: 2,
      state: 'paused', state_since: '2026-10-15T08:00:00Z',
      comparison: {
        current: { from: '2026-10-10', to: '2026-10-14', views: 3, devices: 2, downloads: 2 },
        previous: { from: '2026-10-05', to: '2026-10-09', views: 2, devices: 2, downloads: 0 },
      },
    })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'ar' })

    const state = await screen.findByTestId('share-state')
    expect(state).toHaveAttribute('data-state', 'paused')
    expect(state).toHaveTextContent('الرابط متوقف')
    expect(state).toHaveTextContent(/15.?\/10.?\/2026/)
    expect(screen.getByTestId('share-analytics')).toHaveTextContent('التنزيلات')

    const cmp = screen.getByTestId('share-comparison')
    expect(cmp).toHaveTextContent('2026-10-10 → 2026-10-14 مقابل 2026-10-05 → 2026-10-09')
    expect(screen.getByTestId('share-compare-views')).toHaveTextContent('+50%')
    expect(screen.getByTestId('share-compare-devices')).toHaveTextContent('0%')
    expect(screen.getByTestId('share-compare-downloads')).toHaveTextContent('+∞')
  })

  it('draws neither a state nor a comparison from an older server that sends none', async () => {
    vi.mocked(shareAnalytics).mockResolvedValue({ views: 1, unique_views: 1, first_viewed_at: null, last_viewed_at: null, downloads: 0 })
    renderWithProviders(<ShareAnalyticsStrip projectId="p" reportId="r" shareId="s" />, { locale: 'en' })
    await screen.findByTestId('share-analytics')
    expect(screen.queryByTestId('share-state')).toBeNull()
    expect(screen.queryByTestId('share-comparison')).toBeNull()
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
