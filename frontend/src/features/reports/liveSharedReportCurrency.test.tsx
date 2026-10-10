import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LiveSharedReport } from './LiveSharedReport'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchLiveShared: vi.fn(),
}))
import { fetchLiveShared } from './api'

/**
 * REPORT-CURRENCY-TRUTH-001 — the live page formats money in the unit the PAYLOAD states.
 *
 * The `currency` prop is the report row's stamp, written by the controllers as the reporting
 * default. On the owner's live link the KPI read «45.9K USD» while the budget block beneath it,
 * reading the rows, said 45.9K SAR — one page, one figure, two units.
 */
const clean = {
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}
const payload = (currency: string | null) => ({
  period: { from: '2026-09-11', to: '2026-10-10', days: 30 },
  currency,
  totals: { spend: 45_898.35, revenue: 504_860, roas: 11, cpa: 32.76, impressions: 2_569_040, clicks: 48_181, conversions: 1_401, ...clean },
  deltas: {},
  timeseries: [],
  platforms: [{ provider: 'meta', spend: 45_898.35, conversions: 1_401, ...clean }],
  campaigns: [],
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['meta'], campaigns: [], earliest: '2026-09-11', latest: '2026-10-10' },
  metrics: [],
  applied: { from: '2026-09-11', to: '2026-10-10', providers: [], campaigns: [] },
  is_demo: false,
})

describe('the unit a live shared report prints its money in', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('is the payload\'s, not the report row\'s stamp', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: payload('SAR') } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')
    const kpis = within(screen.getByTestId('live-kpis'))
    expect(kpis.getByText(/45\.9K SAR/)).toBeInTheDocument()
    expect(kpis.queryByText(/USD/)).not.toBeInTheDocument()
  })

  it('prints the figure bare when the scope holds two units and the payload states none', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: payload(null) } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')
    const kpis = within(screen.getByTestId('live-kpis'))
    expect(kpis.queryByText(/USD|SAR/)).not.toBeInTheDocument()
    expect(kpis.getByText(/45\.9K/)).toBeInTheDocument()
  })
})
