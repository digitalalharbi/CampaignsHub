import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
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
 * PLATFORM-DECISION-ANALYTICS-001 — account contribution on the Platforms tab.
 */
const clean = { spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 }
const ACCOUNTS = [
  { account_id: 'r', provider: 'meta', account_name: 'Acme Retail', spend: 3_000, revenue: 9_000, conversions: 30, impressions: 1, clicks: 1, ...clean },
  { account_id: 'b', provider: 'meta', account_name: 'Acme Brand', spend: 7_000, revenue: 21_000, conversions: 70, impressions: 1, clicks: 1, ...clean },
  { account_id: 's', provider: 'snapchat', account_name: 'Acme Snap', spend: 5_000, revenue: 0, conversions: 0, impressions: 1, clicks: 1, ...clean },
]

describe('the Platforms tab says who carries each platform’s spend', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path.includes('/metrics/accounts')) return Promise.resolve(ACCOUNTS)
      if (path.includes('/metrics/summary')) return Promise.resolve({ current: {}, previous: {}, delta: {}, reported: {}, commerce: null, currency: 'SAR', rows_in_scope: true, previous_rows_in_scope: true, previous_range: { from: '2026-08-12', to: '2026-09-10' }, objective_families_in_scope: ['sales'] })
      if (path.includes('/metrics/platforms')) return Promise.resolve([{ provider: 'meta', spend: 10_000, ...clean }, { provider: 'snapchat', spend: 5_000, ...clean }])
      if (path.includes('disclaimer')) return Promise.resolve(null)
      return Promise.resolve([])
    })
  })
  afterEach(() => { signOut(); useProject.getState().setCurrentProjectId(null); vi.clearAllMocks() })

  it('splits a platform between its accounts, largest first, and says when one account carries it all', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics?tab=platforms' })
    const meta = await screen.findByTestId('account-contribution-meta')
    const names = within(meta).getAllByText(/Acme (Brand|Retail)/).map((n) => n.textContent)
    expect(names).toEqual(['Acme Brand', 'Acme Retail'])
    expect(meta.textContent).toContain('70%')
    expect(meta.textContent).toContain('30%')
    expect(meta.textContent).toContain('2 accounts')
    const snap = screen.getByTestId('account-contribution-snapchat')
    expect(snap.textContent).toContain('One account carries all the spend')
    expect(snap.textContent).toContain('100%')
  })
})
