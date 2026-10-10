import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({
  listProjects: vi.fn().mockResolvedValue([]),
  listClientWorkspaces: vi.fn().mockResolvedValue([]),
  listUsers: vi.fn().mockResolvedValue([]),
}))
import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-COVERAGE-COMPARABILITY-001 — the overview compares two windows only when both are whole.
 *
 * The summary said `coverage: partial — meta reported through 2026-09-27` on the current window and
 * the overview printed «+18 %» on every card against a complete previous window, with the banner
 * reserved for an EMPTY previous period. Now the banner names the truncation and the pills go.
 */
const TOTALS = {
  impressions: 2_569_040, clicks: 48_181, conversions: 1_401, spend: 45_898.35, revenue: 504_860,
  roas: 11, cpa: 32.76, ctr: 0.01875, cpc: 0.953, cpm: 17.87, purchases: 1_401,
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}
const COMPLETE = { state: 'complete', expected_contributors: ['meta'], included_contributors: ['meta'], excluded_contributors: [], partial_contributors: [], reported_through: {}, reasons: {} }
const TRUNCATED = {
  state: 'partial', expected_contributors: ['meta'], included_contributors: [], excluded_contributors: ['meta'],
  partial_contributors: ['meta'], reported_through: { meta: '2026-09-27' },
  reasons: { meta: 'Reported through 2026-09-27; this window ends 2026-10-10.' },
}

function route(current: Record<string, unknown>, previous: Record<string, unknown>) {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) {
      return Promise.resolve({
        current, previous,
        delta: { spend: 0.1781, revenue: 0.2132, conversions: 0.2046, roas: 0.0299, cpa: -0.0221, ctr: 0.0635, impressions: 0.11, clicks: 0.18 },
        reported: { spend: true, revenue: true, conversions: true, impressions: true, clicks: true },
        commerce: null, currency: 'SAR', rows_in_scope: true, previous_rows_in_scope: true,
        previous_range: { from: '2026-08-12', to: '2026-09-10' },
        objective_families_in_scope: ['sales'],
        conversions_basis: {
          source: 'platform_reported' as const, label_ar: '', label_en: 'Platform-Reported',
          providers: ['meta'], may_double_count: false, is_unique_order_count: false as const, note_ar: '', note_en: '',
        },
      })
    }
    if (path.includes('/metrics/timeseries')) return Promise.resolve([])
    if (path.includes('disclaimer')) return Promise.resolve(null)
    return Promise.resolve([])
  })
}

describe('the overview and the windows it compares', () => {
  beforeEach(() => { signInWith(['campaigns.view']); useProject.getState().setCurrentProjectId('p1') })
  afterEach(() => { signOut(); useProject.getState().setCurrentProjectId(null); vi.clearAllMocks() })

  it('compares, and shows change pills, when both windows are complete', async () => {
    route({ ...TOTALS, coverage: COMPLETE }, { ...TOTALS, coverage: COMPLETE })
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })
    expect((await screen.findAllByLabelText(/Change/i)).length).toBeGreaterThan(0)
    expect(screen.queryByTestId('no-comparison-period')).not.toBeInTheDocument()
  })

  it('withholds every change pill and names the truncation when the current window stopped short', async () => {
    route({ ...TOTALS, coverage: TRUNCATED }, { ...TOTALS, coverage: COMPLETE })
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })
    const banner = await screen.findByTestId('no-comparison-period')
    expect(banner.textContent).toContain('covered through 2026-09-27 only')
    expect(banner.textContent).toContain('Meta did not report after it')
    expect(screen.queryByLabelText(/Change/i)).not.toBeInTheDocument()
  })
})
