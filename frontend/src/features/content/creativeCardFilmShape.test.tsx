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
 * CONTENT-PREVIEW-SHAPES-001 — «a story is contained, never covered» applies to the FILM too.
 *
 * The still branch of the card has honoured that rule since it was written. The film branch was
 * `object-cover` with no shape in it at all, so a 9:16 creative whose frame does not match its
 * declared shape — every film whose platform returned a landscape cover, and every one in the demo —
 * is cropped to the middle third of a portrait box. On a story that is the logo and the call to
 * action, which is the exact loss the rule names.
 *
 * Measured on the library before the fix: a 480×270 frame drawn `object-fit: cover` inside a 225×399
 * box. The fixture is a film with NO thumbnail and no image, because that is the payload that
 * reaches the film branch — a card with a still never gets there.
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

  it('is contained when the creative is a story, so nothing is cropped away', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page(film({})))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect((await filmElement()).className).toContain('object-contain')
  })

  /**
   * CONTENT-PREVIEW-FIT-001 — and a landscape film is contained too, which this used to forbid.
   *
   * «A landscape film fills its own landscape box» was true about the AD and not about the FILE.
   * The declared ratio describes what was bought; the frame the platform actually returns is
   * frequently a different shape — a landscape cover for a 9:16 video is routine, which this
   * codebase had already written down — so matching the declaration against the stage licensed a
   * crop on evidence that does not say what it was read to say. Measured on the seeded library,
   * six cards were drawn at 0.563 and 1.775 against an intrinsic 1.000 under exactly that rule.
   *
   * Containing costs a landscape film nothing when it really is landscape: the stage is already
   * `aspect-video`, so the two fits are identical pixels. What it buys is that the one time the
   * file is not the shape it was declared to be, the reader sees the whole ad instead of its middle.
   */
  it('is contained when the creative is landscape, because the declaration is not the file', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page(film({
      name: 'Story film', aspect_ratio: '16:9', width: 1920, height: 1080,
    })))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect((await filmElement()).className).toContain('object-contain')
  })
})
