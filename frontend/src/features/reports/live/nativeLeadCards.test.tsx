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

/**
 * NASEL 2026-10-29 — Production's figures for the Riwaq links: 94 verified native lead-form submissions (Meta 39, TikTok 11, Snapchat 44),
 * 2 landing-page completions measured as the `purchases` event, and 2 pixel «Lead» events on Meta. The platforms summary used to read the
 * pixel figure (2 / 0 / 0 / 2); it reads the server's one resolver now.
 */
const ev = { lead_form: 'form_leads', landing_page: 'purchases' }
const payload = {
  period: { from: '2026-10-01', to: '2026-10-29', days: 29 },
  currency: 'USD',
  totals: { spend: 672.75, impressions: 133_213, clicks: 6242, leads: 2, form_leads: 94, purchases: 2, landing_page_views: 43, conversions: 1127, ctr: 0.0469 },
  deltas: {},
  timeseries: [{ date: '2026-10-28', spend: 300, clicks: 3000, impressions: 60_000, form_leads: 40, leads: 1 }],
  platforms: [
    { provider: 'meta', spend: 196.66, impressions: 19_475, clicks: 400, leads: 2, form_leads: 39, purchases: 1, ctr: 0.02 },
    { provider: 'tiktok', spend: 246.19, impressions: 77_124, clicks: 5492, leads: 0, form_leads: 11, purchases: 0, ctr: 0.07 },
    { provider: 'snapchat', spend: 229.89, impressions: 36_614, clicks: 350, leads: 0, form_leads: 44, purchases: 1, ctr: 0.01 },
  ],
  campaigns: [],
  funnel: [],
  ads: [],
  ads_weakest: [],
  ads_groups: [],
  ads_roster: [],
  sections: { attribution: false },
  store_funnel: null,
  freshness: [],
  available: { providers: ['meta', 'tiktok', 'snapchat'], campaigns: [], earliest: '2026-10-01', latest: '2026-10-29' },
  metrics: ['spend', 'impressions', 'clicks', 'leads', 'native_leads'],
  applied: { from: '2026-10-01', to: '2026-10-29', providers: [], campaigns: [] },
  results: {
    configured: true, total: 96, cost: 7.0077, by_platform: {}, by_path: { lead_form: 94, landing_page: 2 }, events: { meta: ev, tiktok: ev, snapchat: ev },
    leads: {
      configured: true, total: 96, native: 94, landing: 2, cost: 7.0077,
      native_by_platform: { meta: 39, tiktok: 11, snapchat: 44 },
      landing_by_platform: { meta: 1, tiktok: 0, snapchat: 1 },
      landing_funnel: { campaigns: 3, page_views: 43, form_starts: 120, contacts: null, completions: 2, completion_rate: 0.0167, spend: 296.8, cost_per_completion: 148.4, basis: 'platform_attributed_not_unique_submissions' },
      by_platform: {}, by_path: { lead_form: 94, landing_page: 2 }, events: { meta: ev, tiktok: ev, snapchat: ev },
    },
  },
}

const render = () => {
  vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: payload } } as never)
  renderWithProviders(<LiveSharedReport token="tok" currency="USD" form="executive_summary" />, { locale: 'ar' })
}

describe('NASEL native lead cards — one resolver for the summary', () => {
  afterEach(() => vi.clearAllMocks())

  it('the platform cards show native leads only and add up to the native total', async () => {
    render()
    const cards = await screen.findByTestId('exec-platform-cards')
    const figure = (key: string) => within(within(cards).getByTestId(`exec-platform-card-${key}`)).getByText(/^\d+$/).textContent

    expect(figure('meta')).toBe('39')
    expect(figure('tiktok')).toBe('11')
    expect(figure('snapchat')).toBe('44')
    expect(figure('all')).toBe('94')
    expect(Number(figure('meta')) + Number(figure('tiktok')) + Number(figure('snapchat'))).toBe(Number(figure('all')))
    expect(cards).toHaveTextContent('العملاء المحتملون الأصليون')
  })

  it('never puts landing completions or the pixel Lead into a platform card', async () => {
    render()
    const cards = await screen.findByTestId('exec-platform-cards')

    // Meta carries 1 landing completion and 2 pixel Leads; its card is still the 39 native submissions — not 40, not 41, not 2.
    expect(within(within(cards).getByTestId('exec-platform-card-meta')).getByText(/^\d+$/)).toHaveTextContent(/^39$/)
    expect(within(within(cards).getByTestId('exec-platform-card-all')).getByText(/^\d+$/)).toHaveTextContent(/^94$/)
    expect(cards).not.toHaveTextContent('96')
  })

  it('the summary under the report is native + landing completions, from the same figures', async () => {
    render()
    const summary = await screen.findByTestId('exec-lead-summary')

    expect(within(summary).getByTestId('exec-lead-native')).toHaveTextContent('94')
    expect(within(summary).getByTestId('exec-lead-landing')).toHaveTextContent('2')
    expect(within(summary).getByTestId('exec-lead-interest')).toHaveTextContent('إجمالي الاهتمام')
    expect(within(summary).getByTestId('exec-lead-interest')).toHaveTextContent('96')
    expect(within(summary).getByTestId('exec-lead-page-views')).toHaveTextContent('43')
    expect(within(summary).getByTestId('exec-lead-spend')).toHaveTextContent('672.75')
    expect(within(summary).getByTestId('exec-lead-cost')).toHaveTextContent('7.01')
  })

  it('shows the landing-page funnel from landing campaigns only, and an unreported step as not available', async () => {
    render()
    const summary = await screen.findByTestId('exec-lead-summary')

    expect(within(summary).getByTestId('exec-lead-form-starts')).toHaveTextContent('120')
    expect(within(summary).getByTestId('exec-lead-completion-rate')).toHaveTextContent('1.7%')
    expect(within(summary).getByTestId('exec-lead-landing-cost')).toHaveTextContent('148.40')
    // Contact taps are not ingested from any source: absent, never a zero.
    expect(within(summary).getByTestId('exec-lead-contacts')).toHaveTextContent('غير متاح')
    expect(screen.getByTestId('exec-lead-basis')).toHaveTextContent('ليست عددًا فريدًا')
  })

  it('never shows clicks in place of leads on the platform cards', async () => {
    render()
    const cards = await screen.findByTestId('exec-platform-cards')

    // TikTok had 5,492 clicks and 11 native leads: the card's figure is the leads; clicks are not a stand-in.
    expect(within(within(cards).getByTestId('exec-platform-card-tiktok')).getByText(/^\d+$/)).toHaveTextContent(/^11$/)
    expect(cards).not.toHaveTextContent('النقرات')
  })

  it('names the purchase event used for landing-page forms as form completions, not purchases', async () => {
    render()
    const summary = await screen.findByTestId('exec-lead-summary')

    expect(within(summary).getByTestId('exec-lead-landing')).toHaveTextContent('إتمامات نموذج الصفحة')
    expect(screen.getByTestId('live-mode-summary')).not.toHaveTextContent('المشتريات')
  })
})
