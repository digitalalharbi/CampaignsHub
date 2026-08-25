import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

/**
 * CONTENT-KPI-TRACE-001 — the card, fed the figures production actually returns.
 *
 * The `integrations:diagnose` trace of 2026-08-24 walked the same path the library walks and printed
 * the first three cards the default sort puts on page one. This reproduces the first of them EXACTLY
 * — not a plausible-looking fixture, the recorded numbers:
 *
 *     objective  : SALES   card shows: spend, impressions, clicks, ctr
 *     spend      : null   original 79.614004 USD  withheld_rows 11
 *     impressions: 33967   clicks 546   ctr 0.0161
 *     reach      : null   video_views 4409
 *     results    : conversions 0   revenue null   roas null
 *     reported by the platform: clicks, conversions, impressions, orders, video_completions, video_views
 *     active_days: 11
 *
 * Every existing fixture in this suite carries a CONVERTED spend, so none of them can reproduce the
 * one state this account is actually in: a real amount the platform reported, in a currency there is
 * no rate for. That is the gap this file closes.
 *
 * The claim under test is narrow and it is the whole question: given that payload, does the browser
 * put four figures on the card, or does the operator see an empty one?
 */
const PRODUCTION_METRICS: CreativeMetrics = {
  // Withheld: FX-001 refuses to convert without a rate, and the ORIGINAL is preserved beside it.
  spend: null,
  spend_original: 79.614004,
  spend_withheld_rows: 11,
  revenue: null,
  revenue_original: 0,
  revenue_withheld_rows: 11,
  money_original_currency: 'USD',
  money_original_currencies: 1,

  impressions: 33967,
  clicks: 546,
  ctr: 0.0161,
  conversions: 0,
  orders: 0,

  // Not reported at creative grain by this platform — null, never zero.
  reach: null,
  frequency: null,
  cpc: null,
  cpm: null,
  cpa: null,
  roas: null,
  conversion_rate: 0,
  video_views: 4409,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  view_rate: null,
  completion_rate: null,
  active_days: 11,

  reported: {
    clicks: true,
    conversions: true,
    impressions: true,
    orders: true,
    video_completions: true,
    video_views: true,
    spend: false,
    revenue: false,
    reach: false,
    frequency: false,
  },
} as unknown as CreativeMetrics

const PRODUCTION_CARD: CreativeCard = {
  id: '81632089-be82-4693-ad0e-aa348b8d4ac8',
  name: 'June 11, 2026 -Product Offers 5wat',
  format: 'video',
  provider: 'snapchat',
  status: 'active',
  campaign_id: 'cmp-1',
  campaign_name: 'Product Offers',
  preview: {
    state: 'available',
    kind: 'video',
    image_url: null,
    video_url: 'https://cf.sc-cdn.net/asset.mp4',
    thumbnail_url: null,
    expires_at: null,
    note_ar: null,
    note_en: null,
  },
  aspect_ratio: '9:16',
  duration_seconds: 12,
  width: 1080,
  height: 1920,
  file_size: 2048000,
  grouped: false,
  is_demo: false,
  freshness: {
    last_synced_at: '2026-08-24T08:00:00+00:00',
    source_updated_at: null,
    first_seen_at: null,
    last_active_at: '2026-08-24T00:00:00+00:00',
  },
  // The RAW provider string, exactly as `unified_campaigns.objective` holds it in production.
  objective: 'SALES',
  path: 'awareness',
  // What the deployed backend chose for it, verbatim from the trace.
  headline_metrics: ['spend', 'impressions', 'clicks', 'ctr', 'cpm'],
  ad_delivered: false,
  metrics: PRODUCTION_METRICS,
  fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
} as unknown as CreativeCard

const PRODUCTION_PAGE: LibraryPage = {
  creatives: [PRODUCTION_CARD],
  page: 1,
  per_page: 24,
  total: 1,
  period: { from: '2026-07-26', to: '2026-08-24' },
  // The account reports in USD; the project reports in SAR. This is the pairing that broke the cell.
  currency: 'SAR',
  metrics_availability: { snapchat: { status: 'success', rows: 878, error: null, at: null } },
  filters: {
    providers: ['snapchat'], formats: ['video'], statuses: ['active'], kinds: ['video'],
    campaigns: [{ id: 'cmp-1', name: 'Product Offers', objective: 'SALES' }],
    ad_sets: [], ads: [], objectives: ['SALES'], paths: ['awareness'],
    projects: [], clients: [], health: ['stable'],
  },
} as unknown as LibraryPage

describe('the Content card, fed what production actually returns', () => {
  beforeEach(() => {
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
    vi.mocked(listCreatives).mockResolvedValue(PRODUCTION_PAGE)
  })

  /** The KPI area exists at all — the empty-reason panel must NOT have replaced it. */
  it('renders the metric grid rather than the «why is this empty» panel', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByText(/June 11, 2026/)).toBeInTheDocument())

    // `metrics` is a real object, so the card is not entitled to the empty-state panel.
    expect(screen.queryByTestId('creative-empty-reason')).not.toBeInTheDocument()
  })

  /**
   * The four figures, by value.
   *
   * Asserted as text a person would read, because «the key is present in the payload» is exactly
   * what was already true while the operator saw nothing.
   */
  it('shows the withheld spend as a real amount in its own currency, never as 0 SAR', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByText(/June 11, 2026/)).toBeInTheDocument())

    const body = document.body.textContent ?? ''

    expect(body).toMatch(/79\.61/)
    expect(body).toContain('USD')
    expect(body).not.toMatch(/0[\s,.]*SAR/)
  })

  it('shows impressions, clicks and CTR as figures', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByText(/June 11, 2026/)).toBeInTheDocument())

    const body = document.body.textContent ?? ''

    expect(body).toMatch(/33,?967/)
    expect(body).toMatch(/546/)
    expect(body).toMatch(/1\.6/)
  })
})
