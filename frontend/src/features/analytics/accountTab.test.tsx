import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-DRILLDOWN-001 — the rung an operator actually manages.
 *
 * The chain read Platform → Campaign. A customer can hold several ad accounts on one platform, and
 * «Snapchat spent X» is not an answer when two accounts run different markets from different budgets.
 */
const ACCOUNT = {
  account_id: 'a1',
  account_name: 'رزه افينيو — Snapchat',
  provider: 'snapchat',
  spend: null,
  spend_original: 412.5,
  spend_withheld_rows: 5,
  money_original_currency: 'USD',
  money_original_currencies: 1,
  impressions: 90000,
  clicks: 300,
  ctr: 0.00333,
}

function route(accounts: unknown[]) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/accounts')) return accounts as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 5, demo_rows: 0 } } as never
    if (url.includes('disclaimer')) return null as never
    return [] as never
  })
}

async function openAccounts() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: 'Accounts' }))
}

describe('the account analysis tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows each ad account with its own figures', async () => {
    route([ACCOUNT])

    await openAccounts()

    const table = await screen.findByTestId('account-table')

    expect(table).toHaveTextContent('رزه افينيو — Snapchat')
    expect(table).toHaveTextContent('90,000')
  })

  /** One money contract at every rung. */
  it('states withheld account spend in its original currency', async () => {
    route([ACCOUNT])

    await openAccounts()

    expect(await screen.findByTestId('account-table')).toHaveTextContent('412.50 USD')
  })

  /**
   * An account removed since ingestion keeps its spend and shows no name.
   *
   * «Unknown account» would hide that it is gone, and the money is still real.
   */
  it('does not invent a name for an account that no longer exists', async () => {
    route([{ ...ACCOUNT, account_name: null }])

    await openAccounts()

    expect(await screen.findByTestId('account-table')).toHaveTextContent('Account no longer available')
  })
})
