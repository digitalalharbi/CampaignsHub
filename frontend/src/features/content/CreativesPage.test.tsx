import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { groupCreatives, listCreatives } from './api'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

const signIn = (permissions: string[]) =>
  useAuth.setState({
    user: { id: '1', name: 'Op', permissions, is_platform_admin: false } as unknown as AuthUser,
    status: 'authenticated',
  })

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
    signIn(['campaigns.view', 'campaigns.link'])
    vi.mocked(listCreatives).mockResolvedValue(page())
  })

  /** Opens the folded filter dialog and hands back its body — the controls, without the modal chrome. */
  const openFilters = async () => {
    fireEvent.click(await screen.findByTestId('content-customise'))
    return within(await screen.findByTestId('content-customise-body'))
  }

  it('offers every axis §15.2 asks for, populated from real rows', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const filters = await openFilters()

    // The closed sets are chips, named by their group heading and offering the real values.
    for (const group of ['Platform', 'Creative type', 'Status', 'Fatigue', 'Objective', 'Marketing path']) {
      expect(filters.getByText(group)).toBeInTheDocument()
    }
    expect(filters.getByRole('button', { name: 'TikTok' })).toBeInTheDocument()
    expect(filters.getByRole('button', { name: 'Fatigued' })).toBeInTheDocument()

    // The open-ended id lists stay selects — a chip row of four hundred campaigns is not a control.
    for (const axis of ['Client', 'Project', 'Campaign', 'Ad set', 'Ad']) {
      expect(filters.getByLabelText(axis)).toBeInTheDocument()
    }
  })

  /**
   * SIMPLIFY-001: folding the controls must hide no state.
   *
   * The dialog is shut for all but a few seconds of the page's life, so the applied line beside the
   * button is the ONLY thing standing between a narrowed library and a library that merely looks
   * short. It states what is applied in words — never `providers[]=meta`.
   */
  it('says what is applied in words, before and after narrowing', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    expect(screen.getByTestId('content-applied')).toHaveTextContent('All content')
    expect(screen.queryByTestId('content-clear')).not.toBeInTheDocument()

    const filters = await openFilters()
    fireEvent.click(filters.getByRole('button', { name: 'TikTok' }))

    await waitFor(() => expect(screen.getByTestId('content-applied')).toHaveTextContent('TikTok'))
    // Having narrowed it, the page offers a way back to everything.
    expect(screen.getByTestId('content-clear')).toBeInTheDocument()
  })

  /** More than one value of an axis collapses to a count, so the line stays a sentence. */
  it('counts a multi-value axis rather than listing it', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const filters = await openFilters()
    fireEvent.click(filters.getByRole('button', { name: 'TikTok' }))
    fireEvent.click(filters.getByRole('button', { name: 'Meta' }))

    await waitFor(() => expect(screen.getByTestId('content-applied')).toHaveTextContent('2 platforms'))
  })

  /** Clearing puts every folded filter back, and the line says so without being read for it. */
  it('clears every folded filter at once', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const filters = await openFilters()
    fireEvent.click(filters.getByRole('button', { name: 'TikTok' }))
    await waitFor(() => expect(screen.getByTestId('content-applied')).toHaveTextContent('TikTok'))

    fireEvent.click(screen.getByTestId('content-clear'))

    await waitFor(() => expect(screen.getByTestId('content-applied')).toHaveTextContent('All content'))
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
    await screen.findByRole('article')

    const filters = await openFilters()
    fireEvent.click(filters.getByRole('button', { name: 'TikTok' }))

    await waitFor(() => {
      const calls = vi.mocked(listCreatives).mock.calls
      const last = calls[calls.length - 1][0]
      expect(last.providers).toEqual(['tiktok'])
    })
  })

  /** An axis nobody touched is omitted, never sent as `[]` — absent and empty differ on the server. */
  it('omits an untouched axis instead of sending an empty list', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

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

  /**
   * The landing half of §15.11's drill-down: the address IS the opening selection.
   *
   * The dashboard's cards link here carrying the filters and period they were computed under. If the
   * page ignored them, «best video» would land on an unfiltered library — a different set of
   * creatives than the card the reader clicked, which is how two surfaces come to look like they
   * disagree when they are reading the same pipeline.
   */
  it('opens on the filters the address carries', async () => {
    renderWithProviders(<CreativesPage />, {
      locale: 'en',
      route: '/app/content?from=2026-01-01&to=2026-01-31&providers[]=tiktok&campaign_ids[]=c1&health=fatigued',
    })

    await screen.findByRole('article')

    expect(vi.mocked(listCreatives)).toHaveBeenCalledWith(
      expect.objectContaining({
        from: '2026-01-01',
        to: '2026-01-31',
        providers: ['tiktok'],
        campaign_ids: ['c1'],
        health: 'fatigued',
      }),
      null,
    )
  })

  /** `?creative=<id>` is the last rung of the drill-down — it opens that creative, not that index. */
  it('opens the creative the address names', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({ id: 'cr-1', name: 'Hero image' }), card({ id: 'cr-2', name: 'Brand film' })],
        total: 2,
      }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?creative=cr-2' })

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Brand film')).toBeInTheDocument()
  })

  /** An id that this selection filtered out leaves the library open — it does not open a neighbour. */
  it('opens nothing when the named creative is not in the results', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?creative=cr-missing' })

    await screen.findByRole('article')

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('switches between grid and list without refetching', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const before = vi.mocked(listCreatives).mock.calls.length
    fireEvent.click(screen.getByRole('button', { name: /List/ }))

    expect(await screen.findByRole('table')).toBeInTheDocument()
    expect(vi.mocked(listCreatives).mock.calls.length).toBe(before)
  })
  /**
   * §15.8 — merging is offered from the library, because that is where the duplicates are visible.
   *
   * The address of the resulting group is offered straight away: a merge whose only confirmation is
   * a toast leaves the reader to go and find the thing they just made.
   */
  it('merges a selection into one asset and offers the group it made', async () => {
    vi.mocked(groupCreatives).mockResolvedValue({
      id: 'grp-9',
      name: 'Brand film',
      method: 'manual',
      creative_ids: ['cr-1', 'cr-2'],
    })

    vi.mocked(listCreatives).mockResolvedValue(
      page({ creatives: [card(), card({ id: 'cr-2', name: 'Brand film' })], total: 2 }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findAllByRole('article')

    for (const name of ['Compare: Hero image', 'Compare: Brand film']) {
      fireEvent.click(screen.getByRole('checkbox', { name }))
    }

    fireEvent.click(screen.getByRole('button', { name: /Merge as one asset/ }))

    await waitFor(() => expect(vi.mocked(groupCreatives)).toHaveBeenCalledWith(['cr-1', 'cr-2']))
    expect(await screen.findByText('2 creatives were merged as one asset.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open group' })).toHaveAttribute(
      'href',
      expect.stringContaining('groups?group=grp-9'),
    )
  })

  /** Without `campaigns.link` the control is absent — not present and refusing. */
  it('offers no merge control without the link permission', async () => {
    signIn(['campaigns.view'])

    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Hero image' }))

    expect(screen.queryByRole('button', { name: /Merge as one asset/ })).not.toBeInTheDocument()
  })

  it('says plainly when a merge was refused rather than failing silently', async () => {
    vi.mocked(groupCreatives).mockRejectedValue(new Error('422'))

    vi.mocked(listCreatives).mockResolvedValue(
      page({ creatives: [card(), card({ id: 'cr-2', name: 'Brand film' })], total: 2 }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findAllByRole('article')

    for (const name of ['Compare: Hero image', 'Compare: Brand film']) {
      fireEvent.click(screen.getByRole('checkbox', { name }))
    }
    fireEvent.click(screen.getByRole('button', { name: /Merge as one asset/ }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/same project/)
  })
})
