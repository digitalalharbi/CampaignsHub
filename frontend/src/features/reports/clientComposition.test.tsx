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
 * CLIENT-FACING-PRESENTATION-001 — the ORDER is the requirement.
 *
 * A client asks six questions, and they ask them in one order: what was spent, what was achieved, at
 * what cost, what improved or declined, WHERE, and what needs attention. A page that answers them in
 * a different order is one they have to read rather than scan, however correct every figure on it is.
 *
 * The defect this pins: the objective split — which answers «at what cost, really» by separating
 * direct from blended — sat BELOW the platform and campaign charts, which answer «where». So a reader
 * met «where» before «at what cost», and had to scroll past two charts to learn whether the headline
 * cost per order was even the right question to have asked.
 *
 * Asserted as document order rather than by counting pixels: `compareDocumentPosition` is what
 * «above» means in a document that reflows on a phone.
 */
const payload = {
  period: { from: '2026-08-01', to: '2026-08-30' },
  currency: 'SAR',
  totals: { spend: 1000, conversions: 40, revenue: 4000, impressions: 90000, clicks: 700 },
  deltas: { spend: 0.1, conversions: -0.2 },
  timeseries: [{ date: '2026-08-01', spend: 100, conversions: 4 }],
  platforms: [{ provider: 'meta', spend: 600, conversions: 24 }],
  campaigns: [{ campaign_name: 'Eid', spend: 600, conversions: 24, objective: 'sales' }],
  ad_sets: [],
  funnel: [{ key: 'impressions', label: 'Impressions', value: 90000, reported: true }],
  funnel_spend: 1000,
  ads: [],
  ads_groups: [],
  objective_performance: {
    direct: { label_ar: 'الأداء المباشر', label_en: 'Direct', spend: 600, conversions: 24, cpa: 25 },
    blended: { label_ar: 'الأداء المدمج', label_en: 'Blended', spend: 1000, blended_cpa: 41.6 },
  },
  objective_leaders: { paths: [] },
  sections: { attribution: false },
  store_funnel: null,
  freshness: [],
  metrics: [],
  available: { providers: ['meta'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  applied: {},
  outline: [],
  is_demo: false,
  form: 'executive_summary',
}

const positionOf = (a: HTMLElement, b: HTMLElement): number => a.compareDocumentPosition(b)

describe('the order a client reads a live report in', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: payload } } as never)
  })
  afterEach(() => vi.clearAllMocks())

  it('answers «at what cost» before «where»', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const kpis = await screen.findByTestId('live-kpis')
    const objective = await screen.findByTestId('live-objective-split')
    const platforms = await screen.findByTestId('live-platforms')
    const campaigns = await screen.findByTestId('live-campaigns')

    // DOCUMENT_POSITION_FOLLOWING === 4: the second argument comes after the first.
    expect(positionOf(kpis, objective) & 4, 'the objective split must follow the KPI cards').toBeTruthy()
    expect(positionOf(objective, platforms) & 4, 'platforms must follow the objective split').toBeTruthy()
    expect(positionOf(platforms, campaigns) & 4, 'campaigns must follow platforms').toBeTruthy()
  })

  /**
   * The first screen is the summary, and the detail is not on it.
   *
   * Progressive disclosure is the requirement's word: the executive form carries no per-campaign,
   * per-platform, per-ad-set tables at all. A reader who wants them opens the detailed link.
   */
  it('keeps the detail off the summary form', async () => {
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="executive_summary" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    expect(screen.queryByTestId('live-detail-tables')).toBeNull()
  })
})
