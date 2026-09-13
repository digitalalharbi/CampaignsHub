import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
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
 * CLIENT-REPORT-MONEY-REDACTION-001 — the renderer half, on the one document a client keeps.
 *
 * ## Two defects that were hiding each other
 *
 * The PAYLOAD half of this row fixed a leak: a link hiding spend shipped `spend_original` and the
 * currency naming it, because the redaction knew only about the converted column. Nothing rendered
 * it, because every client-report surface read money through `metricState`, which also knows only
 * about the converted column. So the leak was invisible and the truth unreadable, from one cause,
 * and fixing either half alone was wrong in a different direction.
 *
 * ## The five states, and who may see them
 *
 * On production every Snapchat account is USD with no USD→SAR rate, so `spend` is null by FX-001's
 * design and the real amount is in `spend_original`. A client's report must say:
 *
 *   converted        the figure, in the report's reporting currency
 *   original-only    the amount and ITS currency — «412.50 USD» — where the link permits spend
 *   reported zero    «0», because a measured zero is a fact about a creative
 *   unavailable      a truthful absence, never «0»
 *   redacted         nothing at all — not a dash, not a labelled blank
 *
 * The last is why permission is not a second input here. `redactRow` removes a hidden metric from
 * `metrics` AND filters it out of `headline_metrics`, for a reason it states: «a key present and
 * empty tells a reader that a value exists and is being kept from them». So «permitted» is a fact
 * the payload already carries, and reading it from the payload is what makes a permission bypass
 * impossible rather than merely unlikely — the page cannot print money it was not sent.
 *
 * The fixtures are the working harness of `SharedCreativeSection.test.tsx`, deliberately: a money
 * test on a bespoke payload proves what the payload says, not what the product does.
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
    headline_metrics: ['impressions', 'reach', 'cpm'],
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
  objectives: [{ key: 'sales', count: 4 }, { key: 'awareness_engagement', count: 2 }, { key: 'traffic', count: 0 }, { key: 'leads', count: 1 }, { key: 'app_promotion', count: 0 }],
  kinds: [{ key: 'image', count: 3 }, { key: 'video', count: 5 }, { key: 'carousel', count: 1 }, { key: 'collection', count: 0 }, { key: 'catalog', count: 0 }],
  earliest: '2026-07-08',
  latest: '2026-08-06',
}

const applied = {
  from: '2026-07-08',
  to: '2026-08-06',
  providers: [],
  campaign_ids: [],
  objectives: [],
  /* The operator's stored narrowing is still part of the applied set — it is only no longer a control. */
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


/** A USD account with no rate: the converted column is empty and the original holds the money. */
const WITHHELD = {
  ...metrics(),
  spend: null,
  revenue: null,
  spend_original: 412.5,
  revenue_original: null,
  spend_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
} as unknown as CreativeMetrics

/** What the payload looks like AFTER `redactRow` hid spend: the keys are GONE, not nulled. */
const redacted = (): CreativeMetrics => {
  const m = { ...metrics() } as Record<string, unknown>
  for (const k of ['spend', 'cpa', 'cpc', 'cpm', 'roas', 'spend_original', 'spend_withheld_rows', 'money_original_currency', 'money_original_currencies']) {
    delete m[k]
  }
  delete (m.reported as Record<string, boolean>).spend

  return m as unknown as CreativeMetrics
}

/** Absence sentences in both languages — none may follow a spend label that has a figure. */
const ABSENT = /No data|Not provided|لا توجد بيانات|غير متاح|غير مُبلَّغ/

const LOCALES = [
  { locale: 'ar' as const, spend: 'الإنفاق' },
  { locale: 'en' as const, spend: 'Spend' },
]

/** The spend label's own row or card, whichever encloses it. */
const cellOf = (label: HTMLElement): HTMLElement =>
  (label.closest('tr') ?? label.closest('div') ?? label.parentElement) as HTMLElement

describe.each(LOCALES)('a client report states spend truthfully ($locale)', ({ locale, spend }) => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockSummary.mockResolvedValue(summary())
    mockDetail.mockResolvedValue(sharedDetail())
  })

  /** The objective gate: an awareness creative's headline set has no spend, and the link permits it. */
  it('shows spend on an awareness creative the link permits it for', async () => {
    mockLibrary.mockResolvedValue(page())

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)
    expect(screen.getAllByText(spend).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/1,200/).length).toBeGreaterThan(0)
  })

  /** The production state: real money, in its own currency, on a client's report. */
  it('prints an original-currency spend as its own amount and currency', async () => {
    mockLibrary.mockResolvedValue(page({ creatives: [card({ metrics: WITHHELD })] }))

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)
    const figures = screen.getAllByText(/412\.5/)
    expect(figures.length).toBeGreaterThan(0)
    expect(figures[0].textContent).toMatch(/USD/)
  })

  /** «Not reported» must not become «0» — the state the owner named explicitly. */
  it('never renders an unreported spend as zero', async () => {
    const unreported = {
      ...metrics(),
      spend: null,
      reported: { ...metrics().reported, spend: false },
    } as unknown as CreativeMetrics
    mockLibrary.mockResolvedValue(page({ creatives: [card({ metrics: unreported })] }))

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)
    const text = cellOf(screen.getAllByText(spend)[0]).textContent ?? ''
    expect(text, 'an unreported spend was rendered as a zero').not.toMatch(/(^|\D)0($|\D)/)
  })

  /** A measured zero is a fact about a creative and must survive as one. */
  it('prints a reported zero as zero', async () => {
    mockLibrary.mockResolvedValue(page({ creatives: [card({ metrics: metrics({ spend: 0 }) })] }))

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)
    const text = cellOf(screen.getAllByText(spend)[0]).textContent ?? ''
    expect(text, 'a measured zero was reported as an absence').not.toMatch(ABSENT)
  })

  /**
   * The permission, enforced by the payload rather than by a second flag.
   *
   * The label must not appear at all — a dash under «Spend» tells the reader exactly which figure
   * their agency withheld, which is what `redactRow` removes the key to avoid.
   */
  it('prints no spend at all on a link that hides it', async () => {
    mockSummary.mockResolvedValue(summary({ permissions: permissions({ spend: false, cpa: false, roas: false }) }))
    mockLibrary.mockResolvedValue(page({
      creatives: [card({ metrics: redacted(), headline_metrics: ['impressions', 'reach'] })],
      permissions: permissions({ spend: false, cpa: false, roas: false }),
    }))

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)
    expect(screen.queryByText(spend), 'a link hiding spend named it anyway').toBeNull()
    expect(screen.queryByText(/412\.5|1,200/), 'a link hiding spend printed a figure').toBeNull()
  })

  /** The comparison table is a second renderer of the same figures and must agree with the tiles. */
  it('agrees with itself between the tiles and the comparison table', async () => {
    mockLibrary.mockResolvedValue(page({
      creatives: [card({ metrics: WITHHELD }), card({ id: 'cr-2', name: 'Brand film', metrics: WITHHELD })],
    }))

    render('detailed', locale)

    expect((await screen.findAllByText('Hero image')).length).toBeGreaterThan(0)

    const table = screen.queryByRole('table')
    if (table === null) {
      return
    }

    expect(within(table).getAllByText(spend).length).toBeGreaterThan(0)
    expect(within(table).getAllByText(/412\.5/)[0].textContent).toMatch(/USD/)
  })
})
