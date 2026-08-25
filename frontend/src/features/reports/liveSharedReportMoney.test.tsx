import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { LiveSharedReport } from './LiveSharedReport'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchLiveShared: vi.fn(),
}))

import { fetchLiveShared } from './api'

/**
 * PARTIAL-WITHHELD-001 — the client's own live link is the worst place to misstate money.
 *
 * The reader has no second view of their account to check against, so «money(t.spend)» reading the
 * aggregator's coalesced 0 — printed in the report currency — is a number they cannot catch. The
 * payload below carries 1,000 converted in SAR plus 500 USD the platform reported and no rate could
 * convert; the honest spend is 500 USD, not 1,000 SAR and not 0.
 */
const withheldMoney = {
  spend_original: 500, spend_withheld_rows: 3,
  revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: 'USD', money_original_currencies: 1,
}

const PAYLOAD = {
  period: { from: '2026-08-01', to: '2026-08-26', days: 26 },
  currency: 'SAR',
  totals: {
    spend: 1000, revenue: 0, roas: 0, cpa: 0, impressions: 50_000, clicks: 800, conversions: 40,
    ...withheldMoney,
  },
  deltas: {},
  timeseries: [],
  platforms: [{ provider: 'snapchat', spend: null, ...withheldMoney }],
  campaigns: [{ campaign_name: 'Always-On', provider: 'snapchat', spend: null, ...withheldMoney }],
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-26' },
  metrics: [],
  applied: { from: '2026-08-01', to: '2026-08-26', providers: [], campaigns: [] },
  is_demo: false,
}

describe('a live shared report over a partially-withheld spend', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: PAYLOAD } } as never)
  })
  afterEach(() => vi.clearAllMocks())

  it('states the withheld spend in its own currency, never the converted subset in the report currency', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" />, { locale: 'en' })

    // The spend KPI states 500 USD — exact and in its own currency, checkable against the platform.
    expect(await screen.findByText('500.00 USD')).toBeInTheDocument()

    // The lie this replaces: the coalesced/converted subset (1,000) printed in the report currency.
    expect(screen.queryByText(/SAR\s*1,?000/)).not.toBeInTheDocument()
    expect(screen.queryByText('1,000')).not.toBeInTheDocument()
  })
})
