import { afterEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveSharedReport } from '../LiveSharedReport'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchLiveShared: vi.fn(),
  fetchLiveContent: vi.fn(),
}))

import { fetchLiveShared } from '../api'

/*
 * LIVE-LINK-OWNER-DEFECTS-001 — the Owner's three observations on a live link (2026-11-09):
 *   «السجل اليومي» had disappeared; Google ads showed without spend; ChatGPT was nowhere on the page.
 */
const days = Array.from({ length: 12 }, (_, i) => ({ date: `2026-11-${String(i + 1).padStart(2, '0')}`, impressions: 1000 + i, clicks: 10 + i, conversions: i % 3, spend: 100 + i }))

const googleRow = {
  provider: 'google', impressions: 10_150, clicks: 392, conversions: 2, spend: 57.19, spend_withheld_rows: 3, spend_original: 28.97,
  money_original_currency: 'EUR', money_original_currencies: 1, revenue: 45.36, ctr: 0.0386,
}
const snapRow = { provider: 'snapchat', impressions: 367_000, clicks: 5070, conversions: 0, spend: 630, ctr: 0.0138 }

const googleAd = {
  name: 'كرم النخيل للعجوة', provider: 'google', content_key: 'g-1', format: 'text_card',
  metrics: { spend: 16.45687, spend_withheld_rows: 1, spend_original: 15.817804, money_original_currency: 'EUR', money_original_currencies: 1, impressions: 289, clicks: 148, conversions: 2, ctr: 0.5121 },
}

function serve(extra: Record<string, unknown> = {}) {
  vi.mocked(fetchLiveShared).mockImplementation(async () => ({
    status: 200,
    envelope: { data: {
      // A year-long window: the trend is bucketed to ONE monthly point, the ledger still has every day.
      period: { from: '2026-11-01', to: '2027-11-09', days: 374, mode: 'rolling', kind: 'custom', grain: 'month' },
      currency: 'USD',
      totals: { spend: 1219.67, impressions: 678_333, clicks: 7274, conversions: 75, revenue: 5307.97, ctr: 0.0107 },
      deltas: {},
      timeseries: [{ date: '2026-11-01', spend: 1219.67, clicks: 7274, conversions: 75, impressions: 678_333 }],
      daily_rows: days,
      platforms: [snapRow, googleRow],
      campaigns: [],
      funnel: [],
      ads: [], ads_weakest: [], ads_groups: [], ads_roster: [googleAd],
      creatives_in_scope: 1,
      sections: {},
      store_funnel: null,
      freshness: [],
      available: { providers: ['google', 'snapchat', 'openai_ads'], campaigns: [], earliest: '2026-11-01', latest: '2027-11-09' },
      metrics: [],
      applied: { from: '2026-11-01', to: '2027-11-09', providers: [], campaigns: [] },
      detail_level: 'detailed',
      accounts: [],
      report_views: ['summary', 'platforms', 'content', 'tables'],
      report_sections: ['kpis', 'platform_comparison', 'content_performance', 'daily_records'],
      ...extra,
    } },
  }) as never)
}

afterEach(() => vi.clearAllMocks())

describe('«السجل اليومي» in the Tables view', () => {
  it('is a table of DAYS, eight to a page, even when the chart is bucketed to a month', async () => {
    serve()
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=tables' })

    const log = await screen.findByTestId('tables-days')
    expect(within(log).getAllByRole('row')).toHaveLength(1 + 8)
    expect(within(log).getByText('2026-11-12')).toBeInTheDocument()
    // The first row is the NEWEST day, never the month bucket.
    expect(within(log).queryByText('2026-11-01')).not.toBeInTheDocument()
  })

  it('falls back to the trend when it is already daily', async () => {
    serve({ daily_rows: undefined, period: { from: '2026-11-04', to: '2026-11-09', days: 6, mode: 'rolling', kind: 'custom', grain: 'day' }, timeseries: days.slice(0, 6) })
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=tables' })

    const log = await screen.findByTestId('tables-days')
    expect(within(log).getAllByRole('row')).toHaveLength(1 + 6)
  })

  it('is off only when its own switch is off', async () => {
    serve({ report_sections: ['kpis', 'platform_comparison', 'content_performance'] })
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=tables' })

    await screen.findByTestId('live-mode-tables')
    expect(screen.queryByTestId('tables-days')).not.toBeInTheDocument()
  })
})

describe('an ad with one converted day and one day awaiting a rate', () => {
  it('prints both amounts and a chip on the content card, never a dash', async () => {
    serve()
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=content&platform=google' })

    const chips = await screen.findAllByTestId('pending-conversion-chip')
    expect(chips[0]).toHaveTextContent('Awaiting EUR conversion')
    expect(document.body.textContent ?? '').toContain('15.82 €')
  })

  it('prints both amounts in the platform tile', async () => {
    serve()
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=platforms' })

    const tile = await screen.findByTestId('live-platform-card-google')
    expect(tile.textContent).toContain('28.97 €')
    expect(tile.textContent).toContain('Awaiting EUR conversion')
  })
})

describe('a platform the link carries that has sent nothing yet', () => {
  it('is a card that says «لا صرف بعد», not an absence', async () => {
    serve()
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=platforms' })

    const idle = await screen.findByTestId('live-platform-idle-openai_ads')
    expect(idle).toHaveTextContent('No spend yet')
    expect(screen.queryByTestId('live-platform-card-openai_ads')).not.toBeInTheDocument()
  })

  it('becomes an ordinary platform card the moment it has figures — the same link, nothing else to do', async () => {
    serve({ platforms: [snapRow, googleRow, { provider: 'openai_ads', impressions: 900, clicks: 40, conversions: 1, spend: 12, ctr: 0.04 }] })
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="detailed" />, { route: '/r/tok?view=platforms' })

    expect(await screen.findByTestId('live-platform-card-openai_ads')).toBeInTheDocument()
    expect(screen.queryByTestId('live-platform-idle-openai_ads')).not.toBeInTheDocument()
  })
})
