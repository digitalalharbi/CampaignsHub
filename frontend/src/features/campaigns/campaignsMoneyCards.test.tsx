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

/* The list endpoint answers with a page — rows plus whether the server had to stop. */
const listPage = (campaigns: UnifiedCampaign[]) => ({ campaigns, truncated: false, limit: 500 })

/**
 * CAMP-MONEY-001 — the Campaigns summary row read the aggregator's zero, in an assumed currency.
 *
 * Production's shape: Snapchat reported 4,128.93 USD and no USD→SAR rate exists, so FX-001 withheld
 * the conversion and the aggregator coalesced the sum to 0. `money(k?.cpa)` formatted that 0 with
 * the helper's SAR default and the card read «0 SAR» — beside a dashboard, on the same data, reading
 * the truth.
 */
const WITHHELD = {
  impressions: 2884062, clicks: 21802, conversions: 102,
  spend: 0, revenue: 0, roas: 0, cpa: 0,
  spend_original: 4128.93, spend_withheld_rows: 262,
  revenue_original: 12969.03, revenue_withheld_rows: 262,
  money_original_currency: 'USD', money_original_currencies: 1,
  ctr: 0.0076,
}

function campaign(id: string, name: string, status: string): UnifiedCampaign {
  return {
    id, project_id: 'p1', name, objective: 'sales', status, total_budget: 1000, budget_currency: 'SAR',
    starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null,
    attribution_window: null, owner_id: null, target_kpi: null, audience: null, regions: null,
    external_campaigns_count: 2, created_at: null,
  }
}

function route(totals: Record<string, unknown>, previousRows: boolean) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) {
      return Promise.resolve({
        current: totals, previous: totals, delta: { conversions: null, cpa: null, roas: null },
        reported: {}, commerce: null, currency: 'SAR', rows_in_scope: true,
        previous_rows_in_scope: previousRows,
        previous_range: { from: '2026-06-27', to: '2026-07-26' },
        objective_families_in_scope: ['sales'],
        conversions_basis: {
          source: 'platform_reported' as const, label_ar: '', label_en: '', providers: ['snapchat'],
          may_double_count: false, is_unique_order_count: false as const, note_ar: '', note_en: '',
        },
      })
    }
    return Promise.resolve([])
  })
}

describe('the Campaigns summary row', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue(listPage([campaign('c1', 'Always-On', 'active')]))
    useProject.getState().setCurrentProjectId('p1')
  })

  afterEach(() => {
    signOut()
    useProject.getState().setCurrentProjectId(null)
  })

  it('shows the withheld original rather than a zero in a currency nobody reports in', async () => {
    route(WITHHELD, true)
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })

    // 4,128.93 / 102 = 40.48, and it is USD — not the SAR the formatter used to assume.
    expect(await screen.findByText('40.48 USD')).toBeInTheDocument()
    expect(screen.queryByText('0 SAR')).not.toBeInTheDocument()

    // 12,969.03 / 4,128.93 ≈ 3.14. The aggregator's own roas was 0.
    expect(screen.getByText(/3\.1/)).toBeInTheDocument()
    expect(screen.queryByText(/^0(\.00)?x$/)).not.toBeInTheDocument()
  })

  /**
   * CAMP-COMPARE-001 — with no comparison window the pill is absent, not «— —».
   *
   * Asserted through the accessible label the pill carries, because the dash itself is styling and
   * would keep this test passing if the pill came back rendering something else that means nothing.
   */
  it('renders no change pill at all when there is no previous period', async () => {
    route(WITHHELD, false)
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })

    await screen.findByText('40.48 USD')

    expect(screen.queryByLabelText(/Change/i)).not.toBeInTheDocument()
  })

  /** CAMP-COPY-001 — a caption that follows its own count. */
  it('does not say paused campaigns need a look when none are paused', async () => {
    route(WITHHELD, true)
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/app/campaigns' })

    await screen.findByText('40.48 USD')

    expect(screen.getByText('None paused')).toBeInTheDocument()
    expect(screen.queryByText('Need a look')).not.toBeInTheDocument()
  })
})
