import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
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

const requested: string[] = []

function route(creatives: unknown[]) {
  requested.length = 0
  vi.mocked(getData).mockImplementation(async (url: string) => {
    requested.push(url)
    if (url.includes('/creatives')) return { creatives, currency: 'SAR', page: 1, per_page: 24, total: creatives.length, period: { from: '', to: '' }, filters: {} } as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 1, demo_rows: 0 } } as never
    if (url.includes('disclaimer')) return null as never
    return [] as never
  })
}

async function openCreative() {
  renderWithProviders(<AnalyticsPage />, { locale: 'en' })
  fireEvent.click(await screen.findByRole('tab', { name: 'Ad' }))
}

/**
 * ANALYTICS-CREATIVE-SCOPE-001 — the tab took only `projectId` and `range`.
 *
 * So selecting TikTok left it listing META creatives with Meta's figures, under a filter bar that
 * said TikTok. The filter was not weak here, it was decorative — and a table that contradicts the
 * control above it is worse than an empty one, because the reader cannot tell which is lying.
 *
 * Asserted on the REQUEST rather than on the rows: what matters is that the choice reaches the
 * server, and a fixture that returns the same rows either way would pass a row assertion.
 */
describe('the ad analysis tab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('shows a ad with its campaign, objective and figures', async () => {
    route([CREATIVE])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('Summer hero')
    expect(table).toHaveTextContent('Always-On')
    expect(table).toHaveTextContent('90,000')
  })

  /** Withheld spend keeps its own currency here too — one money contract across every surface. */
  it('states withheld ad spend in its original currency', async () => {
    route([CREATIVE])

    await openCreative()

    expect(await screen.findByTestId('creative-analysis-table')).toHaveTextContent('412.50 USD')
  })

  /** A creative the platform does not break out shows «—», never a share of the campaign. */
  it('prints a dash rather than inventing a ad-level figure', async () => {
    route([{ ...CREATIVE, metrics: { ...CREATIVE.metrics, impressions: null, clicks: null, ctr: null } }])

    await openCreative()

    const table = await screen.findByTestId('creative-analysis-table')

    expect(table).toHaveTextContent('—')
    expect(table).not.toHaveTextContent('0.00%')
  })
})

describe('the ad tab and the filter bar', () => {
  it('sends the chosen platform to the server instead of ignoring it', async () => {
    route([])
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: 'Ad' }))

    await waitFor(() => expect(requested.some((u) => u.includes('/creatives'))).toBe(true))

    fireEvent.click(screen.getByTestId('analytics-platform-tiktok'))

    // The library speaks `providers`; the metrics API speaks `provider`. The translation is the
    // point of the fix, so the assertion names the library's spelling.
    await waitFor(() => {
      const creativeCalls = requested.filter((u) => u.includes('/creatives'))
      expect(creativeCalls.join(' | ')).toContain('providers')
    })
  })
})
