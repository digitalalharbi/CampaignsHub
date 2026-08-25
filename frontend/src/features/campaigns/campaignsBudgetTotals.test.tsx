import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
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
 * PARTIAL-WITHHELD-001 — the project budget card must not sum a withheld spend as a zero.
 *
 * `budgetPacing` marks a row `spend_withheld` when its spend is the platform's own figure in a
 * currency no rate could convert. Summed with `Number(r.spent ?? 0)` that campaign contributed 0 and
 * vanished from the total, so a project that really spent read a smaller, confident number. The card
 * now sums only the convertible rows and SAYS how many it left out.
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

function routeBudget(rows: Array<Record<string, unknown>>) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) return Promise.resolve(SUMMARY)
    if (path.includes('/metrics/budget')) return Promise.resolve(rows)
    return Promise.resolve([])
  })
}

function campaign(id: string): UnifiedCampaign {
  return {
    id, project_id: 'p1', name: id, objective: 'sales', status: 'active', total_budget: 5000,
    budget_currency: 'SAR', starts_on: null, ends_on: null, primary_conversion_purpose: null,
    attribution_model: null, attribution_window: null, owner_id: null, target_kpi: null,
    audience: null, regions: null, external_campaigns_count: 2, created_at: null,
  }
}

describe('the project budget card over withheld spend', () => {
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

  it('sums only the convertible spend and names the campaigns it could not include', async () => {
    routeBudget([
      budgetRow({ campaign_id: 'c1', spent: 1000, spent_currency: 'SAR', spend_withheld: false }),
      // 500 USD the platform reported, no rate — must NOT be added as 0 and must not vanish.
      budgetRow({ campaign_id: 'c2', spent: 500, spent_currency: 'USD', spend_withheld: true }),
    ])
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })

    // The convertible 1,000 SAR is stated and the withheld campaign is COUNTED, not summed to 0.
    // (The label renders before the budget query resolves, so this awaits the resolved caption.)
    expect(await screen.findByText(/1\.0K SAR spent \(\+1 withheld\)/)).toBeInTheDocument()
    // The wrong answers: the withheld 500 neither swelled the total (1.5K) nor collapsed it to «0 SAR».
    expect(screen.queryByText(/1\.5K SAR/)).not.toBeInTheDocument()
    expect(screen.queryByText(/0 SAR spent/)).not.toBeInTheDocument()
  })

  it('states spend is unconvertible rather than «0 spent» when every row is withheld', async () => {
    routeBudget([
      budgetRow({ campaign_id: 'c1', spent: 500, spent_currency: 'USD', spend_withheld: true }),
    ])
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })

    expect(await screen.findByText(/unconvertible currency/i)).toBeInTheDocument()
    // «0 SAR spent» is exactly the lie this branch replaces.
    expect(screen.queryByText(/SAR spent/)).not.toBeInTheDocument()
  })
})
