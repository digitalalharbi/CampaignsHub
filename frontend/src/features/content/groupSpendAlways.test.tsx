import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { CreativeGroupsPage } from './CreativeGroupsPage'
import type { CreativeCard, CreativeGroupDetail, CreativeGroupSummary, CreativeMetrics } from './api'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreativeGroups: vi.fn(), getCreativeGroup: vi.fn(), ungroupCreative: vi.fn() }
})

import { getCreativeGroup, listCreativeGroups } from './api'

/**
 * CONTENT-SPEND-ALWAYS-001 — the groups surface, which the card's fix did not reach.
 *
 * ## Why this is a separate defect from the card's
 *
 * CONTENT-SPEND-ALWAYS-001 fixed `CreativesPage` and `CreativeDetailPage`. Groups is the third
 * Content surface that renders a creative's money, and it had BOTH halves of the same bug:
 *
 *   1. `keys = headline_metrics.length > 0 ? headline_metrics : ['spend', …]` — the fallback names
 *      spend, so a group with NO agreed objective shows it, and a group that HAS one shows it only
 *      if the objective's own headline set happens to include it. Backwards: the fallback is the
 *      case where the product knows least, and it is the only case that was guaranteed the figure.
 *
 *   2. every cell went through `metricState`, which reads the CONVERTED column only. Production's
 *      Snapchat accounts are USD with no USD→SAR rate, so `spend` is null by FX-001's design and
 *      `spend_original` holds the real amount — and `metricState` called that «no data». The group
 *      card said a film never spent anything while the library card beside it, since #389, said
 *      «412.50 USD» for the same creative. Two answers to one question.
 *
 * Both places this page renders figures had it: the group's own card, and the per-platform table
 * inside an open group.
 *
 * ## The contract, unchanged from #389
 *
 * Spend is an operational fact. It is a FIXED figure on every group regardless of objective, read
 * through `creativeMoney` — the one canonical money reader — and the objective's own metrics follow
 * it. Revenue, the other money key, reads through the same reader wherever it appears.
 */

/** Production's Snapchat shape: reported in USD, no rate, so the converted column is empty. */
const WITHHELD: Partial<CreativeMetrics> = {
  spend: null,
  revenue: null,
  spend_original: 412.5,
  revenue_original: null,
  spend_withheld_rows: 3,
  revenue_withheld_rows: 0,
  money_original_currency: 'USD',
  money_original_currencies: 1,
}

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 500,
  impressions: 65000,
  clicks: 1100,
  conversions: 14,
  revenue: 4200,
  video_views: 30000,
  video_p25: null,
  video_p50: null,
  video_p75: null,
  video_p100: null,
  frequency: 1.3,
  ctr: 0.017,
  cpc: 0.45,
  cpm: 7.7,
  cpa: 35.7,
  roas: 8.4,
  conversion_rate: 0.013,
  view_rate: 0.46,
  completion_rate: 0.11,
  active_days: 12,
  reported: { spend: true, impressions: true, clicks: true, conversions: true, revenue: true },
  ...over,
}) as CreativeMetrics

const summary = (over: Partial<CreativeGroupSummary> = {}): CreativeGroupSummary => ({
  id: 'grp-1',
  name: 'The film',
  method: 'manual',
  confirmed: true,
  confirmed_at: '2026-08-01T10:00:00+03:00',
  project_id: 'proj-1',
  creative_count: 2,
  providers: ['snapchat', 'meta'],
  objectives: ['awareness'],
  paths: ['awareness'],
  objective: 'awareness',
  mixed_objectives: false,
  // An awareness group: the objective's headline set does NOT name spend.
  headline_metrics: ['impressions', 'reach', 'cpm'],
  metrics: metrics(),
  mixed_reason_ar: null,
  mixed_reason_en: null,
  ...over,
})

const member = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1',
    name: 'The film · Snapchat',
    format: 'video',
    provider: 'snapchat',
    status: 'active',
    campaign_id: 'cmp-1',
    campaign_name: 'Sale',
    ad_set_id: null,
    preview: {
      state: 'available', kind: 'video', image_url: null, video_url: null,
      thumbnail_url: null, expires_at: null, note_ar: null, note_en: null,
    },
    aspect_ratio: '1:1',
    duration_seconds: 20,
    width: 600,
    height: 600,
    file_size: null,
    grouped: true,
    group_id: 'grp-1',
    is_demo: false,
    freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
    objective: 'awareness',
    path: 'awareness',
    headline_metrics: ['impressions', 'reach', 'cpm'],
    metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
    ...over,
  }) as CreativeCard

const detail = (over: Partial<CreativeGroupDetail> = {}): CreativeGroupDetail => ({
  ...summary(),
  currency: 'SAR',
  members: [member(), member({ id: 'cr-2', name: 'The film · Meta', provider: 'meta' })],
  by_platform: [
    { provider: 'snapchat', creative_count: 1, creative_ids: ['cr-1'], metrics: metrics({ spend: 400 }) },
    { provider: 'meta', creative_count: 1, creative_ids: ['cr-2'], metrics: metrics({ spend: 100 }) },
  ],
  period: { from: '2026-07-08', to: '2026-08-06' },
  audit: [],
  ...over,
})

const signIn = () =>
  useAuth.setState({
    user: { id: '1', name: 'Op', permissions: ['campaigns.view', 'campaigns.link'], is_platform_admin: false } as unknown as AuthUser,
    status: 'authenticated',
  })

const list = (groups: CreativeGroupSummary[]) =>
  vi.mocked(listCreativeGroups).mockResolvedValue({
    groups, page: 1, per_page: 24, total: groups.length,
    period: { from: '2026-07-08', to: '2026-08-06' }, currency: 'SAR',
  })

/**
 * Both locales, because the Owner's acceptance list names AR/RTL and EN/LTR, and because a label
 * asserted in one language is how a surface ships correct in one and wrong in the other.
 */
const LOCALES = [
  { locale: 'ar' as const, spend: 'الإنفاق' },
  { locale: 'en' as const, spend: 'Spend' },
]

describe.each(LOCALES)('a group states its spend whatever it was bought for ($locale)', ({ locale, spend }) => {
  const render = (route = '/app/content/groups') =>
    renderWithProviders(<CreativeGroupsPage portal="app" />, { route, locale })

  beforeEach(() => {
    vi.clearAllMocks()
    signIn()
    list([summary()])
    vi.mocked(getCreativeGroup).mockResolvedValue(detail())
  })

  /** The defect exactly as it shipped: an awareness group, spend absent from `headline_metrics`. */
  it('shows spend on an awareness group whose headline set does not name it', async () => {
    render()

    expect(await screen.findByText('The film')).toBeInTheDocument()
    expect(screen.getByText(spend)).toBeInTheDocument()
    expect(screen.getByText(/500/)).toBeInTheDocument()
  })

  /** A sales group names spend itself — it must appear ONCE, not twice. */
  it('does not print spend twice when the objective already names it', async () => {
    list([summary({ objective: 'sales', paths: ['conversion'], headline_metrics: ['spend', 'conversions', 'roas'] })])

    render()

    expect(await screen.findByText('The film')).toBeInTheDocument()
    expect(screen.getAllByText(spend)).toHaveLength(1)
  })

  /**
   * The other half of the bug, and the one Production actually has.
   *
   * A withheld figure is not an absent one. `metricState` cannot tell the difference because it
   * never looks at `spend_original`; `creativeMoney` can.
   */
  it('prints a withheld Snapchat spend in its original currency, not as no data', async () => {
    list([summary({ metrics: metrics(WITHHELD) })])

    render()

    expect(await screen.findByText('The film')).toBeInTheDocument()
    expect(screen.getByText(/412\.5/).textContent).toMatch(/USD/)
  })

  /** «Not reported» must not become «0». A false zero is a decision made on a lie. */
  it('never renders an unreported spend as zero', async () => {
    list([summary({ metrics: metrics({ spend: null, spend_original: null, reported: { spend: false } } as Partial<CreativeMetrics>) })])

    render()

    expect(await screen.findByText('The film')).toBeInTheDocument()
    const cell = screen.getByText(spend).closest('div')!
    expect(cell.textContent).not.toMatch(/(^|\D)0($|\D)/)
  })

  /**
   * A group with no agreed objective withholds the JUDGEMENT, not the arithmetic — so it keeps
   * spend for the same reason every other group does, not as a special case.
   */
  it('shows spend on a group whose members disagree about the objective', async () => {
    list([summary({ mixed_objectives: true, objective: null, headline_metrics: [], objectives: ['sales', 'awareness'] } as Partial<CreativeGroupSummary>)])

    render()

    expect(await screen.findByText('The film')).toBeInTheDocument()
    expect(screen.getByText(spend)).toBeInTheDocument()
    expect(screen.getByText(/500/)).toBeInTheDocument()
  })

  /** The table is a second renderer of the same figures, and it had the same two bugs. */
  it('carries a spend column in the per-platform table, original currency where withheld', async () => {
    vi.mocked(getCreativeGroup).mockResolvedValue(detail({
      by_platform: [
        { provider: 'snapchat', creative_count: 1, creative_ids: ['cr-1'], metrics: metrics(WITHHELD) },
        { provider: 'meta', creative_count: 1, creative_ids: ['cr-2'], metrics: metrics({ spend: 100 }) },
      ],
    }))

    render('/app/content/groups?group=grp-1')

    const table = await screen.findByRole('table')
    expect(within(table).getAllByText(spend).length).toBeGreaterThan(0)
    expect(within(table).getByText(/412\.5/).textContent).toMatch(/USD/)
  })
})
