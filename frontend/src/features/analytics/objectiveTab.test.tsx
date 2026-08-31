import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-OBJECTIVE-VISIBLE-001 — the objective split has to be SEEN, not just filtered.
 *
 * The objective work until now was a backend filter and a KPI list. Nothing on screen let an
 * operator see which campaigns were bought for what, or judged each family by its own verdict.
 */
const campaign = (over: Record<string, unknown> = {}) => ({
  campaign_id: 'c1',
  campaign_name: 'Brand film',
  provider: 'snapchat',
  objective: 'awareness',
  objective_family: 'awareness',
  objective_source: 'platform',
  spend: 1000,
  impressions: 90000,
  reach: 45000,
  clicks: 300,
  ...over,
})

function route(campaigns: unknown[]) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/campaigns')) return campaigns as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 5, demo_rows: 0 } } as never
    if (url.includes('disclaimer')) return null as never
    return [] as never
  })
}

async function openObjective() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: 'Objectives' }))
}

describe('the objective analysis tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('groups campaigns into the family the backend computed', async () => {
    route([
      campaign(),
      campaign({ campaign_id: 'c2', campaign_name: 'Ramadan sale', objective: 'sales', objective_family: 'sales', conversions: 40, revenue: 9000, roas: 9 }),
    ])

    await openObjective()

    expect(await screen.findByTestId('objective-family-awareness')).toHaveTextContent('Brand film')
    expect(screen.getByTestId('objective-family-sales')).toHaveTextContent('Ramadan sale')
  })

  /** The whole point: an awareness family is not headlined with ROAS. */
  it('judges each family by its own verdict metrics', async () => {
    route([
      campaign(),
      campaign({ campaign_id: 'c2', campaign_name: 'Ramadan sale', objective: 'sales', objective_family: 'sales', conversions: 40, revenue: 9000 }),
    ])

    await openObjective()

    const awareness = within(await screen.findByTestId('objective-family-awareness'))
    const sales = within(screen.getByTestId('objective-family-sales'))

    /*
      Asserted on the LABELS, not the metric keys.

      This test used to look for `reach`, `frequency` and `roas` — the column names — which is what
      the tab rendered, so it passed while the page showed a reader «conversions 176 revenue 56,320
      roas 15.36» in an Arabic layout. A test that reads the identifier cannot tell a labelled page
      from an unlabelled one; these are the catalogue's own labels, so it now can.

      What the test is FOR is unchanged: an awareness family is not headlined with a return.
    */
    expect(awareness.getByText('Reach')).toBeInTheDocument()
    expect(awareness.getByText('Frequency')).toBeInTheDocument()
    expect(awareness.queryByText('Return on ad spend')).not.toBeInTheDocument()
    expect(awareness.queryByText('roas')).not.toBeInTheDocument()

    expect(sales.getByText('Return on ad spend')).toBeInTheDocument()
    expect(sales.getByText('Order value')).toBeInTheDocument()
  })

  /** A family with no campaigns is absent, not an empty row implying zero performance. */
  it('shows only the families that have campaigns', async () => {
    route([campaign()])

    await openObjective()

    await screen.findByTestId('objective-family-awareness')
    expect(screen.queryByTestId('objective-family-app')).not.toBeInTheDocument()
  })

  /** Withheld money keeps its own currency here too. */
  it('states withheld spend in its original currency', async () => {
    route([
      campaign({
        spend: null,
        spend_withheld_rows: 4,
        spend_original: 412.5,
        money_original_currency: 'USD',
        money_original_currencies: 1,
      }),
    ])

    await openObjective()

    expect(await screen.findByTestId('objective-family-awareness')).toHaveTextContent('412.50 USD')
  })

  /** A campaign the platform never classified lands in Unclassified, never in Sales. */
  it('puts an unclassified campaign somewhere honest', async () => {
    route([campaign({ objective: null, objective_family: null, campaign_name: 'Unknown intent' })])

    await openObjective()

    expect(await screen.findByTestId('objective-family-unknown')).toHaveTextContent('Unknown intent')
  })
})
