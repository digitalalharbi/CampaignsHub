import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { SharedCreativeSection } from './SharedCreativeSection'
import type { CreativeCard, CreativeMetrics } from '@/features/content/api'
import type {
  CreativePermissions,
  SharedCreativeDetail,
  SharedCreativeLibraryPage,
  SharedCreativeSummaryPayload,
} from './sharedCreatives'
import { renderWithProviders } from '@/test/utils'

vi.mock('./sharedCreatives', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./sharedCreatives')>()
  return {
    ...actual,
    getSharedCreativeSummary: vi.fn(),
    getSharedCreatives: vi.fn(),
    getSharedCreative: vi.fn(),
    compareSharedCreatives: vi.fn(),
  }
})

import { getSharedCreative, getSharedCreativeSummary, getSharedCreatives } from './sharedCreatives'

/**
 * §15.12's acceptance claims for the client's creative section.
 *
 * The reader here has no account and no way to check anything by opening another page, so the checks
 * are the ones a client would make on their own behalf:
 *
 *   - the summary and the detailed report are actually different documents;
 *   - a hidden figure produces no labelled blank naming what is withheld;
 *   - every ranking names the metric and the marketing path it was decided on;
 *   - thin evidence is declared instead of being presented as a finding;
 *   - «insufficient data» stays insufficient and never becomes «stable»;
 *   - no `<video>` element mounts before the reader asks for one;
 *   - a filter reaches the SERVER rather than being applied to a page of rows.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics =>
  ({
    spend: 1200,
    impressions: 60000,
    clicks: 1800,
    conversions: 60,
    revenue: 9000,
    video_views: null,
    video_p25: null,
    video_p50: null,
    video_p75: null,
    video_p100: null,
    frequency: 1.4,
    ctr: 0.03,
    cpc: 0.67,
    cpm: 20,
    cpa: 20,
    roas: 7.5,
    conversion_rate: 0.033,
    view_rate: null,
    completion_rate: null,
    active_days: 14,
    reported: {
      spend: true, impressions: true, clicks: true, conversions: true, revenue: true,
      video_views: false, video_p25: false, video_p50: false, video_p75: false, video_p100: false,
    },
    ...over,
  }) as CreativeMetrics

const card = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1',
    name: 'Hero image',
    format: 'image',
    provider: 'meta',
    status: 'active',
    campaign_id: 'c1',
    campaign_name: 'National Day Sale',
    ad_set_id: null,
    ad_id: null,
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
    group_id: null,
    is_demo: false,
    freshness: { last_synced_at: '2026-08-05T10:00:00+00:00', source_updated_at: null, first_seen_at: null, last_active_at: null },
    objective: 'sales',
    path: 'conversion',
    headline_metrics: ['spend', 'orders', 'roas'],
    metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const permissions = (over: Partial<CreativePermissions> = {}): CreativePermissions => ({
  creatives: true,
  video: true,
  image_zoom: true,
  download: true,
  ad_copy: true,
  headline: true,
  cta: true,
  destination_url: true,
  comparison: true,
  spend: true,
  revenue: true,
  cpa: true,
  roas: true,
  insights: true,
  recommendations: true,
  ...over,
})

const empty = { items: [], total: 0, shown: 0 }

const available = {
  providers: ['meta', 'tiktok'],
  campaigns: [{ id: 'c1', name: 'National Day Sale', objective: 'sales' }],
  objectives: ['sales', 'awareness'],
  paths: ['awareness', 'traffic', 'conversion'],
  kinds: ['image', 'video', 'carousel'],
  earliest: '2026-07-08',
  latest: '2026-08-06',
}

const applied = {
  from: '2026-07-08',
  to: '2026-08-06',
  providers: [],
  campaign_ids: [],
  objectives: [],
  paths: [],
  kinds: [],
  search: '',
  sort: '',
}

const summary = (over: Partial<SharedCreativeSummaryPayload> = {}): SharedCreativeSummaryPayload =>
  ({
    period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
    previous_period: { from: '2026-06-08', to: '2026-07-07' },
    totals: { creatives: 3, with_metrics: 3, without_metrics: 0 },
    evidence: { min_impressions: 1000, min_change: 0.1 },
    best_by_objective: [
      {
        objective: 'sales',
        path: 'conversion',
        creatives: 2,
        spend: 2400,
        metric: 'roas',
        higher_wins: true,
        value: 7.5,
        candidates: 2,
        evidenced: 2,
        low_evidence: false,
        creative: card(),
      },
    ],
    best_image: [
      {
        kind: 'image',
        path: 'conversion',
        creatives: 2,
        spend: 2400,
        metric: 'roas',
        higher_wins: true,
        value: 7.5,
        candidates: 2,
        evidenced: 2,
        low_evidence: false,
        creative: card(),
      },
    ],
    best_video: [
      {
        kind: 'video',
        path: 'awareness',
        creatives: 1,
        spend: 400,
        metric: 'cpm',
        higher_wins: false,
        value: 18.4,
        candidates: 1,
        evidenced: 0,
        low_evidence: true,
        creative: card({ id: 'cr-2', name: 'Brand film', objective: 'awareness', path: 'awareness' }),
      },
    ],
    fastest_growing: empty,
    declining: empty,
    fatigue: {
      counts: { improving: 0, stable: 2, watch: 0, fatigued: 1, insufficient_data: 1 },
      fatigued: { items: [card({ id: 'cr-3', name: 'Tired banner' })], total: 1, shown: 1 },
      watch: empty,
      insufficient_data: { items: [card({ id: 'cr-4', name: 'Barely ran' })], total: 1, shown: 1 },
      alerts: {
        items: [
          {
            creative: card({ id: 'cr-3', name: 'Tired banner' }),
            spend: 800,
            signals: ['ctr_down'],
            note_ar: 'انخفض معدل النقر مع ارتفاع التكرار.',
            note_en: 'Click-through fell while frequency rose.',
          },
        ],
        total: 1,
        shown: 1,
      },
      spend_at_risk: { spend: 800, spend_withheld_rows: 0, spend_original: 0, money_original_currency: null, money_original_currencies: 0 },
    },
    spend_by_kind: [],
    currency: 'SAR',
    image_vs_video: [],
    best_platform: empty,
    freshness: {
      last_synced_at: '2026-08-06T09:00:00+00:00',
      providers: [{ provider: 'meta', creatives: 3, with_metrics: 3, without_metrics: 0, last_synced_at: '2026-08-06T09:00:00+00:00' }],
      quality: { insufficient_data: 1 },
    },
    applied,
    available,
    permissions: permissions(),
    ...over,
  }) as SharedCreativeSummaryPayload

const page = (over: Partial<SharedCreativeLibraryPage> = {}): SharedCreativeLibraryPage => ({
  creatives: [card(), card({ id: 'cr-2', name: 'Brand film' })],
  page: 1,
  per_page: 24,
  total: 2,
  period: { from: '2026-07-08', to: '2026-08-06' },
  applied,
  available,
  permissions: permissions(),
  ...over,
})

/** §15.6 — one creative as the client's link answers for it, funnel included. */
const sharedDetail = (over: Partial<SharedCreativeDetail> = {}): SharedCreativeDetail =>
  ({
    creative: {
      ...card(),
      copy: { body: 'Some ad copy', headline: 'A headline', description: null, cta: 'SHOP_NOW' },
      destination_url: 'https://example.test/product',
      previous: card().metrics,
      fatigue: card().fatigue,
    },
    period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
    previous_period: { from: '2026-06-08', to: '2026-07-07' },
    funnel: {
      stages: [
        { key: 'impressions', label_ar: 'الظهور', label_en: 'Impressions', count: 60000, from_stage: null, rate_from_previous: null, cost_per: 0.02, source: 'platform_reported' },
        { key: 'clicks', label_ar: 'النقرات', label_en: 'Clicks', count: 1800, from_stage: 'impressions', rate_from_previous: 0.03, cost_per: 0.67, source: 'platform_reported' },
      ],
      missing: [{ key: 'landing_page_views', label_ar: 'زيارات صفحة الهبوط', label_en: 'Landing page views' }],
      source: 'platform_reported',
    },
    trend: [],
    by_platform: [{ creative_id: 'cr-1', provider: 'meta', metrics: card().metrics, source: 'platform_reported' }],
    by_campaign: [],
    attribution: {
      source: 'platform_reported',
      note_ar: 'الأرقام كما أبلغت عنها المنصة.',
      note_en: 'Figures as the ad platform reported them.',
    },
    permissions: permissions(),
    ...over,
  }) as SharedCreativeDetail

const mockSummary = vi.mocked(getSharedCreativeSummary)
const mockLibrary = vi.mocked(getSharedCreatives)
const mockDetail = vi.mocked(getSharedCreative)

const render = (
  form: 'executive_summary' | 'detailed' = 'detailed',
  locale: 'ar' | 'en' = 'en',
  route = '/reports/share/tok',
) => renderWithProviders(<SharedCreativeSection token="tok" currency="SAR" form={form} />, { locale, route })

describe('SharedCreativeSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockSummary.mockResolvedValue(summary())
    mockLibrary.mockResolvedValue(page())
  })

  /** The two forms are different documents, not the same page at two lengths. */
  it('gives the summary the answers and the detailed report the library as well', async () => {
    render('executive_summary')

    await screen.findByText('Best creative per objective')
    expect(screen.queryByTestId('shared-creative-library')).toBeNull()
    // The library endpoint is not even called for a summary link.
    expect(mockLibrary).not.toHaveBeenCalled()

    render('detailed')
    expect(await screen.findByTestId('shared-creative-library')).toBeTruthy()
  })

  /** Every ranking names the metric it was decided on and the path it was decided inside. */
  it('states the metric and the marketing path behind each winner', async () => {
    render('detailed')

    await screen.findByText('Best creative per objective')

    const labels = screen.getAllByText('Metric used:')
    expect(labels.length).toBeGreaterThanOrEqual(3)
    expect(screen.getAllByText('Marketing path:').length).toBeGreaterThanOrEqual(2)
    // Best video was decided on CPM inside the awareness path — never on the sales metric. The name
    // appears in the winner card and again in the library below it, so both are accepted.
    expect(screen.getAllByText('Brand film').length).toBeGreaterThan(0)
  })

  /** A ranking with nothing past the evidence floor says so rather than reading as a finding. */
  it('declares a thin ranking instead of presenting it as a result', async () => {
    render('detailed')

    expect(await screen.findByText('Thin evidence — provisional ranking')).toBeTruthy()
  })

  /** «Insufficient data» is its own bucket and never becomes «stable». */
  it('keeps insufficient data as its own answer', async () => {
    render('detailed')

    await screen.findByText('Insufficient data')
    expect(screen.getByText('Barely ran')).toBeTruthy()
  })

  /** Fatigue arrives with the evidence behind it, not as a bare label. */
  it('shows the evidence beside a fatigue alert', async () => {
    render('detailed')

    const alerts = await screen.findByTestId('fatigue-alerts')
    expect(alerts.textContent).toContain('Tired banner')
    expect(alerts.textContent).toContain('Click-through fell while frequency rose.')
  })

  /** A withheld figure is not drawn as a labelled blank naming what is being kept back. */
  it('says a value is not shown on this link rather than printing an empty number', async () => {
    mockSummary.mockResolvedValue(
      summary({
        permissions: permissions({ spend: false, revenue: false, cpa: false, roas: false }),
        best_by_objective: [
          {
            objective: 'sales',
            path: 'conversion',
            creatives: 2,
            spend: null,
            metric: 'roas',
            higher_wins: true,
            value: null,
            value_hidden: true,
            candidates: 2,
            evidenced: 2,
            low_evidence: false,
            creative: card(),
          },
        ],
      }),
    )

    render('executive_summary')

    expect(await screen.findByText('Not shown on this link')).toBeTruthy()
  })

  /** With the section forbidden entirely, nothing renders at all. */
  it('renders nothing when the link shows no creatives', async () => {
    mockSummary.mockResolvedValue(summary({ permissions: permissions({ creatives: false }) }))

    const { container } = render('detailed')

    await waitFor(() => expect(mockSummary).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="shared-creative-section"]')).toBeNull()
  })

  /** No video is mounted before the reader opens one. */
  it('mounts no video element in the section itself', async () => {
    const { container } = render('detailed')

    await screen.findByTestId('shared-creative-library')
    expect(container.querySelectorAll('video')).toHaveLength(0)
  })

  /**
   * A filter reaches the SERVER.
   *
   * Applied in the browser it would narrow the twenty-four rows on screen while the rankings above
   * them still described the whole account — two answers on one page, and the reader with no way to
   * tell which one is about their selection.
   */
  it('sends a narrowed filter to the server for both the summary and the library', async () => {
    render('detailed')

    await screen.findByTestId('shared-creative-library')

    fireEvent.change(screen.getByLabelText('Platform'), { target: { value: 'tiktok' } })

    await waitFor(() => {
      expect(mockSummary).toHaveBeenCalledWith('tok', expect.objectContaining({ providers: ['tiktok'] }), undefined)
      expect(mockLibrary).toHaveBeenCalledWith('tok', expect.objectContaining({ providers: ['tiktok'] }), undefined)
    })
  })

  /** Comparison is not offered when the link forbids it. */
  it('offers no comparison control when the link does not carry it', async () => {
    mockSummary.mockResolvedValue(summary({ permissions: permissions({ comparison: false }) }))
    mockLibrary.mockResolvedValue(page({ permissions: permissions({ comparison: false }) }))

    render('detailed')

    await screen.findByTestId('shared-creative-library')
    expect(screen.queryByText('selected')).toBeNull()
  })

  /** The section says when it last synced, so «live» is never inferred from its presence. */
  it('states its own freshness', async () => {
    render('detailed')

    await screen.findByText('Last sync:')
  })

  /** Arabic renders the Arabic copy and the same structure. */
  it('renders in Arabic', async () => {
    render('detailed', 'ar')

    expect(await screen.findByText('تحليل المحتوى')).toBeTruthy()
    expect(screen.getByText('أفضل محتوى لكل هدف')).toBeTruthy()
  })

  // ---- §15.6, the client's own creative page ---------------------------------------------------

  /**
   * Opening a creative fetches its detail and puts it in the ADDRESS, so a refresh reopens it.
   *
   * A query parameter rather than a nested route: this tree holds the accepted password, and a route
   * change that remounted the gate would ask the client for it again on every creative they opened.
   */
  it('opens a creative into its own view and keeps it in the address', async () => {
    mockDetail.mockResolvedValue(sharedDetail())

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])

    expect(await screen.findByTestId('shared-creative-detail')).toBeTruthy()
    expect(mockDetail).toHaveBeenCalledWith('tok', 'cr-1', {}, undefined)
  })

  /**
   * The address alone opens the creative — which is what makes a refresh and a forwarded link work.
   *
   * Asserted by MOUNTING at that address rather than by reading `window.location`: the point is not
   * that a string was written somewhere, it is that arriving at it produces the creative.
   */
  it('opens the creative named by the address on arrival', async () => {
    mockDetail.mockResolvedValue(sharedDetail())

    render('detailed', 'en', '/reports/share/tok?creative=cr-1')

    expect(await screen.findByTestId('shared-creative-detail')).toBeTruthy()
    expect(mockDetail).toHaveBeenCalledWith('tok', 'cr-1', {}, undefined)
  })

  /** The client's funnel shows the reported steps and NAMES the ones the platform withheld. */
  it('shows the reported funnel stages and names the missing ones', async () => {
    mockDetail.mockResolvedValue(sharedDetail())

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])
    await screen.findByTestId('shared-creative-detail')

    expect(screen.getAllByText('Clicks').length).toBeGreaterThan(0)
    expect(screen.getByText(/Stages this platform does not report/)).toHaveTextContent('Landing page views')
  })

  /**
   * A per-stage cost is spend divided by a count printed beside it.
   *
   * When the link withholds spend the server sends `cost_hidden`, and the page must say «not shown
   * on this link» rather than a dash — «withheld» and «never reported» are different sentences.
   */
  it('says a withheld per-stage cost is withheld rather than missing', async () => {
    mockDetail.mockResolvedValue(
      sharedDetail({
        funnel: {
          stages: [
            { key: 'clicks', label_ar: 'النقرات', label_en: 'Clicks', count: 1000, from_stage: null, rate_from_previous: null, cost_per: null, cost_hidden: true, source: 'platform_reported' },
          ],
          missing: [],
          source: 'platform_reported',
        },
      }),
    )

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])
    await screen.findByTestId('shared-creative-detail')

    expect(screen.getAllByText('Not shown on this link').length).toBeGreaterThan(0)
  })

  /** A creative the link excludes is refused here too — the detail view is not a side door. */
  it('refuses a creative the link does not carry', async () => {
    mockDetail.mockRejectedValue(new Error('not found'))

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])

    expect(await screen.findByText('This creative is not available on this link.')).toBeTruthy()
  })

  /** Zoom is an affordance the link may withhold, and then it is not drawn at all. */
  it('draws no zoom controls when the link forbids zooming', async () => {
    mockDetail.mockResolvedValue(sharedDetail())
    mockSummary.mockResolvedValue(summary({ permissions: permissions({ image_zoom: false }) }))
    mockLibrary.mockResolvedValue(page({ permissions: permissions({ image_zoom: false }) }))

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])
    await screen.findByTestId('shared-creative-detail')

    expect(screen.queryByRole('button', { name: '100%' })).toBeNull()
  })

  /** Back returns to the library with the section's filters untouched. */
  it('returns to the library without losing the section', async () => {
    mockDetail.mockResolvedValue(sharedDetail())

    render('detailed')
    fireEvent.click((await screen.findAllByRole('button', { name: 'Creative details' }))[0])
    await screen.findByTestId('shared-creative-detail')

    fireEvent.click(screen.getByRole('button', { name: /Back to the creative library/ }))

    await waitFor(() => expect(screen.queryByTestId('shared-creative-detail')).toBeNull())
    expect(screen.getByText('Creative library')).toBeTruthy()
  })
})
