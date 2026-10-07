import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * CONTENT-COVER-FILL-001 — the card's film and its still are ONE cover, and the cover fills.
 *
 * ## What this file used to assert, and why it changed
 *
 * It held «a story is contained, never covered» for the film branch, matching the still branch. That
 * rule was right about the surface it was written for and the owner has confirmed it on that surface:
 * the opened viewer shows the whole ad at its own dimensions, and its guard is untouched.
 *
 * The CARD is a different surface, and the owner named it directly: «the cover must be the full
 * cover, not a tall shape … so it holds an image that fills the whole cover, not only a portrait
 * strip». Containing inside a frame that also took each asset's own aspect produced a wall of strips
 * of different heights, which is what he was looking at.
 *
 * So the card's frame is one square for every creative and both branches fill it. What stays is the
 * thing the two branches must never disagree about: a still and a film of the same creative are the
 * same cover, drawn the same way — which is what this file has always really been guarding.
 *
 * The crop is real. A story's logo and call to action sit at the top and bottom, and they are one
 * click away in the viewer rather than on the tile.
 */
/** Enough of a metrics payload for the card to render its grid; the figures are not the subject. */
const METRICS = {
  spend: 1200, spend_original: null, spend_withheld_rows: 0,
  revenue: null, revenue_original: null, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
  impressions: 40000, clicks: 500, ctr: 0.0125, conversions: 10, orders: 10,
  reach: null, frequency: null, cpc: null, cpm: null, cpa: null, roas: null,
  conversion_rate: 0.02, video_views: 900,
  video_p25: null, video_p50: null, video_p75: null, video_p100: null,
  view_rate: null, completion_rate: null, active_days: 7,
  reported: { clicks: true, conversions: true, impressions: true, orders: true, spend: true },
} as unknown as CreativeMetrics

function film(over: Partial<CreativeCard>): CreativeCard {
  return {
    id: 'cr-1',
    name: 'Story film',
    format: 'video',
    provider: 'snapchat',
    status: 'active',
    campaign_id: 'cmp-1',
    campaign_name: 'Launch',
    preview: {
      state: 'available',
      kind: 'video',
      image_url: null,
      video_url: 'https://cdn.example.test/story.mp4',
      thumbnail_url: null,
      expires_at: null,
      note_ar: null,
      note_en: null,
    },
    aspect_ratio: '9:16',
    duration_seconds: 12,
    width: 1080,
    height: 1920,
    grouped: false,
    is_demo: false,
    freshness: {
      last_synced_at: '2026-09-27T08:00:00+00:00',
      source_updated_at: null,
      first_seen_at: null,
      last_active_at: '2026-09-27T00:00:00+00:00',
    },
    objective: 'SALES',
    path: 'awareness',
    headline_metrics: ['spend', 'impressions', 'clicks', 'ctr'],
    ad_delivered: true,
    metrics: METRICS,
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  } as unknown as CreativeCard
}

function page(card: CreativeCard): LibraryPage {
  return {
    creatives: [card],
    page: 1,
    per_page: 24,
    total: 1,
    period: { from: '2026-08-29', to: '2026-09-27' },
    currency: 'SAR',
    metrics_availability: {},
    filters: {
      providers: ['snapchat'], formats: ['video'], statuses: ['active'], kinds: ['video'],
      campaigns: [], ad_sets: [], ads: [], objectives: [], paths: [],
      projects: [], clients: [], health: [],
    },
  } as unknown as LibraryPage
}

async function filmElement(): Promise<HTMLVideoElement> {
  await waitFor(() => expect(screen.getByText('Story film')).toBeInTheDocument())
  const video = document.querySelector('video')
  expect(video, 'the card never reached the film branch').not.toBeNull()

  return video as HTMLVideoElement
}

describe('a film on a content card', () => {
  beforeEach(() => {
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
  })

  it('fills its cover when the creative is a story, rather than making the card tall', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page(film({})))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect((await filmElement()).className).toContain('object-cover')
  })

  /**
   * And a landscape film fills the same square, for the same reason.
   *
   * The fit no longer consults the declared ratio at all on this surface, which also removes the
   * trap the previous rule was written around: the declaration describes what was BOUGHT, and a
   * platform returning a landscape cover for a 9:16 video is routine, so a fit that branched on the
   * declaration branched on evidence that does not say what it was read to say. A cover fills,
   * whatever arrives.
   */
  it('fills its cover when the creative is landscape, on the same rule and without consulting the declaration', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page(film({
      name: 'Story film', aspect_ratio: '16:9', width: 1920, height: 1080,
    })))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect((await filmElement()).className).toContain('object-cover')
  })
})
