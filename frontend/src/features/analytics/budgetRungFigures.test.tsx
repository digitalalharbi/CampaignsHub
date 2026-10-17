import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * BUDGET-HIERARCHY-001 — the campaign and account rungs read the same plan figures as the platform
 * rung: expected to date, daily average, over / under. The API already served them; the two tables
 * did not show them, so a reader moving down the hierarchy lost three columns at each step.
 */
const ACCOUNTS = [
  {
    account_id: 'a1', account_name: 'Main Meta', provider: 'meta', spent: 7_500, spent_currency: 'SAR',
    spend_withheld: false, cap: 10_000, remaining: 2_500, consumed_pct: 0.75, pace: 1.4, projected_spend: 14_000,
    expected_to_date: 5_000, daily_average: 500, over_under: 4_000, campaigns: 2, capped_campaigns: 2,
  },
  {
    account_id: 'a2', account_name: 'No cap', provider: 'tiktok', spent: 900, spent_currency: 'SAR',
    spend_withheld: false, cap: null, remaining: null, consumed_pct: null, pace: null, projected_spend: 1_800,
    expected_to_date: null, daily_average: 90, over_under: null, campaigns: 1, capped_campaigns: 0,
  },
]

const CAMPAIGNS = [
  {
    campaign_id: 'c1', campaign_name: 'Spring launch', status: 'active', budget: 10_000, budget_currency: 'SAR',
    spent: 7_500, spent_currency: 'SAR', spend_withheld: false, remaining: 2_500, consumed_pct: 0.75, pace: 1.4,
    projected_spend: 14_000, expected_to_date: 5_000, daily_average: 500, over_under: 4_000, pacing_basis: 'comparable',
  },
]

function route() {
  const body = (url: string) => {
    if (url.includes('budget-explanation')) return null
    if (url.includes('budget-accounts')) return ACCOUNTS
    if (url.includes('budget-platforms')) return []
    if (url.includes('metrics/budget')) return CAMPAIGNS
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('disclaimer')) return null
    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation((url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never)
}

describe('the lower budget rungs show the plan figures', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'budget.view'])
  })
  afterEach(() => signOut())

  it('the account rung carries expected, daily average and over / under, blank where no cap is stated', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    const capped = (await screen.findByText('Main Meta')).closest('tr') as HTMLElement
    const table = capped.closest('table') as HTMLElement
    for (const h of ['Expected to date', 'Daily average', 'Over / under']) expect(within(table).getByText(h)).toBeInTheDocument()

    expect(capped.textContent).toContain('+4K SAR')
    const uncapped = within(table).getByText('No cap').closest('tr') as HTMLElement
    // No ceiling: expected and over / under refuse, the daily average is still a fact.
    expect(within(uncapped).getAllByText('—').length).toBeGreaterThanOrEqual(2)
  })

  it('the campaign rung carries the same three', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    const row = (await screen.findByText('Spring launch')).closest('tr') as HTMLElement
    expect(row.textContent).toContain('5K SAR500 SAR')
    expect(row.textContent).toContain('+4K SAR')
    const table = row.closest('table') as HTMLElement
    for (const h of ['Expected to date', 'Daily average', 'Over / under']) expect(within(table).getByText(h)).toBeInTheDocument()
  })
})
