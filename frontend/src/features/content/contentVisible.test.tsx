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

/**
 * CONTENT-PREVIEW-SHAPES-001 — the GRID names the shape it cannot draw.
 *
 * The library grid does not render through `AdPoster`; it builds its own card, and for anything with
 * no still it said «لا تتوفر معاينة» — one sentence for every shape. `absenceLabel` has had a
 * written sentence for each of them since the shapes requirement shipped, and the one surface the
 * owner actually opens was the one surface not asking the module whose whole job is to answer this.
 *
 * A catalog ad is what makes that wrong rather than merely vague. Nothing about it is missing — the
 * platform composes one image per product at delivery — so «no preview available» describes a fault
 * that does not exist and sends somebody looking for a sync problem. Six of the live Meta account's
 * twelve first-page creatives are catalog ads.
 */
describe('the grid names the shape it cannot draw', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('says a catalog ad has no fixed asset, rather than that it has no preview', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [
          /*
           * A drawable card beside it, because the grid deliberately collapses the media area when
           * NOTHING on the page can be drawn and explains it once in a banner instead. The live Meta
           * first page is mixed — six drawable, six catalog — so a fixture of catalog ads alone would
           * be testing the empty-result path, not the card.
           */
          card({ id: 'ordinary-1', name: 'A real still' }),
          card({
            id: 'cat-1',
            name: '{{product.name}} 2026-08-01',
            format: 'catalog',
            preview: {
              state: 'available',
              kind: 'catalog',
              aspect: null,
              image_url: null,
              video_url: null,
              thumbnail_url: null,
              expires_at: null,
              note_ar: null,
              note_en: null,
            },
          }),
        ],
      }) as never,
    )

    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    const stated = await screen.findByTestId('creative-absence-reason')

    expect(stated.textContent ?? '').toMatch(/كتالوج/)
    expect(stated.textContent ?? '').not.toMatch(/لا تتوفر معاينة/)
  })
})
