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
 * NUMBER-PRESENTATION-001 on the KPI block — the money card was the one that revealed nothing.
 *
 * Measured on production after the detail table was fixed for the same defect: «الظهور 7.08M»
 * revealed 7,077,158 and «النقرات 42.7K» revealed 42,738, while «الإنفاق 10.8K USD» revealed
 * nothing at all. The counts went through `count()`, which carries an exact figure; the money went
 * through `plain()`, which throws away everything the reading knew.
 *
 * It is the same class as the table, one surface up, and it matters more here: these cards are the
 * headline, and spend is the figure the client came to read.
 */
/* The shape `liveSharedReportMoney.test.tsx` renders with — anything less crashes the block. */
const base = {
  period: { from: '2026-08-01', to: '2026-08-30', days: 30 },
  currency: 'USD',
  deltas: {},
  timeseries: [],
  campaigns: [],
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  metrics: [],
  applied: { from: '2026-08-01', to: '2026-08-30', providers: [], campaigns: [] },
  is_demo: false,
}

const clean = {
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}

const stated = {
  ...base,
  totals: {
    spend: 10_696.54, revenue: 0, roas: 0, cpa: 17.6,
    impressions: 7_027_238, clicks: 42_424, conversions: 607, ...clean,
  },
  platforms: [{ provider: 'snapchat', spend: 10_696.54, conversions: 607, ...clean }],
}

/** A partial scope: the contract prints «—» for the total and must reveal nothing behind it. */
const withheldMoney = {
  spend_original: 500, spend_withheld_rows: 3,
  revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: 'USD', money_original_currencies: 1,
}

const refused = {
  ...base,
  totals: {
    spend: 1000, revenue: 0, roas: 0, cpa: 0,
    impressions: 50_000, clicks: 800, conversions: 40, ...withheldMoney,
  },
  platforms: [{ provider: 'snapchat', spend: null, ...withheldMoney }],
}

describe('the spend card a client reads as an abbreviation', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('reveals the exact amount behind the compact one', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: stated } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const kpis = within(screen.getByTestId('live-kpis'))
    const compact = await kpis.findByText(/10\.7K/)
    const holder = compact.closest('[title]') ?? compact.parentElement?.querySelector('[title]')

    expect(holder, 'the spend card revealed nothing behind its abbreviation').not.toBeNull()
    expect(holder!.getAttribute('title')).toContain('10,696.54')
  })

  /** The half that must not be broken to fix the first: a refused total stays refused. */
  it('reveals nothing behind a total the contract refused to state', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: refused } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    for (const el of within(screen.getByTestId('live-kpis')).queryAllByTitle(/\d/)) {
      expect(el.getAttribute('title') ?? '').not.toMatch(/1,?000|500/)
    }
  })
})
