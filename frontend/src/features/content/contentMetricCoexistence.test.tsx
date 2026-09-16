import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import { creativeDialogFigures } from './creativeDialogFigures'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * Owner defect 95, the reader's half — «Spend appears and the other KPIs disappear».
 *
 * ## Two distinct failures, and neither is about arithmetic
 *
 * **The card falls silent without saying so.** `CreativeMetrics::supportable()` returns `['spend']`
 * when nothing else about a creative can be headlined — the deliberate last resort, «the one question
 * asked of every campaign». The card then renders Spend as its FIXED first cell and filters `spend`
 * out of the list it renders beside it, so nothing stands beside the price. The sentence that exists
 * for exactly this state, `noDisplayableMetrics`, is gated on `headline_metrics.length === 0` — and
 * the length is 1. So the panel could never fire in the one case it was written for, and the reader
 * got a card with a price on it, three empty columns, and no explanation. That is the owner's
 * sentence rendered literally.
 *
 * **The popup drops a withheld revenue the card shows.** `creativeDialogFigures` admitted Revenue on
 * `typeof bag.revenue === 'number'`. FX-001 withholds an unconvertible figure by design — `revenue`
 * null, `revenue_original` holding the real amount, `money_original_currency` naming it — which is
 * the state of every Snapchat row on the owner's own account, a USD account with no USD→SAR rate. The
 * card renders that through `creativeMoney` and the popup one click away dropped it entirely, so the
 * same creative had revenue on one surface and none on the next.
 *
 * Both are the same mistake in two places: asking whether a CONVERTED number is present instead of
 * asking the money contract what it holds. The contract names four states and only one of them is
 * «absent».
 */
const PREVIEW = {
  state: 'available' as const,
  kind: 'image' as const,
  image_url: 'https://cdn.test/a.jpg',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
}

/**
 * A creative the platform PRICED and could headline nothing else about.
 *
 * Every figure but spend absent, and `reported` saying the platform does not send them — which is
 * what makes `supportable()` fall through to its last resort.
 */
const PRICED_ONLY = {
  spend: 412.5,
  impressions: null,
  clicks: null,
  conversions: null,
  revenue: null,
  reported: { spend: true, impressions: false, clicks: false, conversions: false, revenue: false },
}

/** Production's Snapchat shape for BOTH figures: real money, no rate, so both are withheld. */
const WITHHELD_BOTH = {
  spend: null,
  spend_original: 412.5,
  spend_withheld_rows: 3,
  revenue: null,
  revenue_original: 1_980.25,
  revenue_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
  impressions: 90_000,
  clicks: 300,
  ctr: 0.0033,
  reported: { spend: true, revenue: true, impressions: true, clicks: true },
}

const card = (over: Record<string, unknown> = {}) => ({
  id: 'c1',
  name: 'Eid hero',
  format: 'image',
  provider: 'snapchat',
  status: 'active',
  campaign_id: 'camp1',
  campaign_name: 'Always-On',
  ad_set_id: null,
  ads: [],
  preview: PREVIEW,
  aspect_ratio: null,
  duration_seconds: null,
  width: null,
  height: null,
  file_size: null,
  source_type: 'api',
  creative_group_id: null,
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
  objective: 'sales',
  path: 'conversion',
  headline_metrics: ['spend'],
  ad_delivered: true,
  metrics: PRICED_ONLY,
  fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' },
  ...over,
})

const page = (over: Record<string, unknown> = {}) => ({
  creatives: [card()],
  page: 1,
  per_page: 24,
  total: 1,
  period: { from: '2026-07-25', to: '2026-08-23' },
  currency: 'SAR',
  metrics_availability: { snapchat: { status: 'success', rows: 819, error: null, at: null } },
  filters: {
    providers: ['snapchat'], statuses: [], kinds: [], campaigns: [], ad_sets: [], ads: [],
    objectives: [], paths: [], projects: [], clients: [], health: [],
  },
  ...over,
})

async function renderCard(over: Record<string, unknown> = {}) {
  vi.mocked(listCreatives).mockResolvedValue(page({ creatives: [card(over)] }) as never)
  renderWithProviders(<CreativesPage />, { locale: 'en' })

  return screen.findByTestId('creative-card-metrics')
}

describe('a card that can only be priced says so', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  /**
   * The owner's sentence, mechanically: Spend and nothing else, with no reason given.
   *
   * The price must stay — a creative somebody spent money on is still that — and the gap beside it
   * must be explained, because a card with one figure and three blanks reads as a broken page rather
   * than as a platform that reported nothing else.
   */
  it('explains the gap when Spend is the only figure the server could headline', async () => {
    const metrics = await renderCard({ headline_metrics: ['spend'] })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/412\.5/)).toBeInTheDocument()
    expect(
      within(metrics).getByText(/No displayable performance metrics/i),
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition
    ).toBeInTheDocument()
  })

  /** And it keeps saying so when the server headlines nothing at all — the case that already worked. */
  it('explains the gap when the server headlines nothing at all', async () => {
    const metrics = await renderCard({ headline_metrics: [] })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/No displayable performance metrics/i)).toBeInTheDocument()
  })

  /** A card that HAS figures beside Spend must not acquire the sentence — it would be a false one. */
  it('says nothing about a gap when the objective\'s own figures are there', async () => {
    const metrics = await renderCard({
      headline_metrics: ['spend', 'impressions', 'clicks'],
      metrics: { ...PRICED_ONLY, impressions: 90_000, clicks: 300, reported: { spend: true, impressions: true, clicks: true } },
    })

    expect(within(metrics).getByText('Impressions')).toBeInTheDocument()
    expect(within(metrics).queryByText(/No displayable performance metrics/i)).not.toBeInTheDocument()
  })
})

describe('the popup reads money the way the card reads it', () => {
  /**
   * A withheld revenue is real money in its own currency, and the popup dropped it.
   *
   * `readMoney` calls this `withheld`, not `absent`, and the card prints «1,980.25 USD» from exactly
   * these fields. A `typeof … === 'number'` check cannot tell the two apart, so the figure vanished
   * one click from where it was shown.
   */
  it('carries a withheld revenue rather than dropping it', () => {
    const figures = creativeDialogFigures(WITHHELD_BOTH as never, 'SAR', false, ['spend', 'revenue'])
    const labels = figures.map((f) => f.label)

    expect(labels).toContain('Revenue')
    expect(figures.find((f) => f.label === 'Revenue')?.value).toMatch(/1,980\.25 USD/)
  })

  /** The price is read the same way, and was already right — asserted so the pair cannot drift. */
  it('carries a withheld spend in its own currency', () => {
    const figures = creativeDialogFigures(WITHHELD_BOTH as never, 'SAR', false, ['spend'])

    expect(figures.find((f) => f.label === 'Spend')?.value).toMatch(/412\.50 USD/)
  })

  /**
   * A revenue the provider never reported is still left out.
   *
   * The fix must widen the question from «is it a number» to «what does the contract hold», not
   * abandon it: a tile reading «Revenue —» on every awareness creative teaches a reader to skip the
   * row where the sales creatives state theirs.
   */
  it('leaves out a revenue nobody reported', () => {
    const figures = creativeDialogFigures(
      { spend: 100, impressions: 10, clicks: 1, reported: { spend: true } } as never,
      'SAR',
      false,
      ['spend'],
    )

    expect(figures.map((f) => f.label)).not.toContain('Revenue')
  })

  /** A measured zero is a fact about the campaign and belongs on the panel. */
  it('carries a revenue measured as zero', () => {
    const figures = creativeDialogFigures(
      { spend: 100, revenue: 0, impressions: 10, clicks: 1, reported: { spend: true, revenue: true } } as never,
      'SAR',
      false,
      ['spend', 'revenue'],
    )

    expect(figures.map((f) => f.label)).toContain('Revenue')
  })
})
