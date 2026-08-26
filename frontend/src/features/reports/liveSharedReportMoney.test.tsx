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
 * convert. That scope is `partial`: 1,000 SAR omits the 500 and 500 USD omits the 1,000, so the
 * honest answer for the TOTAL is «—» — neither half may be printed as the spend.
 *
 * The breakdown CHARTS below follow the other half of the rule: they drop the rows they cannot size
 * and print how many, because refusing every platform because one is unreadable tells the client
 * less than the platforms that are known plus an honest count.
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

  it('refuses a single spend figure for a partial scope, and prints neither half', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    // Not the converted subset in the report currency, and not the withheld half on its own.
    expect(screen.queryByText(/SAR\s*1,?000/)).not.toBeInTheDocument()
    expect(screen.queryByText('1,000')).not.toBeInTheDocument()
    expect(screen.queryByText('500.00 USD')).not.toBeInTheDocument()
  })

  it('keeps the platforms it can size, and says how many it left out', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...PAYLOAD,
          platforms: [
            // Converted, in the report currency — drawable.
            { provider: 'snapchat', spend: 900, spend_original: 0, spend_withheld_rows: 0 },
            // A platform figure in USD: not comparable on a SAR axis, so left off and counted.
            { provider: 'meta', spend: null, ...withheldMoney },
          ],
        },
      },
    } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" />, { locale: 'en' })

    expect(await screen.findByText(/1 platform\(s\) not included/)).toBeInTheDocument()
    // The refusal of the WHOLE chart is what this replaces — snapchat's 900 is perfectly knowable.
    expect(screen.queryByText(/Spend share unavailable/)).not.toBeInTheDocument()
  })
})
