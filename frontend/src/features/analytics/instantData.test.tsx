import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SiteAnalyticsTab } from './SiteAnalyticsTab'
import { StoreFunnelTab } from './StoreFunnelTab'
import { signInWith, signOut } from '@/test/utils'
import { useUi } from '@/stores/ui'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/**
 * PERF-INSTANT-DATA-001 — the Owner's screenshot: Analytics → GA4 / store opened on an empty block and
 * then filled in. A revisit paints at once, a changed period keeps the figures on screen (named for the
 * period they were read for), another project never does, and a cold open is a skeleton of the final size.
 */
const R1 = { from: '2026-10-01', to: '2026-10-10' }
const R2 = { from: '2026-09-01', to: '2026-09-10' }

function ga4(range: { from: string; to: string }, sessions: number) {
  return {
    state: 'ready', period: range, property: { id: '111', name: 'Store', timezone: 'Asia/Riyadh' }, last_synced_at: '2026-10-10T08:00:00Z', currency: 'SAR',
    totals: { sessions, engaged_sessions: 1, engagement_rate: 0.5, purchases: 1, key_events: 1, revenue: 10 },
    trend: [], funnel: [], paid: { available: false }, breakdowns: {}, events: null,
  }
}

/** GA4-STORE-ANALYTICS-001 — the tab's figures are the store reading's tiles; one answer per address. */
function store(range: { from: string; to: string }, sessions: number) {
  return { state: 'ready', period: range, previous_period: range, currency: 'SAR', kpis: [{ key: 'sessions', kind: 'count', current: sessions, previous: null, change_pct: null, currency: null }], series: [], channels: null, devices: null, funnel: [], tables: {} }
}
const answer = (range: { from: string; to: string }, sessions: number) => async (url: string) => (url.includes('/measurement/store') ? store(range, sessions) : ga4(range, sessions))

function mount(client: QueryClient, ui: React.ReactElement) {
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/agency/analytics']}>{ui}</MemoryRouter></QueryClientProvider>)
}
const newClient = () => new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 60_000, staleTime: 30_000 } } })

describe('GA4 tab — instant data', () => {
  beforeEach(() => { vi.clearAllMocks(); useUi.setState({ locale: 'en' }); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('a cold open draws a skeleton of the final parts (four KPI slots, the chart row), not one blank block', async () => {
    vi.mocked(getData).mockImplementation(() => new Promise(() => {}))
    mount(newClient(), <SiteAnalyticsTab projectId="p1" range={R1} />)

    const sk = screen.getByTestId('site-skeleton')
    expect(sk).toHaveAttribute('aria-busy', 'true')
    expect(screen.getAllByTestId(/^site-kpi-.*-skeleton$/)).toHaveLength(4)
    expect(screen.getByTestId('site-trend-skeleton')).toBeInTheDocument()
  })

  it('a revisit paints the cached figures on the first frame and revalidates behind them', async () => {
    const client = newClient()
    vi.mocked(getData).mockImplementation(answer(R1, 1000) as never)
    const first = mount(client, <SiteAnalyticsTab projectId="p1" range={R1} />)
    expect(await screen.findByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')
    first.unmount()

    vi.mocked(getData).mockImplementation(() => new Promise(() => {}))
    client.invalidateQueries() // the entry is stale now, as after staleTime
    mount(client, <SiteAnalyticsTab projectId="p1" range={R1} />)

    expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')
    expect(screen.queryByTestId('site-skeleton')).toBeNull()
    expect(screen.getByTestId('refresh-note')).toHaveAttribute('data-state', 'refreshing')
  })

  it('changing the period keeps the figures and names the period they belong to; nothing is emptied', async () => {
    const client = newClient()
    vi.mocked(getData).mockImplementation(answer(R1, 1000) as never)
    const { rerender } = mount(client, <SiteAnalyticsTab projectId="p1" range={R1} />)
    expect(await screen.findByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')

    const pending: Array<[string, (v: unknown) => void]> = []
    vi.mocked(getData).mockImplementation((url: string) => new Promise((r) => { pending.push([url, r]) }))
    rerender(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/agency/analytics']}><SiteAnalyticsTab projectId="p1" range={R2} /></MemoryRouter></QueryClientProvider>)

    expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')
    const note = screen.getByTestId('refresh-note')
    expect(note).toHaveAttribute('data-state', 'previous')
    expect(note).toHaveTextContent('2026-10-01')
    expect(note).toHaveTextContent('2026-10-10')

    await act(async () => { for (const [url, r] of pending) r(url.includes('/measurement/store') ? store(R2, 222) : ga4(R2, 222)) })
    await waitFor(() => expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('222'))
    expect(screen.queryByTestId('refresh-note')).toBeNull()
  })

  it('another project never shows the previous project’s figures, not even on the first frame', async () => {
    const client = newClient()
    vi.mocked(getData).mockImplementation(answer(R1, 1000) as never)
    const { rerender } = mount(client, <SiteAnalyticsTab projectId="p1" range={R1} />)
    expect(await screen.findByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')

    vi.mocked(getData).mockImplementation(() => new Promise(() => {}))
    rerender(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/agency/analytics']}><SiteAnalyticsTab projectId="p2" range={R1} /></MemoryRouter></QueryClientProvider>)

    expect(screen.queryByText('1,000')).toBeNull()
    expect(screen.getByTestId('site-skeleton')).toBeInTheDocument()
  })

  it('a failed refresh keeps the figures and says so; a failed cold open is an explicit error, not a spinner', async () => {
    const client = newClient()
    vi.mocked(getData).mockImplementation(answer(R1, 1000) as never)
    mount(client, <SiteAnalyticsTab projectId="p1" range={R1} />)
    expect(await screen.findByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')

    vi.mocked(getData).mockRejectedValue(new Error('down'))
    await act(async () => { await client.refetchQueries() })
    await waitFor(() => expect(screen.getByTestId('refresh-note')).toHaveAttribute('data-state', 'failed'))
    expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('1,000')

    const cold = newClient()
    mount(cold, <SiteAnalyticsTab projectId="p9" range={R1} />)
    expect(await screen.findByTestId('site-failure-retryable')).toBeInTheDocument()
    expect(screen.queryAllByTestId('site-skeleton').length).toBe(0)
  })
})

function funnel(range: { from: string; to: string }, orders: number) {
  const src = { kind: 'stores', ar: 'من المتجر', en: 'From the store' }
  return {
    window: range,
    stages: [{ key: 'orders', label_ar: 'الطلبات', label_en: 'Orders', value: orders, state: 'measured', source: src, note_ar: null, note_en: null }],
    steps: [],
    totals: { reporting_currency: 'SAR', spend: 1, revenue: 1, gross_revenue: 1, refunded: 0, cancelled_orders: 0, orders, new_customers: 0, attributed_orders: 0, attributed_revenue: 0, unattributed_orders: 0 },
    derived: { cpa: null, cac: null, aov: null, roas: null, attributed_roas: null, conversion_rate: null },
    comparisons: { platforms: [], campaigns: [], products: [] },
    coverage: { stores: 1, stores_without_cart_data: [], store_last_synced_at: null, orders_in_window: orders, orders_without_attribution: 0, reporting_currency: 'SAR', orders_with_money_withheld: 0, money_withheld_currencies: [] },
  }
}

describe('store funnel tab — instant data', () => {
  beforeEach(() => { vi.clearAllMocks(); useUi.setState({ locale: 'en' }); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  const wrap = (client: QueryClient, ui: React.ReactElement) => <QueryClientProvider client={client}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>

  it('a cold open is a skeleton of the final size, not one slab', () => {
    vi.mocked(getData).mockImplementation(() => new Promise(() => {}))
    mount(newClient(), <StoreFunnelTab projectId="p1" range={R1} />)
    expect(screen.getByTestId('store-funnel-skeleton')).toHaveAttribute('aria-busy', 'true')
  })

  it('a changed period keeps the funnel on screen, names the period it was read for, then swaps', async () => {
    const client = newClient()
    vi.mocked(getData).mockResolvedValue(funnel(R1, 25))
    const { rerender } = render(wrap(client, <StoreFunnelTab projectId="p1" range={R1} />))
    expect(await screen.findByTestId('funnel-stage-orders')).toHaveTextContent('25')

    let release: (v: unknown) => void = () => {}
    vi.mocked(getData).mockImplementation(() => new Promise((r) => { release = r }))
    rerender(wrap(client, <StoreFunnelTab projectId="p1" range={R2} />))

    expect(screen.getByTestId('funnel-stage-orders')).toHaveTextContent('25')
    expect(screen.getByTestId('refresh-note')).toHaveAttribute('data-state', 'previous')
    expect(screen.getByTestId('refresh-note')).toHaveTextContent('2026-10-01')

    await act(async () => { release(funnel(R2, 7)) })
    await waitFor(() => expect(screen.getByTestId('funnel-stage-orders')).toHaveTextContent('7'))
    expect(screen.queryByTestId('refresh-note')).toBeNull()
  })

  it('another project never shows the previous project’s funnel', async () => {
    const client = newClient()
    vi.mocked(getData).mockResolvedValue(funnel(R1, 25))
    const { rerender } = render(wrap(client, <StoreFunnelTab projectId="p1" range={R1} />))
    expect(await screen.findByTestId('funnel-stage-orders')).toBeInTheDocument()

    vi.mocked(getData).mockImplementation(() => new Promise(() => {}))
    rerender(wrap(client, <StoreFunnelTab projectId="p2" range={R1} />))

    expect(screen.queryByTestId('funnel-stage-orders')).toBeNull()
    expect(screen.getByTestId('store-funnel-skeleton')).toBeInTheDocument()
  })
})
