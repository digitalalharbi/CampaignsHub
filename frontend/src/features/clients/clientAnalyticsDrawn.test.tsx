import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { TabAnalytics } from './TabAnalytics'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), getClientAnalytics: vi.fn() }))

import { getClientAnalytics } from './api'

/**
 * VIZ-CLIENT-001 — a client's analytics tab served a full daily series and drew none of it.
 *
 * The payload has carried `timeseries` — date, spend, clicks, impressions, conversions, revenue —
 * since this tab existed, and the tab rendered eight cards of period totals. «Spend 108K SAR» is
 * the answer to «how much»; it cannot answer «when», which is the question somebody opens a client's
 * analytics to ask, and the data for it was already on the wire.
 *
 * ## The currency contract, as the SERVICE defines it rather than as the field name reads
 *
 * `money_blended` reads like «these figures mix currencies». `ClientAnalyticsService` sets it TRUE on
 * the branch that established a single currency and aggregated the money safely, and FALSE on the
 * mixed branch where it refuses to aggregate — and that mixed branch also sends `timeseries: []`.
 *
 * Reading the name instead of the service produced a page that withheld a money line while printing
 * «96.1K SAR» in the card beside it. These cases pin the real contract: a mixed client has NO series
 * of any kind and must say the currency rule withheld it, never «no measured days»; a single-currency
 * client draws both charts.
 */
const day = (date: string, over: Record<string, number> = {}) => ({
  date, spend: 100, clicks: 20, impressions: 1000, conversions: 2, revenue: 300, ...over,
})

const analytics = (over: Record<string, unknown> = {}) => ({
  range: { from: '2026-09-01', to: '2026-09-30' },
  source_of_truth: 'platform',
  currency_mode: 'single', currency: 'SAR', money_blended: false,
  objective_mix: [{ objective: 'sales', count: 6 }, { objective: 'awareness', count: 4 }],
  roas_is_primary: true,
  totals: { spend: 300, revenue: 900, roas: 3, conversions: 6, cpa: 50, ctr: 0.02, cpc: 5, cpm: 100 },
  previous: null, delta: null,
  counts: { impressions: 3000, clicks: 60, conversions: 6 },
  platforms: [
    { provider: 'meta', spend: 200, spend_share: 0.66, impressions: 2000, clicks: 40, conversions: 4, revenue: 600, roas: 3, ctr: null, cpc: null, cpm: null, cpa: null },
    { provider: 'tiktok', spend: 100, spend_share: 0.34, impressions: 1000, clicks: 20, conversions: 2, revenue: 300, roas: 3, ctr: null, cpc: null, cpm: null, cpa: null },
  ],
  projects: [],
  timeseries: [day('2026-09-01'), day('2026-09-02'), day('2026-09-03')],
  best_campaign: null, worst_campaign: null,
  freshness: { state: 'fresh', last_sync_at: null, missing_days: 0, sync_failed: false },
  attribution: { windows: [] },
  ...over,
})

describe('the client analytics tab draws the series it already serves', () => {
  it('draws spend against revenue over the window', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics() as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('client-money-trend')).toBeInTheDocument()
  })

  it('draws the counts over the window on their own scale', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics() as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('client-count-trend')).toBeInTheDocument()
  })

  it('names the currency rule when a mixed client is sent no series, instead of calling it no data', async () => {
    // The mixed branch sends `timeseries: []` ON PURPOSE. Reporting that as «no measured days» would
    // present a refusal as an absence.
    vi.mocked(getClientAnalytics).mockResolvedValue(
      analytics({ money_blended: false, currency_mode: 'mixed', currency: null, timeseries: [] }) as never,
    )
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    const note = await screen.findByTestId('client-trend-currency-withheld')
    expect(note).toHaveTextContent(/more than one currency/i)
    expect(screen.queryByTestId('client-trend-empty')).toBeNull()
    expect(screen.queryByTestId('client-money-trend')).toBeNull()
  })

  it('draws the money trend on the branch that set money_blended true, because that branch is the single-currency one', async () => {
    // Guards the inversion directly: `money_blended: true` is the SAFE branch, not the unsafe one.
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ money_blended: true, currency_mode: 'single', currency: 'SAR' }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('client-money-trend')).toBeInTheDocument()
  })

  it('withholds only the money chart when a series arrives with no currency established', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ currency_mode: 'single', currency: null }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('client-count-trend')).toBeInTheDocument()
    expect(screen.queryByTestId('client-money-trend')).toBeNull()
    expect(screen.getByTestId('client-money-trend-withheld')).toBeInTheDocument()
  })

  it('draws no trend at all from a single day, because one point is not a line', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ timeseries: [day('2026-09-01')] }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    await screen.findByTestId('client-trend-too-short')
    expect(screen.queryByTestId('client-money-trend')).toBeNull()
    expect(screen.queryByTestId('client-count-trend')).toBeNull()
  })

  it('says nothing was measured rather than drawing an empty chart', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ timeseries: [] }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    expect(await screen.findByTestId('client-trend-empty')).toBeInTheDocument()
  })

  it('divides the window by objective from the counts, whatever the currency is doing', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ money_blended: false, currency_mode: 'mixed', currency: null, timeseries: [] }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    const mix = await screen.findByTestId('client-objective-mix-bar')
    expect(mix).toHaveAccessibleName(/Sales 6/i)
  })

  it('draws no objective bar when the server grouped nothing', async () => {
    vi.mocked(getClientAnalytics).mockResolvedValue(analytics({ objective_mix: [] }) as never)
    renderWithProviders(<TabAnalytics clientId="c1" />, { locale: 'en' })

    await screen.findByTestId('client-count-trend')
    expect(screen.queryByTestId('client-objective-mix-bar')).toBeNull()
  })
})
