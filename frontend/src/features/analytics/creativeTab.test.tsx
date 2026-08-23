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
 * ANALYTICS-CREATIVE-VISIBLE-001 — the last rung of the drill-down.
 *
 * Figures come from `creative_daily_metrics` only. A creative the platform does not break out shows
 * «—», because inventing its share of a campaign total would be a number nobody measured.
 */
const CREATIVE = {
  id: 'cr1',
  name: 'Summer hero',
  campaign_name: 'Always-On',
  objective: 'sales',
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: '2026-08-22T00:00:00Z' },
  metrics: {
    spend: null,
    spend_original: 412.5,
    spend_withheld_rows: 3,
    money_original_currency: 'USD',
    money_original_currencies: 1,
    impressions: 90000,
    clicks: 300,
    ctr: 0.00333,
  },
}

function route(creatives: unknown[]) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/creatives')) return { creatives, currency: 'SAR', page: 1, per_page: 24, total: creatives.length, period: { from: '', to: '' }, filters: {} } as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 1, demo_rows: 0 } } as never
    if (url.includes('disclaimer')) return null as never
    return [] as never
  })
}

async function openCreative() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByText('Creative analysis'))
}

describe('the creative analysis tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows a creative with its campaign, objective and figures', async () => {
    route([CREATIVE])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('Summer hero')
    expect(table).toHaveTextContent('Always-On')
    expect(table).toHaveTextContent('90,000')
  })

  /** Withheld spend keeps its own currency here too — one money contract across every surface. */
  it('states withheld creative spend in its original currency', async () => {
    route([CREATIVE])

    await openCreative()

    expect(await screen.findByTestId('creative-analysis-table')).toHaveTextContent('412.50 USD')
  })

  /** A creative the platform does not break out shows «—», never a share of the campaign. */
  it('prints a dash rather than inventing a creative-level figure', async () => {
    route([{ ...CREATIVE, metrics: { ...CREATIVE.metrics, impressions: null, clicks: null, ctr: null } }])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('—')
    expect(table).not.toHaveTextContent('0.00%')
  })
})
