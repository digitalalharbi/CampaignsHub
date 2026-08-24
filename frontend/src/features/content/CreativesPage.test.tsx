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
    ad_delivered: false,
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
  currency: 'SAR',
  metrics_availability: { meta: { status: 'success', rows: 12, error: null, at: null } },
  filters: {
    providers: ['meta', 'tiktok'],
    formats: ['image', 'video'],
    statuses: ['active'],
    kinds: ['image', 'video', 'carousel'],
    campaigns: [{ id: 'c1', name: 'National Day Sale', objective: 'sales' }],
    ad_sets: ['set-1'],
    ads: [{ value: 'ad-1', label: 'ad-1' }],
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

  /**
   * UX-CONTENT-001 — the axes a reviewer uses daily are ON the page.
   *
   * The predecessor of this test opened a dialog first. That is the change: somebody who came to
   * LOOK at creatives should not have to discover that the library can be narrowed at all.
   */
  it('puts every daily axis §15.2 asks for on the page, populated from real rows', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    const bar = within(screen.getByTestId('content-filters'))

    for (const axis of ['Client', 'Project', 'Platform', 'Campaign', 'Objective', 'Marketing path', 'Creative type', 'Fatigue']) {
      expect(bar.getByText(axis)).toBeInTheDocument()
    }
    // Reachable without opening anything.
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    // The values come from the rows the server returned, not from a hardcoded list.
    // Visible without opening anything — that IS the requirement now.
    expect(screen.getByTestId('content-providers-tiktok')).toBeInTheDocument()
  })

  /** The rare axes still fold — that is what «More filters» is for. */
  it('folds status, ad set and ad, and nothing else', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    fireEvent.click(screen.getByTestId('content-more-filters'))
    const dialog = within(await screen.findByTestId('content-more-filters-body'))

    for (const axis of ['Status', 'Ad set', 'Ad']) {
      expect(dialog.getByText(axis)).toBeInTheDocument()
    }
    expect(dialog.queryByText('Platform')).not.toBeInTheDocument()
  })

  /**
   * A narrowed library names what narrowed it, and each chip undoes exactly its own value.
   *
   * This is what the old applied-summary SENTENCE could not do: it could say «2 platforms» and
   * offer no way to drop one of them, so the only way back was clearing everything.
   */
  it('names each applied filter as a chip and removes only that one', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    expect(screen.queryByTestId('content-applied')).not.toBeInTheDocument()

    // UX-FILTERS-001 — the platforms are visible chips now, so choosing one is a single press
    // rather than «open the popover, then pick». The assertion below is unchanged: what matters is
    // that the choice reaches the SERVER, not how the control is shaped.
    fireEvent.click(screen.getByTestId('content-providers-tiktok'))
    fireEvent.click(screen.getByTestId('content-providers-meta'))

    await waitFor(() => expect(screen.getByTestId('content-applied-providers:tiktok')).toBeInTheDocument())
    expect(screen.getByTestId('content-applied-providers:meta')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remove Platform: TikTok' }))

    await waitFor(() => expect(screen.queryByTestId('content-applied-providers:tiktok')).not.toBeInTheDocument())
    expect(screen.getByTestId('content-applied-providers:meta')).toBeInTheDocument()
  })

  /** One Reset, because clearing eight controls by hand is how people end up reloading the page. */
  it('clears every filter at once, folded ones included', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findByRole('article')

    // UX-FILTERS-001 — the platforms are visible chips now, so choosing one is a single press
    // rather than «open the popover, then pick». The assertion below is unchanged: what matters is
    // that the choice reaches the SERVER, not how the control is shaped.
    fireEvent.click(screen.getByTestId('content-providers-tiktok'))
    await waitFor(() => expect(screen.getByTestId('content-applied-providers:tiktok')).toBeInTheDocument())

    fireEvent.click(screen.getByTestId('content-reset'))

    await waitFor(() => expect(screen.queryByTestId('content-applied')).not.toBeInTheDocument())
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

    // UX-FILTERS-001 — the platforms are visible chips now, so choosing one is a single press
    // rather than «open the popover, then pick». The assertion below is unchanged: what matters is
    // that the choice reaches the SERVER, not how the control is shaped.
    fireEvent.click(screen.getByTestId('content-providers-tiktok'))

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

  /*
   * ── CONTENT-STATE-SEMANTICS-001 — on the RENDERED card ───────────────────────────────────────
   *
   * A helper test cannot catch a page that never calls the helper, and this product has already
   * shipped one canonical reader that nothing was wired to. These render the real library.
   */

  it('tells a creative that did not run apart from one whose data failed to arrive', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({ metrics: null })],
        metrics_availability: { meta: { status: 'success', rows: 814, error: null, at: null } },
      }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect(await screen.findByTestId('creative-empty-did_not_run')).toHaveTextContent('لم يعمل خلال هذه الفترة')
    expect(screen.queryByText('لا توجد بيانات')).not.toBeInTheDocument()
  })

  it('shows a failed fetch as a warning carrying the provider reason', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({ metrics: null })],
        metrics_availability: {
          meta: { status: 'failed', rows: 0, error: 'Rate limited by the platform (429).', at: null },
        },
      }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    const el = await screen.findByTestId('creative-empty-failed')

    expect(el).toHaveTextContent(/could not be fetched/i)
    expect(el).toHaveTextContent('Rate limited by the platform (429).')
  })

  it('says a platform reports no creative performance rather than implying data is missing', async () => {
    vi.mocked(listCreatives).mockResolvedValue(
      page({
        creatives: [card({ metrics: null, provider: 'tiktok' })],
        metrics_availability: { tiktok: { status: 'unsupported', rows: 0, error: null, at: null } },
      }),
    )

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    expect(await screen.findByTestId('creative-empty-unsupported')).toBeInTheDocument()
  })

  /** A creative WITH figures keeps its metric grid — the reason replaces nothing real. */
  it('leaves a delivering creative its numbers', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page())

    renderWithProviders(<CreativesPage />, { locale: 'en' })

    await screen.findByText('Hero image')

    expect(screen.queryByTestId(/creative-empty-/)).not.toBeInTheDocument()
  })

})
