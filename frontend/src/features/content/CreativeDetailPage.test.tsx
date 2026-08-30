import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativeDetailPage } from './CreativeDetailPage'
import type { CreativeDetail, CreativeMetrics } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getCreativeInReach: vi.fn() }
})

import { getCreativeInReach } from './api'

/**
 * §15.6 — the acceptance claims for one creative's page.
 *
 * Not «the page renders». What is asserted is what a reviewer would check by hand: that a metric
 * nobody reported does not become a zero, that the funnel shows only the steps the platform sent and
 * NAMES the ones it did not, that an awareness creative is never handed a cost per order, that
 * nothing mounts a `<video>` until somebody asks for one, and that Back returns to the shelf the
 * reader took the creative off rather than to an unfiltered library.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 1000,
  impressions: 50000,
  clicks: 1000,
  conversions: 50,
  revenue: 9000,
  video_views: null,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  frequency: 1.4,
  ctr: 0.02,
  cpc: 1,
  cpm: 20,
  cpa: 20,
  roas: 9,
  conversion_rate: 0.05,
  view_rate: null,
  completion_rate: null,
  active_days: 14,
  reported: {
    spend: true, impressions: true, clicks: true, conversions: true, revenue: true,
    add_to_cart: true, purchases: true,
    video_views: false, video_p100: false, landing_page_views: false,
  },
  ...over,
})

const detail = (over: Partial<CreativeDetail> = {}): CreativeDetail =>
  ({
    creative: {
      id: 'cr-1',
      name: 'Hero image',
      format: 'image',
      provider: 'meta',
      status: 'active',
      campaign_id: 'c1',
      campaign_name: 'National Day Sale',
      ad_set_id: 'set-1',
      preview: {
        state: 'available',
        kind: 'image',
        image_url: 'https://cdn.example.com/a.jpg',
        video_url: null,
        thumbnail_url: null,
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
      freshness: {
        last_synced_at: '2026-08-05T10:00:00+00:00',
        source_updated_at: '2026-08-05T09:00:00+00:00',
        first_seen_at: '2026-07-01T00:00:00+00:00',
        last_active_at: '2026-08-04T00:00:00+00:00',
      },
      objective: 'sales',
      path: 'conversion',
      headline_metrics: ['spend', 'orders', 'cpa', 'roas'],
      metrics: metrics(),
      fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
      copy: { body: 'Some ad copy', headline: 'A headline', description: null, cta: 'SHOP_NOW' },
      dimensions: { width: 1080, height: 1080, aspect_ratio: '1:1', file_size: 204800 },
      destination_url: 'https://example.test/product',
      external_ids: { creative: 'x-cr', ad_set: 'x-set', campaign: 'x-camp' },
    },
    period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
    previous_period: { from: '2026-06-08', to: '2026-07-07' },
    metrics: metrics(),
    previous: metrics({ spend: 800, cpa: 25 }),
    headline_metrics: ['spend', 'orders', 'cpa', 'roas'],
    path: 'conversion',
    fatigue: {
      status: 'watch',
      signals: [{ metric: 'ctr', direction: 'down', change: -0.22 }],
      reason_ar: 'انخفض معدل النقر',
      reason_en: 'Click-through rate fell',
    },
    funnel: {
      stages: [
        { key: 'impressions', label_ar: 'الظهور', label_en: 'Impressions', count: 50000, from_stage: null, rate_from_previous: null, cost_per: 0.02, source: 'platform_reported' },
        { key: 'clicks', label_ar: 'النقرات', label_en: 'Clicks', count: 1000, from_stage: 'impressions', rate_from_previous: 0.02, cost_per: 1, source: 'platform_reported' },
        { key: 'add_to_cart', label_ar: 'الإضافة إلى السلة', label_en: 'Add to cart', count: 200, from_stage: 'clicks', rate_from_previous: 0.2, cost_per: 5, source: 'platform_reported' },
        { key: 'purchases', label_ar: 'الشراء', label_en: 'Purchases', count: 50, from_stage: 'add_to_cart', rate_from_previous: 0.25, cost_per: 20, source: 'platform_reported' },
      ],
      missing: [
        { key: 'video_views', label_ar: 'المشاهدات', label_en: 'Video views' },
        { key: 'landing_page_views', label_ar: 'زيارات صفحة الهبوط', label_en: 'Landing page views' },
      ],
      source: 'platform_reported',
    },
    trend: [{ date: '2026-08-01', spend: 100, impressions: 5000, clicks: 100, conversions: 5, revenue: 900, video_views: null, video_p100: null, frequency: 1.2 }],
    weekly: [{ week: 1, from: '2026-08-01', to: '2026-08-07', days: 7, spend: 100, impressions: 5000, clicks: 100, conversions: 5, revenue: 900, video_views: null, video_p100: null }],
    by_platform: [{ creative_id: 'cr-1', provider: 'meta', metrics: metrics(), source: 'platform_reported' }],
    by_campaign: [],
    peers: { count: 4, path: 'conversion', ctr: 0.015, cpc: 1.4, cpm: 25, roas: 6 } as unknown as CreativeDetail['peers'],
    group: null,
    insights: {
      items: [
        {
          key: 'ctr_decline:cr-1',
          severity: 'warning',
          comparison: 'previous_period',
          title_ar: 'انخفاض معدل النقر',
          title_en: 'Click-through rate is falling',
          detail_ar: 'انخفض معدل النقر بنسبة 22%.',
          detail_en: 'Click-through rate fell by 22%.',
          action_ar: 'جرّب صورة جديدة.',
          action_en: 'Try a fresh image.',
          supporting_metrics: { ctr: 0.02 },
          previous_metrics: { ctr: 0.026 },
          movement: { metric: 'ctr', current: 0.02, previous: 0.026, change: -0.22 },
          confidence: 'high',
          creative_id: 'cr-1',
          creative_name: 'Hero image',
          objective: 'sales',
          path: 'conversion',
          provider: 'meta',
          campaign_name: 'National Day Sale',
          period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
          previous_period: { from: '2026-06-08', to: '2026-07-07' },
          generated_by: 'rules',
          needs_human_review: false,
        },
      ],
      total: 1,
      compared_against: { path: 'conversion', creatives: 12, capped: false, cap: 120 },
    },
    attribution: {
      source: 'platform_reported',
      note_ar: 'الأرقام كما أبلغت عنها المنصة.',
      note_en: 'Figures as the ad platform reported them.',
    },
    currency: 'SAR',
    timezone: 'Asia/Riyadh',
    project_id: 'p1',
    ...over,
  }) as CreativeDetail

const mocked = vi.mocked(getCreativeInReach)

const render = (route = '/app/content/cr-1?from=2026-07-08&to=2026-08-06&providers%5B%5D=meta') =>
  renderWithProviders(<CreativeDetailPage portal="app" />, {
    locale: 'en',
    route,
    path: '/app/content/:creativeId',
  })

describe('CreativeDetailPage', () => {
  beforeEach(() => {
    mocked.mockReset()
    mocked.mockResolvedValue(detail())
  })

  /** The window in the address is the window that is fetched — that is what makes a link shareable. */
  it('asks for the ad in the window carried by the address', async () => {
    render()

    await screen.findByText('Hero image')
    expect(mocked).toHaveBeenCalledWith('cr-1', { from: '2026-07-08', to: '2026-08-06' })
  })

  /**
   * Back returns to the shelf, not to an unfiltered library.
   *
   * The library's filters travel on the detail address precisely so this link can rebuild them.
   */
  it('returns to the library carrying the filters it arrived with', async () => {
    render()

    const back = await screen.findByRole('link', { name: /Back to the library/ })
    const href = back.getAttribute('href') ?? ''

    expect(href).toContain('/app/content?')
    expect(href).toContain('from=2026-07-08')
    expect(href).toContain('providers%5B%5D=meta')
    // Not the creative itself: that would send the reader back to a library that reopened the
    // creative they just left.
    expect(href).not.toContain('ad=')
  })

  /** Nothing mounts a player until somebody asks. An image page has no `<video>` at all. */
  it('mounts no video element for an image ad', async () => {
    const { container } = render()

    await screen.findByText('Hero image')
    expect(container.querySelectorAll('video')).toHaveLength(0)
  })

  /** A video arms nothing: metadata only, no autoplay, and the source is not fetched up front. */
  it('mounts a video with metadata only and no autoplay', async () => {
    mocked.mockResolvedValue(
      detail({
        creative: {
          ...detail().creative,
          preview: {
            state: 'available',
            kind: 'video',
            image_url: null,
            video_url: 'https://cdn.example.com/a.mp4',
            thumbnail_url: 'https://cdn.example.com/a.jpg',
            expires_at: null,
            note_ar: null,
            note_en: null,
          },
        } as CreativeDetail['creative'],
      }),
    )

    const { container } = render()
    await screen.findByText('Hero image')

    const video = container.querySelector('video')
    expect(video).not.toBeNull()
    expect(video?.getAttribute('preload')).toBe('metadata')
    expect(video?.hasAttribute('autoplay')).toBe(false)
  })

  /**
   * The funnel shows the reported stages and NAMES the ones the platform withheld.
   *
   * Silence about a missing stage reads as «this funnel has four steps»; a zero reads as «nobody
   * bought». Neither is what the platform said.
   */
  it('shows only reported funnel stages and names the missing ones', async () => {
    render()

    await screen.findByText('Funnel')
    expect(screen.getAllByText('Add to cart').length).toBeGreaterThan(0)
    expect(screen.getByText(/Stages this platform does not report/)).toHaveTextContent('Landing page views')
    // The step it never sent is NOT drawn as a stage of its own in the table.
    expect(screen.queryByRole('cell', { name: 'Landing page views' })).toBeNull()
  })

  /** An awareness creative is judged on watching, never on a cost per order it never had. */
  it('never shows cpa or roas as a headline for an awareness ad', async () => {
    mocked.mockResolvedValue(
      detail({
        path: 'awareness',
        headline_metrics: ['impressions', 'cpm', 'view_rate', 'completion_rate'],
      }),
    )

    render()
    await screen.findByText('Metrics for this objective')

    const figures = screen.getByText('Metrics for this objective').closest('section')
    expect(figures).not.toBeNull()
    expect(within(figures as HTMLElement).queryByText('Cost per result')).toBeNull()
    expect(within(figures as HTMLElement).queryByText('ROAS')).toBeNull()
    expect(within(figures as HTMLElement).getByText('Completion rate')).toBeInTheDocument()
  })

  /** «Insufficient data» is a verdict, and it never renders as «stable». */
  it('keeps insufficient data out of the stable bucket', async () => {
    mocked.mockResolvedValue(
      detail({
        fatigue: { status: 'insufficient_data', signals: [], reason_ar: '', reason_en: 'Not enough days yet' },
      }),
    )

    render()
    await screen.findByText('Not enough days yet')

    expect(screen.getAllByText('Insufficient data').length).toBeGreaterThan(0)
    expect(screen.queryByText('Stable')).toBeNull()
  })

  /** Fatigue arrives with what produced it — a verdict with no evidence is an opinion. */
  it('shows the evidence behind the fatigue verdict', async () => {
    render()

    await screen.findByText('Evidence')
    expect(screen.getByText('-22.0%')).toBeInTheDocument()
  })

  /** A finding carries its own window, its confidence, and what it was compared against. */
  it('shows the findings with the comparison they were made against', async () => {
    render()

    await screen.findByText('Click-through rate is falling')
    // The label lives in its own span, so the assertion is on the line that holds both.
    expect(screen.getByText(/Suggested action/).parentElement).toHaveTextContent('Try a fresh image.')
    expect(screen.getByText(/Compared against/)).toHaveTextContent('12')
    expect(screen.getByText(/Confidence/)).toHaveTextContent('High')
  })

  /** An empty findings list says nothing moved — it does not disappear silently. */
  it('says so when there is nothing to report', async () => {
    mocked.mockResolvedValue(
      detail({ insights: { items: [], total: 0, compared_against: { path: 'conversion', creatives: 3, capped: false, cap: 120 } } }),
    )

    render()
    expect(await screen.findByText(/nothing crossed a material threshold/)).toBeInTheDocument()
  })

  /** Changing the period rewrites the address and refetches — a control that changes the answer. */
  it('refetches when the period changes and puts it in the address', async () => {
    render()
    await screen.findByText('Hero image')

    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-07-01' } })

    await waitFor(() =>
      expect(mocked).toHaveBeenCalledWith('cr-1', { from: '2026-07-01', to: '2026-08-06' }),
    )
  })

  /** The lineage a reader needs to trust the numbers, on the page rather than in a tooltip. */
  it('states where the figures came from and how fresh they are', async () => {
    render()

    await screen.findByText('Identity and source')
    expect(screen.getByText('Asia/Riyadh')).toBeInTheDocument()
    expect(screen.getByText('SAR')).toBeInTheDocument()
    expect(screen.getByText(/Figures as the ad platform reported them/)).toBeInTheDocument()
  })

  /** A creative outside the caller's reach is a refusal, not an empty page pretending to be one. */
  it('says the ad is unavailable rather than rendering an empty shell', async () => {
    mocked.mockRejectedValue(new Error('not found'))

    render()
    expect(await screen.findByText(/Could not open this ad/)).toBeInTheDocument()
  })
})
