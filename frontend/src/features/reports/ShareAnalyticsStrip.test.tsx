import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
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
    expect(strip).toHaveTextContent('Unique visitors')
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
})
