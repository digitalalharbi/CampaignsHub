import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativePulseSection } from './CreativePulseSection'
import type { CreativeCard, CreativeMetrics } from './api'
import type { CreativePulse } from './pulse'
import { renderWithProviders } from '@/test/utils'

vi.mock('./pulse', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./pulse')>()
  return { ...actual, getCreativePulse: vi.fn() }
})

import { getCreativePulse } from './pulse'

/**
 * §15.11's acceptance claims for the dashboard section.
 *
 * These are the checks a reviewer would make by hand, not «it renders»:
 *
 *   - the host's filters reach the SERVER, so the section narrows when the dashboard narrows;
 *   - a card links into the library carrying the same selection it was computed under;
 *   - every winner names its metric and its marketing path, and none of them is «best creative»;
 *   - a winner with thin evidence says so instead of being presented as a finding;
 *   - an unreported metric renders «Not provided», never 0;
 *   - no `<video>` element mounts on a dashboard.
 */

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 900,
  impressions: 40000,
  clicks: 200,
  conversions: 20,
  revenue: 4500,
  video_views: null,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  frequency: 1.2,
  ctr: 0.005,
  cpc: 4.5,
  cpm: 22.5,
  cpa: 45,
  roas: 5,
  conversion_rate: 0.1,
  view_rate: null,
  completion_rate: null,
  active_days: 12,
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
    ad_set_id: 'set-1',
    /*
     * The canonical relation. Two ads, because one asset placed by several ads is the ordinary case
     * on a real account — and the case the singular `ad_id` above could never represent.
     */
    ads: [
      { id: 'a1', external_id: 'ad-1', name: 'Ad one', status: 'active', external_ad_set_id: 'set-1', external_campaign_id: 'c1' },
      { id: 'a2', external_id: 'ad-2', name: 'Ad two', status: 'active', external_ad_set_id: 'set-1', external_campaign_id: 'c1' },
    ],
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
    freshness: {
      last_synced_at: '2026-08-05T10:00:00+00:00',
      source_updated_at: null,
      first_seen_at: null,
      last_active_at: null,
    },
    objective: 'sales',
    path: 'conversion',
    headline_metrics: ['spend', 'orders', 'roas'],
    metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const empty = { items: [], total: 0, shown: 0 }

const pulse = (over: Partial<CreativePulse> = {}): CreativePulse => ({
  period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
  previous_period: { from: '2026-06-08', to: '2026-07-07' },
  totals: { creatives: 4, with_metrics: 3, without_metrics: 1 },
  evidence: { min_impressions: 1000, min_change: 0.1 },
  insights: {
    items: [],
    total: 0,
    shown: 0,
    evidence: { min_impressions: 1000, min_change: 0.1 },
    period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
    previous_period: { from: '2026-06-08', to: '2026-07-07' },
  },
  best_by_objective: [
    {
      objective: 'sales',
      path: 'conversion',
      creatives: 3,
      spend: 2700,
      metric: 'roas',
      higher_wins: true,
      value: 5,
      candidates: 3,
      evidenced: 3,
      low_evidence: false,
      creative: card(),
    },
  ],
  best_image: [
    {
      kind: 'image',
      path: 'conversion',
      creatives: 2,
      spend: 1800,
      metric: 'roas',
      higher_wins: true,
      value: 5,
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
      spend: 900,
      metric: 'cpm',
      higher_wins: false,
      value: 12.5,
      candidates: 1,
      evidenced: 0,
      low_evidence: true,
      creative: card({
        id: 'cr-2',
        name: 'Brand film',
        format: 'video',
        objective: 'awareness',
        path: 'awareness',
        preview: {
          state: 'available',
          kind: 'video',
          image_url: null,
          video_url: 'https://cdn.example.com/v.mp4',
          thumbnail_url: 'https://cdn.example.com/v-thumb.jpg',
          expires_at: null,
          note_ar: null,
          note_en: null,
        },
        metrics: metrics({ cpm: 12.5, roas: null, revenue: null }),
      }),
    },
  ],
  fastest_growing: empty,
  declining: empty,
  fatigue: {
    counts: { improving: 1, stable: 2, watch: 0, fatigued: 1, insufficient_data: 0 },
    fatigued: empty,
    watch: empty,
    insufficient_data: empty,
    alerts: empty,
    spend_at_risk: { spend: 0, spend_withheld_rows: 0, spend_original: 0, money_original_currency: null, money_original_currencies: 0 },
  },
  currency: 'SAR',
  spend_by_kind: [
    { kind: 'image', spend: 1800, share: 0.667, creatives: 2, spend_not_reported: 1 },
    { kind: 'video', spend: 900, share: 0.333, creatives: 1, spend_not_reported: 0 },
  ],
  image_vs_video: [],
  best_platform: empty,
  freshness: {
    last_synced_at: '2026-08-05T10:00:00+00:00',
    providers: [
      { provider: 'meta', creatives: 3, with_metrics: 3, without_metrics: 0, last_synced_at: '2026-08-05T10:00:00+00:00' },
    ],
    quality: {
      previews_withheld: 0, previews_expired: 0, previews_unavailable: 0,
      without_metrics: 1, insufficient_data: 0, never_synced: 0,
    },
  },
  filters: {
    providers: ['meta', 'tiktok'],
    formats: ['image', 'video'],
    statuses: ['active'],
    kinds: ['image', 'video', 'carousel'],
    campaigns: [{ id: 'c1', name: 'National Day Sale', objective: 'sales' }],
    ad_sets: ['set-1'],
    ads: [{ value: 'ad-1', label: 'ad-1' }],
    objectives: ['sales', 'awareness'],
    paths: ['awareness', 'traffic', 'conversion'],
    projects: [{ id: 'p1', name: 'Q3 Launch', client_id: 'cl1' }],
    clients: [{ id: 'cl1', name: 'Acme' }],
    health: ['improving', 'stable', 'watch', 'fatigued', 'insufficient_data'],
  },
  ...over,
})

const mocked = vi.mocked(getCreativePulse)

/** A pulse whose one winner carries exactly these ads — the drill-down's ad step reads them. */
const withAds = (ads: CreativeCard['ads']): CreativePulse =>
  pulse({
    best_by_objective: [
      {
        objective: 'sales', path: 'conversion', creatives: 3, spend: 2700, metric: 'roas',
        higher_wins: true, value: 5, candidates: 3, evidenced: 3, low_evidence: false,
        creative: card({ ads }),
      },
    ],
  })

describe('CreativePulseSection', () => {
  beforeEach(() => {
    mocked.mockReset()
    mocked.mockResolvedValue(pulse())
  })

  const render = (props: Partial<Parameters<typeof CreativePulseSection>[0]> = {}) =>
    renderWithProviders(
      <CreativePulseSection
        libraryPath="/app/content"
        filters={{ from: '2026-07-08', to: '2026-08-06', providers: ['meta'] }}
        {...props}
      />,
      { locale: 'en' },
    )

  it('sends the host dashboard’s filters to the server rather than filtering in the browser', async () => {
    render()

    await screen.findByText('Best image')

    expect(mocked).toHaveBeenCalledWith(
      expect.objectContaining({ from: '2026-07-08', to: '2026-08-06', providers: ['meta'] }),
      undefined,
    )
  })

  /**
   * The two things that make a ranking checkable: which metric, and which marketing path.
   *
   * A dashboard card reading «best video» with neither is a verdict nobody can verify, and one
   * computed across paths would be judging an awareness film by a sales figure.
   */
  it('names the metric and the marketing path behind every winner', async () => {
    render()

    const image = (await screen.findByText('Best image')).closest('article') as HTMLElement
    expect(within(image).getByText('ROAS')).toBeInTheDocument()
    expect(within(image).getByText('Conversion & sales')).toBeInTheDocument()

    const video = (await screen.findByText('Best video')).closest('article') as HTMLElement
    expect(within(video).getByText('CPM')).toBeInTheDocument()
    expect(within(video).getByText('Awareness')).toBeInTheDocument()

    // And there is no card claiming an overall winner across the paths.
    expect(screen.queryByText(/best creative/i)).not.toBeInTheDocument()
  })

  /** A winner nothing evidenced is offered as provisional — not as a finding. */
  it('says when a winner was chosen with thin evidence', async () => {
    render()

    const video = (await screen.findByText('Best video')).closest('article') as HTMLElement
    expect(within(video).getByText(/Provisional/)).toBeInTheDocument()

    const image = screen.getByText('Best image').closest('article') as HTMLElement
    expect(within(image).getByText('Chosen from 2 ads')).toBeInTheDocument()
    expect(within(image).queryByText(/Provisional/)).not.toBeInTheDocument()
  })

  /**
   * A card naming ONE creative opens that creative's page, carrying the selection it was computed
   * under (§15.6).
   *
   * The filters travel because the detail page's Back link rebuilds the shelf the reader took the
   * creative off — a drill-down that dropped the dashboard's period would return them to a
   * different set of creatives than the card they clicked, which is how a drill-down stops being
   * trusted.
   */
  it('links to the ad page carrying the filters it was computed under', async () => {
    render()

    // The same creative is both the objective winner and the best image, so it legitimately appears
    // twice; either link has to carry the whole selection.
    const links = await screen.findAllByRole('link', { name: 'Hero image' })
    const href = links[0].getAttribute('href') ?? ''

    expect(href).toContain('/app/content/cr-1?')
    expect(href).toContain('from=2026-07-08')
    expect(href).toContain('to=2026-08-06')
    expect(href).toContain('providers%5B%5D=meta')
  })

  /** The drill-down chain is Platform › Campaign › Ad set › Ad › Creative, each one narrower. */
  it('offers the full drill-down from platform down to the ad', async () => {
    render()

    const nav = (await screen.findAllByRole('navigation', { name: 'Drill down' }))[0]

    const campaign = within(nav).getByRole('link', { name: 'National Day Sale' })
    expect(campaign.getAttribute('href')).toContain('campaign_ids%5B%5D=c1')

    const adSet = within(nav).getByRole('link', { name: 'Ad set' })
    expect(adSet.getAttribute('href')).toContain('ad_set_ids%5B%5D=set-1')
    expect(adSet.getAttribute('href')).toContain('campaign_ids%5B%5D=c1')

    /*
     * EVERY ad running this creative, and the label admits how many.
     *
     * This asserted a single `ad-1`, taken from `creative.ad_id` — one ad chosen from many by row
     * order, rewritten on every import. On the live Snapchat account four ads share each creative,
     * so a reader following «Ad ›» to decide whether to pause it was shown a quarter of the
     * evidence. The canonical relation is `external_ads.creative_id`, and the step now narrows to
     * all of it.
     */
    const ad = within(nav).getByRole('link', { name: 'Ad (2)' })
    // The last rung is «Ad asset», not a second «Ad»: one creative can be carried by several ads, so
    // two adjacent links under one word would point at different places and read as a repetition.
    within(nav).getByRole('link', { name: 'Ad asset' })
    expect(ad.getAttribute('href')).toContain('ad_ids%5B%5D=ad-1')
    expect(ad.getAttribute('href')).toContain('ad_ids%5B%5D=ad-2')
  })

  /** One ad is not announced as a count — «Ad (1)» is noise where «Ad» is the whole truth. */
  it('does not count a single ad', async () => {
    mocked.mockResolvedValue(withAds([
      { id: 'a1', external_id: 'ad-1', name: 'Ad one', status: 'active', external_ad_set_id: 'set-1', external_campaign_id: 'c1' },
    ]))
    render()

    const nav = (await screen.findAllByRole('navigation', { name: 'Drill down' }))[0]

    expect(within(nav).getByRole('link', { name: 'Ad' })).toBeInTheDocument()
  })

  /** A creative no ad is running gets no ad step. */
  it('offers no ad step when no ad is running the creative', async () => {
    mocked.mockResolvedValue(withAds([]))
    render()

    const nav = (await screen.findAllByRole('navigation', { name: 'Drill down' }))[0]

    expect(within(nav).queryByRole('link', { name: /^Ad( \(\d+\))?$/ })).not.toBeInTheDocument()
  })

  /**
   * And a response that never carried `ads` behaves the same way rather than throwing.
   *
   * A deployed frontend meets whatever backend is live, and a payload from before this field
   * existed must produce no ad step — not a crash that takes the whole pulse section down.
   */
  it('survives a response that predates the ads relation', async () => {
    mocked.mockResolvedValue(withAds(undefined as never))
    render()

    const nav = (await screen.findAllByRole('navigation', { name: 'Drill down' }))[0]

    expect(within(nav).queryByRole('link', { name: /^Ad( \(\d+\))?$/ })).not.toBeInTheDocument()
  })

  /** A dashboard is the worst page to mount players on — it is the one most often left open. */
  it('mounts no video element, whatever the ad is', async () => {
    const { container } = render()

    await screen.findByText('Best video')

    expect(container.querySelectorAll('video')).toHaveLength(0)
  })

  /** The fatigue states are links into the library, so a count is a way in rather than a fact. */
  it('turns each fatigue state into a way into the library', async () => {
    render()

    const fatigued = await screen.findByRole('link', { name: /Fatigued 1/ })
    expect(fatigued.getAttribute('href')).toContain('health=fatigued')
  })

  /**
   * A control the section owns narrows the SAME query — it does not slice what was already fetched.
   *
   * The axis is added to the request; nothing is filtered in the browser, so the totals and the
   * rankings are recomputed over the narrowed set rather than over a subset of the wide one.
   */
  it('refetches with the added axis when its own filter changes', async () => {
    render({ axes: ['clients'] })

    const select = await screen.findByLabelText('Client')
    fireEvent.change(select, { target: { value: 'cl1' } })

    await waitFor(() =>
      expect(mocked).toHaveBeenLastCalledWith(expect.objectContaining({ client_ids: ['cl1'] }), undefined),
    )
  })

  /** «Six of nineteen» — a section showing part of a list and not saying so reads as the whole. */
  it('states the real total when a list is longer than what it shows', async () => {
    mocked.mockResolvedValue(
      pulse({
        declining: {
          items: [
            {
              metric: 'roas', higher_wins: true, current: 2, previous: 5,
              change: -0.6, improvement: -0.6, creative: card({ id: 'cr-9', name: 'Tired ad' }),
            },
          ],
          total: 19,
          shown: 1,
        },
      }),
    )

    render()

    expect(await screen.findByText('Showing 1 of 19')).toBeInTheDocument()
  })

  /**
   * An images-versus-videos table marks the winner per metric and never overall — and a metric the
   * platform did not report reads «Not provided», not 0.
   */
  it('compares images and videos metric by metric, and never invents a zero', async () => {
    mocked.mockResolvedValue(
      pulse({
        image_vs_video: [
          {
            path: 'conversion',
            headline_metrics: ['spend', 'roas', 'video_views'],
            image: metrics({ roas: 5 }),
            video: metrics({ roas: 9 }),
          },
        ],
      }),
    )

    render()

    const table = (await screen.findByText('Images vs videos')).closest('div') as HTMLElement
    // Neither side reported video views — so neither shows a zero, and neither wins the row.
    const unreported = within(table).getByText('Video views').closest('tr') as HTMLElement
    expect(within(unreported).getAllByText('Not provided')).toHaveLength(2)

    // And the metric both sides DID report marks its winner — per metric, never overall.
    const roasRow = within(table).getByText('ROAS').closest('tr') as HTMLElement
    const winner = within(roasRow).getByText('9.00×')
    expect(winner).toBeInTheDocument()
    expect(winner.className).toContain('text-success')
    expect(within(roasRow).getByText('5.00×').className).not.toContain('text-success')
  })

  /**
   * Nothing to show says so — and says what narrowed it to nothing.
   *
   * Found live on `/app`, where the dashboard's filters sit behind a «customise» dialog: the section
   * read «no creatives match this selection» on a workspace with four creatives, because the page
   * defaults to the awareness objective and that workspace runs only sales. Both statements were
   * true and the reader could see neither the cause nor the cure.
   */
  it('says there is nothing, and names the filter that made it nothing', async () => {
    mocked.mockResolvedValue(
      pulse({
        totals: { creatives: 0, with_metrics: 0, without_metrics: 0 },
        best_by_objective: [],
        best_image: [],
        best_video: [],
      }),
    )

    renderWithProviders(
      <CreativePulseSection
        libraryPath="/app/content"
        filters={{ from: '2026-07-08', to: '2026-08-06', objectives: ['awareness'], providers: ['meta'] }}
      />,
      { locale: 'en' },
    )

    expect(await screen.findByText('No ads match this selection.')).toBeInTheDocument()
    expect(screen.getByText(/Filtered by: Platform: Meta · Objective: Awareness/)).toBeInTheDocument()
  })

  /** An unfiltered dashboard carries no line saying it is unfiltered. */
  it('says nothing about filters when none are applied', async () => {
    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    await screen.findByText('Best image')

    expect(screen.queryByText(/Filtered by/)).not.toBeInTheDocument()
  })

  /**
   * §15.10 on the dashboard — the findings the endpoint has always returned.
   *
   * The defect this closes is not a missing feature: `GET /creatives/pulse` carried `insights` from
   * the day the engine landed and this section drew none of them. An API without a UI is the same
   * defect as a page without data, and it is invisible in every test that only checks the cards.
   */
  it('draws the findings its own endpoint returns, with their evidence', async () => {
    vi.mocked(getCreativePulse).mockResolvedValue(
      pulse({
        insights: {
          items: [
            {
              id: 'roas_drop:cr-1',
              key: 'roas_drop',
              severity: 'warning',
              comparison: 'previous_period',
              title_ar: 'تراجع العائد',
              title_en: 'ROAS fell on Brand film',
              detail_ar: 'من 5.00× إلى 2.10×',
              detail_en: 'From 5.00× to 2.10× against the previous period.',
              action_ar: 'راجع الصفحة المقصودة',
              action_en: 'Review the landing page before adding budget.',
              supporting_metrics: { roas: 2.1 },
              previous_metrics: { roas: 5 },
              movement: { metric: 'roas', current: 2.1, previous: 5, change: -0.58 },
              confidence: 'high',
              creative_id: 'cr-1',
              creative_name: 'Brand film',
              objective: 'sales',
              path: 'conversion',
              provider: 'meta',
              campaign_name: 'Sale',
              period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
              previous_period: { from: '2026-06-08', to: '2026-07-07' },
              generated_by: 'rules',
              needs_human_review: false,
            },
          ],
          total: 3,
          shown: 1,
          evidence: { min_impressions: 1000, min_change: 0.1 },
          period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
          previous_period: { from: '2026-06-08', to: '2026-07-07' },
        },
      }),
    )

    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    expect(await screen.findByText('ROAS fell on Brand film')).toBeInTheDocument()
    expect(screen.getByText('From 5.00× to 2.10× against the previous period.')).toBeInTheDocument()
    // The action, the confidence and BOTH windows — a movement with no «against what» is not evidence.
    expect(screen.getByText('Review the landing page before adding budget.').parentElement).toHaveTextContent(
      'Suggested action',
    )
    expect(screen.getByText('Confidence: High confidence')).toBeInTheDocument()
    expect(screen.getByText(/Previous period: 2026-06-08/)).toBeInTheDocument()
    // A truncated list says so, rather than reading as «this is everything».
    expect(screen.getByText('1/3')).toBeInTheDocument()
    // And the finding links into the ad it names, carrying the dashboard's own window.
    expect(screen.getByRole('link', { name: /Open ad: Brand film/ })).toHaveAttribute(
      'href',
      expect.stringContaining('/app/content/cr-1'),
    )
  })

  /** A model-written finding must never reach a decision undeclared. */
  it('marks a generated finding as needing human review', async () => {
    vi.mocked(getCreativePulse).mockResolvedValue(
      pulse({
        insights: {
          items: [
            {
              id: 'ai:cr-1',
              key: 'ai',
              severity: 'opportunity',
              comparison: 'peers',
              title_ar: 'اقتراح',
              title_en: 'A generated suggestion',
              detail_ar: '…',
              detail_en: 'Written by a model.',
              supporting_metrics: {},
              previous_metrics: null,
              movement: null,
              confidence: 'medium',
              creative_id: null,
              creative_name: null,
              objective: null,
              path: null,
              provider: null,
              campaign_name: null,
              period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
              previous_period: { from: '2026-06-08', to: '2026-07-07' },
              generated_by: 'model',
              needs_human_review: true,
            },
          ],
          total: 1,
          shown: 1,
          evidence: { min_impressions: 1000, min_change: 0.1 },
          period: { from: '2026-07-08', to: '2026-08-06', days: 30 },
          previous_period: { from: '2026-06-08', to: '2026-07-07' },
        },
      }),
    )

    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    expect(await screen.findByText('Generated — needs human review')).toBeInTheDocument()
  })

  /** An account where nothing moved has nothing to be told — and an empty panel reads as a broken one. */
  it('draws no findings panel when there are none', async () => {
    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    await screen.findByText('Best image')

    expect(screen.queryByText('What the figures say')).not.toBeInTheDocument()
  })

  /*
   * ── CREATIVE-MONEY-TRUTH-001 — the strip said «SAR» whatever the money actually was ───────────
   *
   * This section's formatter appended a hard-coded «SAR» / «ر.س» to whatever number it was handed.
   * `creative_daily_metrics` carried no currency at all, so on production it was labelling USD
   * figures as Saudi riyals — 4,128.93 USD rendered «4,129 SAR», understating spend by roughly
   * 3.75× and reading as a measured fact. A wrong number is worse than a withheld one: nothing
   * about it looks wrong.
   */

  it('states an unconvertible figure in its own currency, never in the project\'s', async () => {
    vi.mocked(getCreativePulse).mockResolvedValue(
      pulse({
        currency: 'SAR',
        spend_by_kind: [
          {
            kind: 'image',
            // Withheld exactly as the pipeline reports it: no converted figure, the original kept.
            spend: null,
            share: null,
            creatives: 2,
            spend_not_reported: 0,
            spend_withheld_rows: 262,
            spend_original: 4128.93,
            money_original_currency: 'USD',
            money_original_currencies: 1,
          },
        ],
      }),
    )

    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    await screen.findByText('Spend by ad type')

    // Exact, in the currency it was actually spent in — not rounded into a figure it never was.
    expect(screen.getByText(/4,128\.93 USD/)).toBeInTheDocument()
    expect(screen.queryByText(/4,129 SAR/)).not.toBeInTheDocument()
  })

  /** Two unconvertible currencies cannot be added, so no single figure may be printed. */
  it('refuses to state one figure when the withheld money is in several currencies', async () => {
    vi.mocked(getCreativePulse).mockResolvedValue(
      pulse({
        currency: 'SAR',
        spend_by_kind: [
          {
            kind: 'image', spend: null, share: null, creatives: 2, spend_not_reported: 0,
            spend_withheld_rows: 40, spend_original: 900, money_original_currency: 'USD',
            money_original_currencies: 2,
          },
        ],
      }),
    )

    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    // Scoped to the strip itself: other cards on this section carry their own, convertible money.
    const strip = (await screen.findByText('Spend by ad type')).parentElement!

    expect(strip).not.toHaveTextContent('900')
    expect(strip).not.toHaveTextContent('USD')
    expect(strip).not.toHaveTextContent('SAR')
  })

  /** A converted figure still renders in the reporting currency the SERVER named. */
  it('uses the currency the payload declares, not one compiled into the page', async () => {
    vi.mocked(getCreativePulse).mockResolvedValue(
      pulse({
        currency: 'AED',
        spend_by_kind: [
          { kind: 'image', spend: 1800, share: 1, creatives: 2, spend_not_reported: 0 },
        ],
      }),
    )

    renderWithProviders(<CreativePulseSection libraryPath="/app/content" filters={{}} />, { locale: 'en' })

    await screen.findByText('Spend by ad type')

    expect(screen.getByText(/1,800 AED/)).toBeInTheDocument()
    expect(screen.queryByText(/SAR/)).not.toBeInTheDocument()
  })

})
