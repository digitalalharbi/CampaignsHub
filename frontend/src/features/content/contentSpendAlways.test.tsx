import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * CONTENT-SPEND-ALWAYS-001 — Spend is an operational fact, not an objective's opinion.
 *
 * ## The defect, and why every existing test passed over it
 *
 * The card renders `creative.headline_metrics.slice(0, 4)`. `headline_metrics` is the server's
 * answer to «what is this creative JUDGED on», which is an objective question: an awareness video is
 * headlined on reach and view rate, a traffic ad on clicks and CPC. Spend is in that list for some
 * objectives and not for others — so on production, Spend simply vanished from the card for every
 * creative whose objective did not happen to headline it, while the table beside it rendered Spend
 * explicitly and the money reader handled it correctly the whole time.
 *
 * `contentVisible.test.tsx` asserts Spend appears, and passes, because its fixture is
 * `headline_metrics: ['spend', 'impressions']`. A fixture that contains the thing under test is how
 * a defect survives its own regression test.
 *
 * ## The contract
 *
 * «Spend is a mandatory operational figure for every promoted creative where the provider reported
 * it. It must remain visible regardless of objective.» So the card carries a FIXED Spend cell, read
 * through `creativeMoney` — the same canonical reader the table, the popup and the report use, which
 * already knows the four money states — and the objective's own metrics follow it, dynamic as they
 * should be. The card stays compact: one fixed figure and three chosen ones, not seven.
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

/** Converted money: the provider reported it and a rate existed. */
const CONVERTED = {
  spend: 1234.5,
  impressions: 90_000,
  clicks: 300,
  conversions: 12,
  reach: 40_000,
  view_rate: 0.42,
  reported: { spend: true, impressions: true, clicks: true },
}

/** Production's Snapchat shape: a USD account with no USD→SAR rate, so the figure is withheld. */
const ORIGINAL_ONLY = {
  spend: null,
  spend_original: 412.5,
  spend_withheld_rows: 3,
  money_original_currency: 'USD',
  money_original_currencies: 1,
  impressions: 90_000,
  clicks: 300,
  reported: { spend: true, impressions: true, clicks: true },
}

const card = (over: Record<string, unknown> = {}) => ({
  id: 'c1',
  name: 'Eid hero',
  format: 'image',
  provider: 'meta',
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
  /* Deliberately WITHOUT spend — the case the card could not render. */
  headline_metrics: ['conversions', 'cpa', 'roas'],
  ad_delivered: true,
  metrics: CONVERTED,
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
  metrics_availability: { meta: { status: 'success', rows: 819, error: null, at: null } },
  filters: {
    providers: ['meta'], statuses: [], kinds: [], campaigns: [], ad_sets: [], ads: [],
    objectives: [], paths: [], projects: [], clients: [], health: [],
  },
  ...over,
})

async function renderCard(over: Record<string, unknown> = {}) {
  vi.mocked(listCreatives).mockResolvedValue(page({ creatives: [card(over)] }) as never)
  renderWithProviders(<CreativesPage />, { locale: 'en' })

  return screen.findByTestId('creative-card-metrics')
}

describe('Spend survives the objective that does not headline it', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  /** Sales: headlined on conversions, CPA and ROAS. Spend is none of them, and must still show. */
  it('shows Spend on a sales creative whose headline metrics exclude it', async () => {
    const metrics = await renderCard({ objective: 'sales', headline_metrics: ['conversions', 'cpa', 'roas'] })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/1,234\.5/)).toBeInTheDocument()
  })

  /** Awareness: reach and view rate. Spend is still what it cost. */
  it('shows Spend on an awareness creative whose headline metrics exclude it', async () => {
    const metrics = await renderCard({
      objective: 'awareness',
      headline_metrics: ['reach', 'impressions', 'view_rate'],
    })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/1,234\.5/)).toBeInTheDocument()
  })

  /** A video creative, headlined on completion — the owner's third named case. */
  it('shows Spend on a video creative whose headline metrics exclude it', async () => {
    const metrics = await renderCard({
      format: 'video',
      objective: 'awareness',
      headline_metrics: ['video_completion_rate', 'impressions'],
    })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/1,234\.5/)).toBeInTheDocument()
  })

  /**
   * «We cannot headline it» is not «we do not know what it cost».
   *
   * With no headline metrics at all the card showed a reason panel and nothing else, so a creative
   * the platform HAD priced looked unpriced.
   */
  it('shows Spend even when the server headlines nothing', async () => {
    const metrics = await renderCard({ headline_metrics: [] })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).getByText(/1,234\.5/)).toBeInTheDocument()
  })

  /**
   * The Snapchat case, which is the one the owner named: no rate, so the ORIGINAL amount shows.
   *
   * Never «0», and never the reporting currency — 412.50 USD is what the provider said.
   */
  it('shows the original amount and currency when no conversion exists', async () => {
    const metrics = await renderCard({
      provider: 'snapchat',
      headline_metrics: ['impressions', 'clicks'],
      metrics: ORIGINAL_ONLY,
    })

    expect(within(metrics).getByText(/412\.50 USD/)).toBeInTheDocument()
    expect(within(metrics).queryByText(/^0 SAR$/)).not.toBeInTheDocument()
  })

  /** A measured zero is a fact and reads as one — never as «—». */
  it('shows a reported zero as zero', async () => {
    const metrics = await renderCard({
      headline_metrics: ['impressions'],
      metrics: { ...CONVERTED, spend: 0 },
    })

    expect(within(metrics).getByText('Spend')).toBeInTheDocument()
    expect(within(metrics).queryByText('—')).not.toBeInTheDocument()
  })

  /**
   * And an unreported spend is NOT turned into zero.
   *
   * This is the case that keeps the fix honest: «always show Spend» must not become «always show a
   * number», which would invent a figure for a creative nobody priced.
   */
  it('never converts an unreported spend into zero', async () => {
    const metrics = await renderCard({
      headline_metrics: ['impressions'],
      metrics: {
        impressions: 90_000,
        clicks: 300,
        reported: { spend: false, impressions: true, clicks: true },
      },
    })

    expect(within(metrics).queryByText(/^0$/)).not.toBeInTheDocument()
    expect(within(metrics).queryByText(/^0 SAR$/)).not.toBeInTheDocument()
  })
})
