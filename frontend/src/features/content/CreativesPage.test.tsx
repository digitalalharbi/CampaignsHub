import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * The library's acceptance claims (§15.2, §15.4, §15.15).
 *
 * Deliberately NOT «the page renders»: a grid that renders is exactly what the old page did while
 * quietly showing zeros for metrics no platform had sent. What is asserted here is what a reviewer
 * would check by hand — that a filter reaches the SERVER rather than slicing the page in the
 * browser, that no card mounts a video element, and that a missing metric says so.
 */

/**
 * A complete figure set, written out rather than cast.
 *
 * The cast this replaced (`as CreativeMetrics`) let the fixture omit half the keys, which is exactly
 * the shape of drift these tests exist to catch: a fixture that cannot represent «the platform sent
 * nothing for this» is a fixture that cannot fail when the page renders it as zero.
 */
const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 900,
  impressions: 40000,
  clicks: 200,
  conversions: 0,
  revenue: 0,
  video_views: null,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  frequency: 1.2,
  ctr: 0.005,
  cpc: 4.5,
  cpm: 22.5,
  cpa: null,
  roas: 0,
  conversion_rate: 0,
  view_rate: null,
  completion_rate: null,
  active_days: 12,
  // `video_views: false` is the platform SAYING it does not report this — which is what makes it
  // «Not provided» rather than the weaker «No data» an absent key would produce.
  reported: {
    spend: true, impressions: true, clicks: true, conversions: true, revenue: true,
    video_views: false, video_p25: false, video_p50: false, video_p75: false, video_p100: false,
  },
  ...over,
})

const card = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1',
    name: 'Hero image',
    format: 'image',
    provider: 'meta',
    status: 'active',
    campaign_id: 'c1',
    campaign_name: 'National Day Sale',
    preview: {
      state: 'available',
      kind: 'image',
      image_url: 'https://cdn.example.com/a.jpg',
      video_url: null,
      thumbnail_url: 'https://cdn.example.com/a-thumb.jpg',
      expires_at: null,
      note_ar: null,
      note_en: null,
    },
    aspect_ratio: '1:1',
    duration_seconds: null,
    width: 1080,
    height: 1080,
    file_size: 204800,
    grouped: false,
    is_demo: false,
    freshness: {
      last_synced_at: '2026-08-05T10:00:00+00:00',
      source_updated_at: null,
      first_seen_at: null,
      last_active_at: null,
    },
    objective: 'sales',
    path: 'sales',
    headline_metrics: ['spend', 'revenue', 'video_views', 'completion_rate'],
    metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const page = (over: Partial<LibraryPage> = {}): LibraryPage => ({
  creatives: [card()],
  page: 1,
  per_page: 24,
  total: 1,
  period: { from: '2026-07-08', to: '2026-08-06' },
  filters: {
    providers: ['meta', 'tiktok'],
    formats: ['image', 'video'],
    statuses: ['active'],
    kinds: ['image', 'video', 'carousel'],
    campaigns: [{ id: 'c1', name: 'National Day Sale', objective: 'sales' }],
    ad_sets: ['set-1'],
    ads: ['ad-1'],
    objectives: ['sales', 'awareness'],
    paths: ['awareness', 'traffic', 'leads', 'sales'],
    projects: [{ id: 'p1', name: 'Q3 Launch', client_id: 'cl1' }],
    clients: [{ id: 'cl1', name: 'Acme' }],
    health: ['improving', 'stable', 'watch', 'fatigued', 'insufficient_data'],
  },
  ...over,
})

describe('CreativesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listCreatives).mockResolvedValue(page())
  })

  it('offers every axis §15.2 asks for, populated from real rows', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    expect(await screen.findByLabelText('Client')).toBeInTheDocument()
    for (const axis of ['Project', 'Platform', 'Campaign', 'Ad set', 'Ad', 'Objective', 'Marketing path', 'Creative type', 'Status']) {
      expect(screen.getByLabelText(axis)).toBeInTheDocument()
    }
    expect(screen.getByLabelText('Fatigue')).toBeInTheDocument()
  })

  /**
   * A filter has to reach the SERVER.
   *
   * Filtering the loaded page in the browser looks identical on screen and is wrong the moment the
   * result spans more than one page — the count, the pager and the sort all describe a set the
   * filter never touched.
   */
  it('sends a changed filter to the server rather than slicing the loaded page', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByLabelText('Platform')

    fireEvent.change(screen.getByLabelText('Platform'), { target: { value: 'tiktok' } })

    await waitFor(() => {
      const calls = vi.mocked(listCreatives).mock.calls
      const last = calls[calls.length - 1][0]
      expect(last.providers).toEqual(['tiktok'])
    })
  })

  /** An axis nobody touched is omitted, never sent as `[]` — absent and empty differ on the server. */
  it('omits an untouched axis instead of sending an empty list', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByLabelText('Platform')

    const first = vi.mocked(listCreatives).mock.calls[0][0]

    expect(first.providers).toBeUndefined()
    expect(first.client_ids).toBeUndefined()
    expect(first.campaign_ids).toBeUndefined()
  })

  /**
   * A metric the platform did not send says so, beside a genuine zero that does not.
   *
   * Both are falsy in JavaScript, and the old page printed «0» for both — so a creative that had
   * earned nothing and a creative nobody had measured looked identical.
   */
  it('shows Not provided, No data and a real zero as three different things', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    const article = await screen.findByRole('article')

    // Measured, and really nothing: revenue was reported and it was zero.
    expect(within(article).getByText('0 SAR')).toBeInTheDocument()
    // Raw, and never sent: this platform reports no video views for a still image.
    expect(within(article).getByText('Not provided')).toBeInTheDocument()
    // Derived, and not computable: a completion rate over no views is not a rate of zero.
    expect(within(article).getByText('No data')).toBeInTheDocument()
    // And the figure that IS measured still reads as a figure.
    expect(within(article).getByText('900 SAR')).toBeInTheDocument()
  })

  /**
   * No card mounts a video element (§15.7 of the brief: «يمنع تشغيل جميع الفيديوهات تلقائيًا»).
   *
   * A grid of twenty `<video>` tags costs a phone tens of megabytes to open even without autoplay,
   * because each one fetches its own metadata. Cards render a poster; the player exists only inside
   * the viewer, after somebody opens a creative.
   */
  it('renders posters on cards and mounts no video element until a creative is opened', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [
          card({
            id: 'cr-video',
            name: 'Brand film',
            format: 'video',
            duration_seconds: 30,
            preview: {
              state: 'available',
              kind: 'video',
              image_url: null,
              video_url: 'https://cdn.example.com/film.mp4',
              thumbnail_url: 'https://cdn.example.com/film.jpg',
              expires_at: null,
              note_ar: null,
              note_en: null,
            },
          }),
        ],
      }),
    )

    const { container } = renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    expect(container.querySelector('video')).toBeNull()
    expect(screen.getByAltText('Brand film')).toHaveAttribute('loading', 'lazy')
  })

  /** A withheld or absent asset explains itself instead of showing a blank frame. */
  it('says why a preview is missing rather than showing an empty box', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [
          card({
            preview: {
              state: 'withheld',
              kind: 'image',
              image_url: null,
              video_url: null,
              thumbnail_url: null,
              expires_at: null,
              note_ar: 'رابط المعاينة من المنصة يحمل بيانات اعتماد، فلا يُعرض.',
              note_en: 'The platform’s preview link carries a credential, so it is not shown.',
            },
          }),
        ],
      }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    expect(await screen.findByText(/carries a credential/)).toBeInTheDocument()
  })

  it('switches between grid and list without refetching', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const before = vi.mocked(listCreatives).mock.calls.length
    fireEvent.click(screen.getByRole('button', { name: /List/ }))

    expect(await screen.findByRole('table')).toBeInTheDocument()
    expect(vi.mocked(listCreatives).mock.calls.length).toBe(before)
  })
})
