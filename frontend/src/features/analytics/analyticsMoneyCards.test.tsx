import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * MONEY-TRUTH-001 — asserted on the RENDERED Analytics cards, not on the helpers.
 *
 * `readRoas()` was implemented and unit-tested while the card still rendered
 * `ratio(cur?.roas ?? null)` — the contract existed and the product did not use it. A helper test
 * cannot catch that; only rendering the page can. This is that test.
 *
 * The figures are production's: 4,128.93 USD spend and 12,969.03 USD revenue withheld for want of a
 * USD→SAR rate, which the aggregator coalesces to 0.
 */
const WITHHELD_TOTALS = {
  impressions: 2884062, clicks: 21802, conversions: 102,
  // What the aggregator returns when the money cannot be converted.
  spend: 0, revenue: 0, roas: 0, cpa: 0,
  spend_original: 4128.93, spend_withheld_rows: 262,
  revenue_original: 12969.03, revenue_withheld_rows: 262,
  money_original_currency: 'USD', money_original_currencies: 1,
  ctr: 0.0076, cpc: 0, cpm: 0,
  reach: 0, video_views: 0, video_completions: 0, landing_page_views: 0, leads: 0,
  qualified_leads: 0, purchases: 0, installs: 0, registrations: 0, in_app_events: 0,
  engagements: 0, frequency: null, cpl: null, cpi: null, cpe: null, aov: null,
  conversion_rate: null, engagement_rate: null, video_completion_rate: null,
}

const CONVERTED_TOTALS = { ...WITHHELD_TOTALS, spend: 15480.5, revenue: 46000, roas: 2.97, cpa: 151.77, spend_withheld_rows: 0, revenue_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 }

function route(totals: Record<string, unknown>, currency: string | null = 'SAR') {
  vi.mocked(getData).mockImplementation((path: string) => {
    if (path.includes('/metrics/summary')) {
      return Promise.resolve({
        current: totals, previous: totals, delta: {}, reported: {}, commerce: null, currency,
        conversions_basis: {
          source: 'platform_reported' as const, label_ar: '', label_en: 'Platform-Reported',
          providers: ['snapchat'], may_double_count: false, is_unique_order_count: false as const,
          note_ar: '', note_en: '',
        },
      })
    }
    if (path.includes('/metrics/timeseries')) return Promise.resolve([])
    if (path.includes('disclaimer')) return Promise.resolve(null)
    return Promise.resolve([])
  })
}

describe('the Analytics money cards obey one provenance', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })

  afterEach(() => {
    signOut()
    useProject.getState().setCurrentProjectId(null)
    vi.clearAllMocks()
  })

  it('shows the real withheld amounts instead of zero', async () => {
    route(WITHHELD_TOTALS)
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    // Spend and Revenue carry the platform's own figure, in its own currency.
    expect(await screen.findByText('4,128.93 USD')).toBeInTheDocument()
    expect(screen.getByText('12,969.03 USD')).toBeInTheDocument()

    // And none of them reads as a zero in the project currency.
    expect(screen.queryByText('0 SAR')).not.toBeInTheDocument()
  })

  /**
   * The defect this file exists for: ROAS rendered from the raw aggregator field while the canonical
   * reader sat unused. 12,969.03 / 4,128.93 = 3.14 — the ratio survives the missing rate because
   * both sides are USD.
   */
  it('derives ROAS from the originals rather than printing the aggregator zero', async () => {
    route(WITHHELD_TOTALS)
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    await screen.findByText('4,128.93 USD')

    // The aggregator's own roas was 0. The card must not show it.
    expect(screen.queryByText('0.00x')).not.toBeInTheDocument()
    expect(screen.queryByText('0x')).not.toBeInTheDocument()

    // 12969.03 / 4128.93 ≈ 3.14
    expect(screen.getByText(/3\.1/)).toBeInTheDocument()
  })

  it('refuses ROAS when the originals are in different currencies', async () => {
    route({ ...WITHHELD_TOTALS, money_original_currencies: 2 })
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    await screen.findByText('ROAS')

    // A ratio across unlike units is not a number anybody measured.
    expect(screen.queryByText(/3\.1/)).not.toBeInTheDocument()
  })

  it('leaves genuinely converted money alone', async () => {
    route(CONVERTED_TOTALS)
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    // Wait for the LOADED strip, not for page chrome: a label exists before the data arrives, and
    // asserting on it passes for the wrong reason.
    await screen.findByText('15K SAR')

    // The aggregator's own ratio is used verbatim when the money converted cleanly.
    expect(document.body.textContent).toContain('2.97x')

    // Nothing claims the figure came from an unconvertible original.
    expect(screen.queryByText(/USD/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Conversion to the project currency/)).not.toBeInTheDocument()
  })
})
