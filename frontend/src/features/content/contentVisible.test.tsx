import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * The two defects an owner could see on production, asserted on the rendered library.
 *
 * Both are the same underlying mistake: the product held the thing and displayed «nothing».
 */
const PREVIEW = {
  state: 'available' as const,
  kind: 'video' as const,
  image_url: null,
  video_url: 'https://cf.snapchat.com/me-1.mp4',
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
}

/** Production's exact shape: a USD account with no USD→SAR rate, so the figure is withheld. */
const WITHHELD = {
  spend: null,
  spend_original: 412.5,
  spend_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
  impressions: 90000,
  clicks: 300,
  reported: { spend: true, impressions: true, clicks: true },
}

const card = (over: Record<string, unknown> = {}) => ({
  id: 'c1',
  name: 'Summer hero',
  format: 'video',
  provider: 'snapchat',
  status: 'active',
  campaign_id: 'camp1',
  campaign_name: 'Always-On',
  ad_set_id: null,
  ad_id: null,
  ads: [],
  preview: PREVIEW,
  aspect_ratio: null,
  duration_seconds: null,
  width: null,
  height: null,
  file_size: null,
  source_type: 'api',
  creative_group_id: null,
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
  objective: 'sales',
  path: 'conversion',
  headline_metrics: ['spend', 'impressions'],
  ad_delivered: false,
  metrics: WITHHELD,
  fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
  ...over,
})

const page = (over: Record<string, unknown> = {}) => ({
  creatives: [card()],
  page: 1,
  per_page: 24,
  total: 1,
  period: { from: '2026-07-25', to: '2026-08-23' },
  currency: 'SAR',
  metrics_availability: { snapchat: { status: 'success', rows: 819, error: null, at: null } },
  filters: {
    providers: ['snapchat'], statuses: [], kinds: [], campaigns: [], ad_sets: [], ads: [],
    objectives: [], paths: [], projects: [], clients: [], health: [],
  },
  ...over,
})

describe('what the owner sees on /content', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  /**
   * P0-E — real spend rendered as «No data».
   *
   * `metricState` reads only the CONVERTED column, and on production every Snapchat row is
   * withheld. So 412.50 USD of measured spend displayed as though the creative had never run.
   */
  it('shows withheld spend as its real amount and currency, never as No data', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page() as never)

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await screen.findByText('Summer hero')

    expect(screen.getByText(/412\.50 USD/)).toBeInTheDocument()
    expect(screen.queryByText(/^No data$/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Not provided/)).not.toBeInTheDocument()
    expect(screen.queryByText(/0 SAR/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Currency not stated/)).not.toBeInTheDocument()
  })

  /**
   * P0-A — a video creative with no thumbnail rendered «No preview».
   *
   * Snapchat supplies the file as `video_url` and frequently no separate poster, so the card
   * claimed to have nothing while holding the asset itself.
   */
  it('shows a video creative that has no thumbnail, rather than claiming no preview', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page() as never)

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await screen.findByText('Summer hero')

    const poster = await screen.findByTestId('creative-video-poster')

    expect(poster).toBeInTheDocument()
    expect(poster.getAttribute('src')).toContain('me-1.mp4')
    // Nothing autoplays and nothing preloads the whole file — a grid of these must stay cheap.
    expect(poster).not.toHaveAttribute('autoplay')
    expect(poster.getAttribute('preload')).toBe('metadata')
  })

  /** A creative with genuinely no asset still says so — the fallback must not be swallowed. */
  it('still says no preview when there is no asset at all', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({ preview: { ...PREVIEW, video_url: null, kind: 'image' } })],
      }) as never,
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await screen.findByText('Summer hero')

    expect(screen.queryByTestId('creative-video-poster')).not.toBeInTheDocument()
  })

  /** A converted figure still renders in the reporting currency the payload names. */
  it('renders a converted figure in the reporting currency', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({
          metrics: { ...WITHHELD, spend: 1500, spend_original: null, spend_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 },
        })],
      }) as never,
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await screen.findByText('Summer hero')

    expect(screen.getByText(/1,500 SAR/)).toBeInTheDocument()
  })
})
