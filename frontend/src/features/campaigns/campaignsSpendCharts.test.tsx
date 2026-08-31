import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listUsers: vi.fn() }))
vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'
import { getData } from '@/lib/api/client'

/**
 * PARTIAL-WITHHELD-001 — the two spend CHARTS on this page, held to the same rule as the cards.
 *
 * «توزيع الإنفاق» and the budget-consumption ring both took a raw figure: the donut sized a slice
 * from `Number(p.spend ?? 0)`, and the ring divided a spend total that now excludes withheld rows by
 * a budget total that still includes their budgets. Both read as «this platform spent nothing» and
 * «this project has barely spent» over money that was really spent — the identical defect the client
 * link's donut was fixed for in the same change.
 */
const SUMMARY = {
  current: { conversions: 40, spend: 1000, revenue: 0, roas: 0, cpa: 0 },
  previous: {},
  delta: { conversions: null, cpa: null, roas: null },
  reported: {}, commerce: null, currency: 'SAR', rows_in_scope: true,
  previous_rows_in_scope: true,
  previous_range: { from: '2026-06-27', to: '2026-07-26' },
  objective_families_in_scope: ['sales'],
  conversions_basis: {
    source: 'platform_reported' as const, label_ar: '', label_en: '', providers: ['snapchat'],
    may_double_count: false, is_unique_order_count: false as const, note_ar: '', note_en: '',
  },
}

const budgetRow = (over: Record<string, unknown>) => ({
  campaign_id: 'x', campaign_name: 'X', status: 'active',
  budget: 5000, budget_currency: 'SAR', spent: 0, spent_currency: 'SAR',
  spend_withheld: false, remaining: null, consumed_pct: null, pace: null,
  projected_spend: 0, pacing_basis: 'comparable' as const,
  ...over,
})

/** A platform row whose spend the aggregator could not convert: 500 USD, reported, no rate. */
const withheldPlatform = (provider: string) => ({
  provider, spend: 0, revenue: 0, roas: null, conversions: 0, clicks: 0, impressions: 0,
  spend_withheld_rows: 1, spend_original: 500, money_original_currency: 'USD',
  money_original_currencies: 1,
})

const convertedPlatform = (provider: string, spend: number) => ({
  provider, spend, revenue: 0, roas: null, conversions: 0, clicks: 0, impressions: 0,
  spend_withheld_rows: 0, spend_original: 0, money_original_currency: null,
  money_original_currencies: 0,
})

function route(opts: { budget: Array<Record<string, unknown>>; platforms?: Array<Record<string, unknown>> }) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) return Promise.resolve(SUMMARY)
    if (path.includes('/metrics/budget')) return Promise.resolve(opts.budget)
    if (path.includes('/metrics/platforms')) return Promise.resolve(opts.platforms ?? [])
    // No rankable campaigns, so «Best campaigns» falls through to the consumption ring.
    if (path.includes('/metrics/campaigns')) return Promise.resolve([])
    return Promise.resolve([])
  })
}

/**
 * The charts live in the Overview view, and `usePlatforms`/`useTimeseries` are deliberately disabled
 * until it is open — so a test that never switches proves nothing about either chart.
 */
async function openOverview() {
  fireEvent.click(await screen.findByTestId('view-overview'))
}

function campaign(id: string): UnifiedCampaign {
  return {
    id, project_id: 'p1', name: id, objective: 'sales', status: 'active', total_budget: 5000,
    budget_currency: 'SAR', starts_on: null, ends_on: null, primary_conversion_purpose: null,
    attribution_model: null, attribution_window: null, owner_id: null, target_kpi: null,
    audience: null, regions: null, external_campaigns_count: 2, created_at: null,
  }
}

describe('the spend charts over withheld money', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue([campaign('c1')])
    useProject.getState().setCurrentProjectId('p1')
  })

  afterEach(() => {
    signOut()
    useProject.getState().setCurrentProjectId(null)
  })

  it('leaves a withheld platform off the spend donut and says how many it left off', async () => {
    route({
      budget: [budgetRow({ campaign_id: 'c1', spent: 1000 })],
      platforms: [convertedPlatform('meta', 1000), withheldPlatform('snapchat')],
    })
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })
    await openOverview()

    // The card names the platform it could not size — a slice of 0 would have said «spent nothing».
    expect(await screen.findByText('By platform — 1 withheld')).toBeInTheDocument()
  })

  it('says nothing about withholding when every platform converted', async () => {
    route({
      budget: [budgetRow({ campaign_id: 'c1', spent: 1000 })],
      platforms: [convertedPlatform('meta', 1000), convertedPlatform('snapchat', 250)],
    })
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })
    await openOverview()

    expect(await screen.findByText('By platform')).toBeInTheDocument()
    expect(screen.queryByText(/withheld/)).not.toBeInTheDocument()
  })

  it('refuses a consumption ring when part of the spend could not be summed', async () => {
    route({
      budget: [
        budgetRow({ campaign_id: 'c1', spent: 1000 }),
        // 500 USD with no rate: its BUDGET still counts toward the total, so spent ÷ total would
        // divide two different populations and read as «barely consumed».
        budgetRow({ campaign_id: 'c2', spent: 500, spent_currency: 'USD', spend_withheld: true }),
      ],
    })
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })
    await openOverview()

    expect(await screen.findByText(/Budget consumption unavailable/i)).toBeInTheDocument()
  })

  it('still draws the ring when every campaign spend is comparable', async () => {
    route({ budget: [budgetRow({ campaign_id: 'c1', spent: 1000 })] })
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })
    // The budget caption proves the page settled before the view is switched.
    // «1K», not «1.0K»: NUMBER-PRESENTATION-001 drops a decimal that carries no information.
    expect(await screen.findByText(/1K SAR spent/)).toBeInTheDocument()
    await openOverview()

    // The ring states the real ratio; the refusal is for the withheld case alone.
    expect(await screen.findByText('1K / 5K')).toBeInTheDocument()
    expect(screen.queryByText(/Budget consumption unavailable/i)).not.toBeInTheDocument()
  })

  /*
   * PARTIAL-WITHHELD-001 (d/f) — the spend/revenue TREND fails closed, unlike the donut beside it.
   *
   * A donut drops a withheld platform and discloses the count; a trend line cannot omit a day without
   * stating a false dip, so one withheld day closes the whole series to «unavailable» rather than
   * drawing that day at the coalesced 0.
   */
  it('renders the trend as unavailable when a day is withheld, never a false line at zero', async () => {
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/metrics/summary')) return Promise.resolve(SUMMARY)
      if (path.includes('/metrics/budget')) return Promise.resolve([budgetRow({ campaign_id: 'c1', spent: 1000 })])
      if (path.includes('/metrics/timeseries')) return Promise.resolve([
        { date: '2026-08-01', spend: 1000, revenue: 0, spend_withheld_rows: 0, revenue_withheld_rows: 0 },
        // A day the platform reported in USD with no rate — converted beside it ⇒ not one currency.
        { date: '2026-08-02', spend: 0, spend_original: 500, spend_withheld_rows: 1, revenue: 0, revenue_withheld_rows: 0, money_original_currency: 'USD', money_original_currencies: 1 },
      ])
      return Promise.resolve([])
    })
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })
    await openOverview()

    expect(await screen.findByText(/Spend\/revenue over time unavailable/i)).toBeInTheDocument()
  })
})
