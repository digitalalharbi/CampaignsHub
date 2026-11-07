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

  it('says the website is not connected rather than drawing an empty dashboard', async () => {
    vi.mocked(getData).mockResolvedValue({ state: 'not_connected', period: range })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })
    expect(await screen.findByTestId('site-state-not_connected')).toHaveTextContent('Website not connected')
  })

  it('draws the store reading first, then the events, and says an absent breakdown is absent', async () => {
    vi.mocked(getData).mockImplementation(async (url: string) => (url.includes('/measurement/store')
      ? {
        state: 'ready', period: range, previous_period: { from: '2026-09-21', to: '2026-09-30' }, currency: 'SAR', usd: { available: true, from: 'SAR', rate: 0.25 },
        kpis: [
          { key: 'sessions', kind: 'count', current: 1000, previous: 800, change_pct: 0.25, currency: null },
          { key: 'revenue', kind: 'money', current: 5500, previous: 5000, change_pct: 0.1, currency: 'SAR', current_usd: 1375, previous_usd: 1250 },
        ],
        series: [], channels: null, devices: null, funnel: [],
        gap: { summed: false, rows: [{ platform: 'meta', reported: { conversions: 20, revenue: null, currency: null, revenue_usd: null }, ga4: { sessions: 300, purchases: 9, revenue: 1800, currency: 'SAR', revenue_usd: 450, session_share: 0.3 }, gap: { conversions: 11, conversions_share: 0.55, revenue_usd: null, revenue_share: null } }], unpaid: [], other_paid: null, ga4_sessions: 1000 },
        tables: {},
      }
      : url.includes('/table?') ? { state: 'ready', rows: [], total: 0, page: 1, per_page: 10, currency: 'SAR' }
        : {
          state: 'ready', period: range, property: { id: '111', name: 'Store', timezone: 'Asia/Riyadh' }, last_synced_at: null, currency: 'SAR',
          breakdowns: { user_type: null },
          events: { rows: [{ event: 'purchase', event_count: 30, key_events: 30, is_key_event: true }], more: 0 },
        }))
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')
    // Money reads in USD; the property's own currency stays beside it.
    expect(screen.getByTestId('ga4-tile-revenue')).toHaveTextContent('1.38K USD')
    expect(screen.getByTestId('ga4-tile-revenue')).toHaveTextContent('5.5K SAR')
    expect(screen.getByTestId('ga4-gap-chip-meta')).toHaveTextContent('+11 · 55%')
    expect(screen.getByTestId('site-not-summed')).toBeInTheDocument()
    expect(screen.getByTestId('site-user-type-absent')).toBeInTheDocument()
    expect(screen.getByTestId('site-events')).toHaveTextContent('purchase')
    // The tab sits above the store reading in the DOM order: the figure first, the detail after.
    const order = screen.getByTestId('site-analytics').innerHTML
    expect(order.indexOf('ga4-tiles')).toBeLessThan(order.indexOf('site-events'))
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

  it('shows the new website KPIs with previous period, change, definition and source, and an absent KPI as a dash, not zero', async () => {
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

    const bounce = await screen.findByTestId('ga4-tile-bounce_rate')
    expect(bounce).toHaveTextContent('30.0%')
    expect(bounce).toHaveTextContent('60.0%')
    expect(screen.getByTestId('ga4-delta-bounce_rate')).toHaveTextContent('50.0%')
    // A falling bounce rate is the good direction, and is coloured as one.
    expect(screen.getByTestId('ga4-delta-bounce_rate')).toHaveAttribute('data-good', 'yes')
    expect(screen.getByTestId('ga4-tile-aov')).toHaveTextContent('200 SAR')
    const absent = screen.getByTestId('site-kpi-items_per_order')
    expect(absent).toHaveTextContent('—')
    expect(absent).not.toHaveTextContent('0.00')
    expect(absent).toHaveTextContent('The property did not report this')
    expect(screen.getByTestId('site-kpi-def-bounce_rate')).toHaveTextContent('[bounceRate, sessions]')
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
