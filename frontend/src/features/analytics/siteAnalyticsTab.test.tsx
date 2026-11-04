import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { SiteAnalyticsTab } from './SiteAnalyticsTab'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/** GA4-ANALYTICS-PRODUCT-001 — the site tab states what GA4 measured, and says plainly when it cannot. */
const range = { from: '2026-10-01', to: '2026-10-10' }

describe('the site analytics tab', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('says GA4 is not connected rather than drawing an empty dashboard', async () => {
    vi.mocked(getData).mockResolvedValue({ state: 'not_connected', period: range })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })
    expect(await screen.findByTestId('site-state-not_connected')).toHaveTextContent('Google Analytics 4 is not connected')
  })

  it('states sessions, engagement, purchases and revenue, the paid share per platform, and absent breakdowns as absent', async () => {
    vi.mocked(getData).mockResolvedValue({
      state: 'ready', period: range, property: { id: '111', name: 'Store', timezone: 'Asia/Riyadh' }, last_synced_at: null, currency: 'SAR',
      totals: { sessions: 1000, engaged_sessions: 560, engagement_rate: 0.56, purchases: 30, key_events: 30, revenue: 5500, users_daily_sum: 1400 },
      trend: [{ date: '2026-10-05', sessions: 1000, engaged_sessions: 560, purchases: 30, revenue: 5500 }],
      funnel: [{ stage: 'sessions', count: 1000, step_rate: null, exceeds_previous: false }, { stage: 'purchases', count: 30, step_rate: 0.03, exceeds_previous: false }],
      paid: { available: true, sessions: 500, purchases: 17, revenue: 3400, session_share: 0.5, purchase_share: 0.567, revenue_share: 0.618, platforms: [{ platform: 'meta', sessions: 300, purchases: 9, revenue: 1800 }, { platform: 'google', sessions: 200, purchases: 8, revenue: 1600 }] },
      breakdowns: { source_medium: { rows: [{ dimension_1: 'facebook', dimension_2: 'paid_social', sessions: 300, purchases: 9, revenue: 1800, engagement_rate: 0.5, conversion_rate: 0.03 }], more: 0 }, campaign: null, landing_page: null, campaign_landing: { rows: [{ dimension_1: 'national_day_sale', dimension_2: '/sale/national-day', sessions: 250, purchases: 8, revenue: 1700, engagement_rate: 0.6, conversion_rate: 0.032 }], more: 0 }, device: null, country: null, user_type: null },
      events: { rows: [{ event: 'purchase', event_count: 30, key_events: 30, is_key_event: true }], more: 0 },
    })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('site-kpi-sessions')).toHaveTextContent('1,000')
    expect(screen.getByTestId('site-kpi-sessions')).toHaveTextContent('1,400 users (sum of daily)')
    expect(screen.getByTestId('site-kpi-engagement')).toHaveTextContent('56.0%')
    expect(screen.getByTestId('site-kpi-revenue')).toHaveTextContent('5.5K SAR')
    expect(screen.getByTestId('site-kpi-revenue')).toHaveTextContent('62% from paid visits')
    const paid = screen.getByTestId('site-paid')
    expect(within(paid).getAllByText('Meta').length).toBeGreaterThan(0)
    // TRAFFIC-DRILLDOWN-001 — source / medium, campaign and landing page moved into the paged explorer (tests below).
    expect(screen.getByTestId('site-events')).toHaveTextContent('purchase')
  })

  const ready = {
    state: 'ready', period: range, property: { id: '111', name: 'Store', timezone: 'Asia/Riyadh' }, last_synced_at: null, currency: 'SAR',
    totals: { sessions: 1000 }, trend: [], funnel: [], paid: { available: false }, breakdowns: {}, events: null,
  }
  const kpi = (key: string, kind: string, current: number | null, previous: number | null, metrics: string[]) => ({
    key, kind, current, previous, change: current !== null && previous !== null ? current - previous : null,
    change_pct: current !== null && previous ? (current - previous) / Math.abs(previous) : null, currency: kind === 'money' ? 'SAR' : null,
    definition: `def ${key}`, source: { kind: 'ga4', metrics }, blended_with_other_sources: false,
    freshness: { last_synced_at: null, newest_day: '2026-10-09', days_with_data: 8, days_in_window: 10 },
  })

  it('shows the new GA4 KPIs with previous period, change, definition and source, and an absent KPI as a dash, not zero', async () => {
    vi.mocked(getData).mockImplementation(async (url: string) => (url.includes('/table?')
      ? { state: 'ready', rows: [], total: 0, page: 1, per_page: 10, currency: 'SAR' }
      : {
        ...ready, previous_period: { from: '2026-09-21', to: '2026-09-30' },
        kpis: [
          kpi('bounce_rate', 'rate', 0.3, 0.6, ['bounceRate', 'sessions']),
          kpi('aov', 'money', 200, 100, ['purchaseRevenue', 'transactions']),
          kpi('items_per_order', 'ratio', null, null, ['itemsPurchased', 'transactions']),
        ],
      }))
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })

    const bounce = await screen.findByTestId('site-kpi-bounce_rate')
    expect(bounce).toHaveTextContent('30.0%')
    expect(bounce).toHaveTextContent('Previous: 60.0%')
    expect(bounce).toHaveTextContent('−50.0%')
    expect(screen.getByTestId('site-kpi-aov')).toHaveTextContent('200 SAR')
    const absent = screen.getByTestId('site-kpi-items_per_order')
    expect(absent).toHaveTextContent('—')
    expect(absent).not.toHaveTextContent('0.00')
    expect(absent).toHaveTextContent('The property did not report this')
    expect(screen.getByTestId('site-kpi-def-bounce_rate')).toHaveTextContent('[GA4: bounceRate, sessions]')
    expect(screen.getByTestId('site-kpi-def-bounce_rate')).toHaveTextContent('8/10 days')
  })

  it('pages the traffic table on the server: page size, page, sort and drill-down are requests, never client slicing', async () => {
    const calls: string[] = []
    const rowsFor = (n: number) => Array.from({ length: n }, (_, i) => ({ dimension_1: `Channel ${i + 1}`, dimension_2: null, sessions: 100 - i, engaged_sessions: 50, purchases: 2, revenue: 400, engagement_rate: 0.5, conversion_rate: 0.02 }))
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (!url.includes('/table?')) return ready
      calls.push(url)
      return { state: 'ready', rows: rowsFor(10), total: 87, page: 1, per_page: 10, currency: 'SAR' }
    })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })

    const box = await screen.findByTestId('site-traffic')
    await waitFor(() => expect(within(box).getByTestId('table-total')).toHaveTextContent('1–10 / 87 total'))
    expect(calls[0]).toContain('breakdown=default_channel_group')
    expect(calls[0]).toContain('per_page=10')
    // a server page holds 10 rows even though the property has 87: nothing is sliced here
    expect(box.querySelectorAll('tbody tr')).toHaveLength(10)

    fireEvent.change(within(box).getByTestId('table-page-size'), { target: { value: '25' } })
    await waitFor(() => expect(calls.some((c) => c.includes('per_page=25'))).toBe(true))
    fireEvent.click(within(box).getByRole('button', { name: 'Page 2' }))
    await waitFor(() => expect(calls.some((c) => c.includes('page=2'))).toBe(true))
    fireEvent.click(within(box).getByRole('button', { name: 'Sessions' }))
    await waitFor(() => expect(calls.some((c) => c.includes('sort=sessions') && c.includes('dir=asc'))).toBe(true))

    // drill from a channel into its source / medium: the next request is the chained breakdown, filtered by that channel
    fireEvent.click(within(box).getAllByTestId('traffic-drill')[0])
    await waitFor(() => expect(calls.some((c) => c.includes('breakdown=channel_source') && c.includes('where_d1=Channel+1'))).toBe(true))
    expect(within(box).getByTestId('traffic-crumbs')).toHaveTextContent('Channel 1')
  })

  it('keeps the traffic table state when the reader leaves and returns', async () => {
    window.sessionStorage.clear()
    const calls: string[] = []
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (!url.includes('/table?')) return ready
      calls.push(url)
      return { state: 'ready', rows: [], total: 87, page: 1, per_page: 10, currency: 'SAR' }
    })
    const first = renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })
    const box = await screen.findByTestId('site-traffic')
    await waitFor(() => expect(within(box).getByTestId('table-pager')).toBeInTheDocument())
    fireEvent.click(within(box).getByRole('button', { name: 'Page 2' }))
    await waitFor(() => expect(calls.some((c) => c.includes('page=2'))).toBe(true))
    first.unmount()
    calls.length = 0
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })
    await screen.findByTestId('site-traffic')
    await waitFor(() => expect(calls.length).toBeGreaterThan(0))
    expect(calls[0]).toContain('page=2')
  })
})
