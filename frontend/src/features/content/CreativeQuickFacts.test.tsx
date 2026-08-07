import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import { CreativeViewer } from './CreativeViewer'
import type { CreativeCard, CreativeDetail, CreativeMetrics } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getCreativeInReach: vi.fn() }
})

import { getCreativeInReach } from './api'

/**
 * UX-CONTENT-001 — the panel that answers «should we keep running this» beside the asset.
 *
 * The test that matters most here is the NEGATIVE one. This viewer is also what a client sees on a
 * `/r/<token>` link, which has no session and must never acquire one; the pane reads an
 * authenticated endpoint, so if it ever rendered by default every public report would fire a 401
 * the moment a client opened a picture. That is the regression PUBLIC-REPORT-NOAUTH exists to
 * prevent, and it would look like nothing on screen.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 900, impressions: 40000, clicks: 200, conversions: 12, revenue: 4500,
  video_views: null, video_p25: null, video_p50: null, video_p75: null, video_p100: null,
  frequency: 1.2, ctr: 0.005, cpc: 4.5, cpm: 22.5, cpa: 75, roas: 5,
  conversion_rate: 0.06, view_rate: null, completion_rate: null, active_days: 12,
  reported: { spend: true, impressions: true, clicks: true, conversions: true, video_views: false },
  ...over,
})

const card = (): CreativeCard => ({
  id: 'c1',
  name: 'Hero image',
  format: 'image',
  provider: 'meta',
  status: 'active',
  campaign_id: 'camp1',
  campaign_name: 'Always-On — Sales',
  ad_set_id: null,
  ad_id: null,
  preview: {
    state: 'available', kind: 'image', image_url: 'https://example.test/a.png',
    video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null,
  },
  aspect_ratio: '1:1', duration_seconds: null, width: 1080, height: 1080, file_size: null,
  grouped: false, group_id: null, is_demo: false,
  freshness: { last_synced_at: '2026-08-01T00:00:00Z', source_updated_at: null, first_seen_at: null, last_active_at: null },
  objective: 'sales',
  path: 'conversion',
  headline_metrics: ['spend', 'conversions', 'cpa', 'roas'],
  metrics: metrics(),
  fatigue: { status: 'watch', signals: [], reason_ar: 'انخفض معدل النقر', reason_en: 'CTR has fallen' },
})

const detail = (): CreativeDetail => ({
  creative: {
    ...card(),
    copy: { body: 'Free delivery this week', headline: 'New season', description: null, cta: 'Shop now' },
    dimensions: { width: 1080, height: 1080, aspect_ratio: '1:1', file_size: null },
    destination_url: 'https://shop.test/new',
    external_ids: { creative: 'x1', ad: null, ad_set: null, campaign: null },
  },
  period: { from: '2026-07-09', to: '2026-08-07', days: 30 },
  previous_period: { from: '2026-06-09', to: '2026-07-08' },
  metrics: metrics(),
  // Half the spend last period, so the comparison has something true to say.
  previous: metrics({ spend: 450, conversions: 6 }),
  headline_metrics: ['spend', 'conversions', 'cpa', 'roas'],
  path: 'conversion',
  fatigue: { status: 'watch', signals: [], reason_ar: 'انخفض معدل النقر', reason_en: 'CTR has fallen' },
  funnel: {
    stages: [
      { key: 'impressions', label_ar: 'الظهور', label_en: 'Impressions', count: 40000, from_stage: null, rate_from_previous: null, cost_per: null, source: 'platform' },
      { key: 'clicks', label_ar: 'النقرات', label_en: 'Clicks', count: 200, from_stage: 'impressions', rate_from_previous: 0.005, cost_per: null, source: 'platform' },
    ],
    missing: [{ key: 'add_to_cart', label_ar: 'الإضافة إلى السلة', label_en: 'Add to Cart' }],
    source: 'platform',
  },
  trend: [],
  weekly: [],
  by_platform: [],
  by_campaign: [],
  peers: null,
  group: null,
  insights: {
    items: [{
      id: 'i1', key: 'ctr_falling', severity: 'warning', comparison: 'previous_period',
      title_ar: 'تراجع معدل النقر', title_en: 'Click-through rate is falling',
      detail_ar: 'انخفض 30% عن الفترة السابقة.', detail_en: 'Down 30% on the previous period.',
      supporting_metrics: {}, previous_metrics: null, movement: null, confidence: 'high',
      creative_id: 'c1', creative_name: 'Hero image', objective: 'sales', path: 'conversion',
      provider: 'meta', campaign_name: 'Always-On — Sales',
      period: { from: '2026-07-09', to: '2026-08-07', days: 30 },
      previous_period: { from: '2026-06-09', to: '2026-07-08' },
      generated_by: 'rules', needs_human_review: false,
    }],
    total: 1,
    compared_against: { path: 'conversion', creatives: 4, capped: false, cap: 50 },
  },
  attribution: { source: 'platform', note_ar: '', note_en: '' },
  currency: 'SAR',
  timezone: 'Asia/Riyadh',
  project_id: 'p1',
})

const open = (analysis: boolean) =>
  renderWithProviders(
    <CreativeViewer
      creatives={[card()]}
      index={0}
      onIndexChange={() => {}}
      onClose={() => {}}
      analysis={analysis ? { window: { from: '2026-07-09', to: '2026-08-07' }, detailsTo: (c) => `/app/content/${c.id}` } : undefined}
    />,
    { locale: 'en' },
  )

describe('the creative panel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getCreativeInReach).mockResolvedValue(detail())
  })

  /**
   * The negative case, first because it is the dangerous one.
   *
   * No `analysis` prop → no pane, and — the part that matters — no request. A client link renders
   * this exact component with no session at all.
   */
  it('renders no analysis pane and makes no request when the caller did not ask for one', async () => {
    open(false)

    expect(screen.queryByTestId('creative-quick-facts')).not.toBeInTheDocument()
    expect(getCreativeInReach).not.toHaveBeenCalled()
  })

  it('shows the copy, the funnel, the fatigue and the findings beside the asset', async () => {
    open(true)

    const pane = within(await screen.findByTestId('creative-quick-facts'))

    // The words that ran, not only the picture.
    expect(await pane.findByText('New season')).toBeInTheDocument()
    expect(pane.getByText('Shop now')).toBeInTheDocument()

    // The funnel names what was NOT reported rather than padding it with a zero (§15.6).
    expect(pane.getByText(/Not reported by the platform/)).toBeInTheDocument()
    expect(pane.getByText(/Add to Cart/)).toBeInTheDocument()

    // The objective comes from the CARD — the detail payload publishes the path instead, and the
    // panel showed «—» beside a row that had just named the objective.
    expect(pane.getByText('Sales')).toBeInTheDocument()

    expect(pane.getByText('Watch')).toBeInTheDocument()
    expect(pane.getByText('Click-through rate is falling')).toBeInTheDocument()
    expect(pane.getByRole('link', { name: /Full details/ })).toHaveAttribute('href', '/app/content/c1')
  })

  /**
   * It reads the same window the library was measured over.
   *
   * A pane that quoted its own default period would put one set of figures beside a row computed
   * over another, and nothing on screen would say the two were different questions.
   */
  it('asks for the library’s own period', async () => {
    open(true)

    await waitFor(() => expect(getCreativeInReach).toHaveBeenCalledWith('c1', { from: '2026-07-09', to: '2026-08-07' }))
  })

  /** A metric the platform does not report says so — never a zero, even here. */
  it('never prints a zero for a metric the platform does not report', async () => {
    vi.mocked(getCreativeInReach).mockResolvedValue({
      ...detail(),
      headline_metrics: ['spend', 'video_views'],
    })

    open(true)

    const views = await screen.findByTestId('quick-metric-video_views')
    expect(views).toHaveTextContent('Not provided')
    expect(views).not.toHaveTextContent(/\b0\b/)
  })
})
