import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
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
 * BUDGET-HIERARCHY-001 — one drill from the project down to a campaign, and the platform's own
 * limit beside the plan on the campaign rung.
 *
 * The three rungs were three tables that did not know about each other. A pick on a platform row
 * narrows the accounts to that platform and the campaigns to the ones placed on it; a pick on an
 * account narrows the campaigns to that account; the crumbs name the path and lead back up. No
 * figure changes — the rows are the same rows, fewer of them.
 */
const PLATFORMS = [
  { provider: 'meta', budget: 10_000, budget_currency: 'SAR', spent: 7_500, spent_currency: 'SAR', spend_withheld: false, remaining: 2_500, consumed_pct: 0.75, pace: 1.4, projected_spend: 14_000, expected_to_date: 5_000, daily_average: 500, over_under: 4_000, pacing_basis: 'comparable', refusal: null },
  { provider: 'tiktok', budget: 2_000, budget_currency: 'SAR', spent: 900, spent_currency: 'SAR', spend_withheld: false, remaining: 1_100, consumed_pct: 0.45, pace: 0.9, projected_spend: 1_800, expected_to_date: 1_000, daily_average: 90, over_under: -200, pacing_basis: 'comparable', refusal: null },
]

const ACCOUNTS = [
  { account_id: 'a1', account_name: 'Main Meta', provider: 'meta', spent: 7_500, spent_currency: 'SAR', spend_withheld: false, cap: 10_000, remaining: 2_500, consumed_pct: 0.75, pace: 1.4, projected_spend: 14_000, expected_to_date: 5_000, daily_average: 500, over_under: 4_000, campaigns: 2, capped_campaigns: 2 },
  { account_id: 'a2', account_name: 'TikTok shop', provider: 'tiktok', spent: 900, spent_currency: 'SAR', spend_withheld: false, cap: null, remaining: null, consumed_pct: null, pace: null, projected_spend: 1_800, expected_to_date: null, daily_average: 90, over_under: null, campaigns: 1, capped_campaigns: 0 },
]

const CAMPAIGNS = [
  { campaign_id: 'c1', campaign_name: 'Spring launch', status: 'active', budget: 10_000, budget_currency: 'SAR', spent: 7_500, spent_currency: 'SAR', spend_withheld: false, remaining: 2_500, consumed_pct: 0.75, pace: 1.4, projected_spend: 14_000, expected_to_date: 5_000, daily_average: 500, over_under: 4_000, pacing_basis: 'comparable', provider_limit: 12_000, provider_limit_currency: 'SAR', provider_limit_basis: 'stated', providers: ['meta'], account_ids: ['a1'] },
  { campaign_id: 'c2', campaign_name: 'Ramadan clips', status: 'active', budget: 2_000, budget_currency: 'SAR', spent: 900, spent_currency: 'SAR', spend_withheld: false, remaining: 1_100, consumed_pct: 0.45, pace: 0.9, projected_spend: 1_800, expected_to_date: 1_000, daily_average: 90, over_under: -200, pacing_basis: 'comparable', provider_limit: null, provider_limit_currency: null, provider_limit_basis: 'not_sent', providers: ['tiktok'], account_ids: ['a2'] },
  { campaign_id: 'c3', campaign_name: 'Unlinked plan', status: 'draft', budget: 500, budget_currency: 'SAR', spent: 0, spent_currency: 'SAR', spend_withheld: false, remaining: 500, consumed_pct: 0, pace: 0, projected_spend: 0, expected_to_date: 250, daily_average: 0, over_under: -500, pacing_basis: 'comparable', provider_limit: null, provider_limit_currency: null, provider_limit_basis: 'not_sent', providers: [], account_ids: [] },
]

function route() {
  const body = (url: string) => {
    if (url.includes('budget-explanation')) return null
    if (url.includes('budget-accounts')) return ACCOUNTS
    if (url.includes('budget-platforms')) return PLATFORMS
    if (url.includes('metrics/budget')) return CAMPAIGNS
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('disclaimer')) return null
    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation((url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never)
}

describe('the budget hierarchy drills from the project to a campaign', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'budget.view'])
  })
  afterEach(() => signOut())

  it('the campaign rung states the platform limit beside the plan, and «not sent» where none was', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))

    const row = (await screen.findByText('Spring launch')).closest('tr') as HTMLElement
    expect(within(row.closest('table') as HTMLElement).getByText('Platform limit')).toBeInTheDocument()
    expect(screen.getByTestId('provider-limit-c1')).toHaveTextContent('12K SAR')
    expect(screen.getByTestId('provider-limit-c2')).toHaveTextContent('Not sent')
  })

  it('a platform pick narrows the accounts and campaigns, an account pick narrows further, and the crumbs lead back', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Budget/i }))
    await screen.findByText('Spring launch')
    await screen.findByText('Ramadan clips')
    // The three rungs load independently; wait for the account rung too before narrowing.
    await screen.findByText('TikTok shop')
    expect(screen.queryByTestId('budget-crumbs')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('budget-drill-platform-meta'))
    await waitFor(() => expect(screen.queryByText('Ramadan clips')).not.toBeInTheDocument())
    expect(screen.queryByText('TikTok shop')).not.toBeInTheDocument()
    expect(screen.getByText('Spring launch')).toBeInTheDocument()
    expect(screen.getByTestId('budget-crumbs')).toHaveTextContent('Project')
    // The plan linked to no platform campaign is outside the path, and the page says so rather than losing it.
    expect(screen.queryByText('Unlinked plan')).not.toBeInTheDocument()
    expect(screen.getByTestId('budget-unplaced-note')).toHaveTextContent('1 campaign(s)')

    fireEvent.click(screen.getByTestId('budget-drill-account-a1'))
    await waitFor(() => expect(screen.getByTestId('budget-crumb-account')).toHaveTextContent('Main Meta'))
    expect(screen.getByText('Spring launch')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('budget-crumb-project'))
    await waitFor(() => expect(screen.getByText('Ramadan clips')).toBeInTheDocument())
    expect(screen.getByText('TikTok shop')).toBeInTheDocument()
    expect(screen.getByText('Unlinked plan')).toBeInTheDocument()
    expect(screen.queryByTestId('budget-crumbs')).not.toBeInTheDocument()
  })
})
