import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import * as client from '@/lib/api/client'
import { WidgetSurface } from './WidgetSurface'
import { LinkActivityWidget } from './LinkActivityWidget'
import { AttributionCreditWidget } from './AttributionCreditWidget'
import { DataState } from './DataState'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof client>()), getData: vi.fn(), putData: vi.fn() }))
// Recharts measures its container; in jsdom that is zero, so the chart internals are replaced by a marker that keeps the data.
vi.mock('@/features/analytics/charts', () => ({
  ChartCard: ({ title, subtitle, action, children }: { title: string; subtitle?: string; action?: React.ReactNode; children: React.ReactNode }) => (
    <section><h3>{title}</h3>{subtitle && <p data-testid="card-subtitle">{subtitle}</p>}{action}{children}</section>
  ),
  MetricLineChart: ({ data }: { data: unknown[] }) => <div data-testid="chart-line" data-points={data.length} />,
  PlatformDonutChart: ({ data }: { data: Array<{ name: string; value: number }> }) => <div data-testid="chart-donut" data-slices={data.map((d) => `${d.name}:${d.value}`).join('|')} />,
  RankingBarChart: ({ data }: { data: Array<{ label: string; orders: number }> }) => <div data-testid="chart-bars" data-rows={data.map((d) => `${d.label}:${d.orders}`).join('|')} />,
}))

const range = { from: '2026-10-14', to: '2026-10-21' }
const widget = (over: Record<string, unknown>) => ({
  key: 'link_opens', visible: true, order: 10, size: 'half', kind: 'trend', module: 'advanced_tracking', reason: null, title: { en: 'Link activity', ar: 'نشاط الروابط' }, ...over,
})
const layout = (widgets: unknown[]) => ({ surface: 'dashboard', widgets, sizes: ['third', 'half', 'full'] })

const summary = (over: Record<string, unknown> = {}) => ({
  state: 'measured', period: { from: '2026-10-14', to: '2026-10-21', timezone: 'Asia/Riyadh' }, tracking_since: '2026-10-18',
  opens: 14, machine_opens: 5,
  series: [{ period: '2026-10-18', opens: 6, devices: 4 }, { period: '2026-10-19', opens: 8, devices: 5 }],
  by_type: { short_link_redirect: 14 }, by_destination: { whatsapp: 9, external_url: 5 },
  top_links: [{ id: 'l1', slug: 'abc2345', destination_host: 'store.example', opens: 14 }],
  basis: { counts: 'recorded_events', unique_basis: 'devices_per_day_estimate', includes_sales: false },
  ...over,
})

const credit = (over: Record<string, unknown> = {}) => ({
  model_comparison: {
    credit_models: {
      version: '2026-10-credit-2', evidence_basis: 'verified_marketing_touchpoints', models: ['linear', 'last_click'], lookback_days: 30,
      coverage: { orders_credited: 5, orders_with_verified_touchpoints: 3, orders_without_evidence: 2, orders_excluded_fully_refunded: 1, orders_with_withheld_revenue: 1, share_with_verified_touchpoints: 0.6 },
      channels: [
        { channel: 'smart_link', kind: 'other', models: { linear: { orders: 2, revenue: 400 }, last_click: { orders: 3, revenue: 600 } } },
        { channel: 'influencer', kind: 'influencer', models: { linear: { orders: 1, revenue: 200 } } },
        { channel: 'unattributed', kind: 'unattributed', models: { linear: { orders: 2, revenue: 300 }, last_click: { orders: 2, revenue: 300 } } },
      ],
      ...over,
    },
  },
})

beforeEach(() => vi.clearAllMocks())

/** The kind of figure the widget's badge declares. */
const kind = () => document.querySelector('[data-kind]')?.getAttribute('data-kind')

describe('DataState', () => {
  it('names each kind of figure separately and in both languages', () => {
    for (const [kind, en, ar] of [['measured', 'Measured', 'مقيس'], ['estimate', 'Estimate', 'تقديري'], ['unattributed', 'Unattributed', 'غير منسوب'], ['missing', 'No data', 'لا توجد بيانات'], ['demo', 'Demo', 'تجريبي']] as const) {
      const { unmount } = renderWithProviders(<DataState kind={kind} />, { locale: 'en' })
      expect(screen.getByTestId(`data-state-${kind}`).textContent).toContain(en)
      unmount()
      const arabic = renderWithProviders(<DataState kind={kind} />, { locale: 'ar' })
      expect(screen.getByTestId(`data-state-${kind}`).textContent).toContain(ar)
      arabic.unmount()
    }
  })
})

describe('WidgetSurface', () => {
  it('draws nothing at all when every widget is behind a module that is off — the page stays as it was', async () => {
    vi.mocked(client.getData).mockResolvedValue(layout([widget({ visible: false, reason: 'module_off' }), widget({ key: 'attribution_credit', visible: false, reason: 'module_off' })]))
    const { container } = renderWithProviders(<WidgetSurface projectId="p" surface="dashboard" range={range} />, { locale: 'en' })

    await waitFor(() => expect(client.getData).toHaveBeenCalled())
    expect(container.querySelector('[data-testid^="widget-surface"]')).toBeNull()
  })

  it('draws nothing when the layout cannot be read', async () => {
    vi.mocked(client.getData).mockRejectedValue({ isAxiosError: true, response: { status: 403, data: {} } })
    const { container } = renderWithProviders(<WidgetSurface projectId="p" surface="dashboard" range={range} />, { locale: 'en' })

    await waitFor(() => expect(client.getData).toHaveBeenCalled())
    expect(container.querySelector('[data-testid^="widget-surface"]')).toBeNull()
  })

  it('draws the visible widgets in the server order and skips a hidden one', async () => {
    vi.mocked(client.getData).mockImplementation(async (url: string) => {
      if (url.includes('/widgets/')) {
        return layout([
          widget({ key: 'attribution_credit', order: 1, size: 'full' }),
          widget({ key: 'link_opens', order: 2, visible: false, reason: 'hidden_by_project' }),
        ])
      }
      return credit()
    })
    renderWithProviders(<WidgetSurface projectId="p" surface="dashboard" range={range} />, { locale: 'en' })

    expect(await screen.findByTestId('widget-attribution_credit')).toBeTruthy()
    expect(screen.queryByTestId('widget-link_opens')).toBeNull()
    expect(screen.getByTestId('widget-attribution_credit').className).toContain('lg:col-span-6')
  })

  it('lets the owner hide a widget and save, sending exactly the order, visibility and width chosen', async () => {
    vi.mocked(client.getData).mockImplementation(async (url: string) => (url.includes('/widgets/') ? layout([widget({ key: 'link_opens', order: 1 }), widget({ key: 'attribution_credit', order: 2, module: 'attribution_intelligence' })]) : summary()))
    vi.mocked(client.putData).mockResolvedValue(layout([]))
    renderWithProviders(<WidgetSurface projectId="p" surface="dashboard" range={range} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('widgets-customize'))
    fireEvent.click(await screen.findByTestId('widget-switch-attribution_credit'))
    fireEvent.click(screen.getByTestId('widgets-save'))

    await waitFor(() => expect(client.putData).toHaveBeenCalledWith('/projects/p/widgets/dashboard', {
      items: [
        { key: 'link_opens', visible: true, order: 10, size: 'half' },
        { key: 'attribution_credit', visible: false, order: 20, size: 'half' },
      ],
    }))
  })

  it('shows a module that is off as locked, with where to switch it on, and offers no switch for it', async () => {
    vi.mocked(client.getData).mockImplementation(async (url: string) => (url.includes('/widgets/') ? layout([widget({ key: 'link_opens', order: 1 }), widget({ key: 'attribution_credit', order: 2, visible: false, reason: 'module_off' })]) : summary()))
    renderWithProviders(<WidgetSurface projectId="p" surface="dashboard" range={range} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('widgets-customize'))
    const row = await screen.findByTestId('widget-row-attribution_credit')
    expect(row.textContent).toContain('Settings → Modules')
    expect((screen.getByTestId('widget-switch-attribution_credit') as HTMLButtonElement).disabled).toBe(true)
  })
})

describe('LinkActivityWidget', () => {
  it('says nothing was recorded rather than drawing a zero, and does not call it measured', async () => {
    vi.mocked(client.getData).mockResolvedValue(summary({ state: 'no_events', tracking_since: null, opens: 0, machine_opens: 0, series: [], by_destination: {}, top_links: [] }))
    renderWithProviders(<LinkActivityWidget projectId="p" range={range} />, { locale: 'en' })

    expect(await screen.findByTestId('link-activity-state')).toBeTruthy()
    expect(kind()).toBe('missing')
    expect(screen.queryByTestId('link-activity')).toBeNull()
    expect(screen.getByText(/Days before tracking was switched on were not measured/)).toBeTruthy()
  })

  it('shows human opens, the machine opens left out, since when it measured, and never sales or visitors', async () => {
    vi.mocked(client.getData).mockResolvedValue(summary())
    renderWithProviders(<LinkActivityWidget projectId="p" range={range} />, { locale: 'en' })

    expect((await screen.findByTestId('link-activity-opens')).textContent).toBe('14')
    expect(screen.getByTestId('link-activity-machines').textContent).toBe('5')
    expect(kind()).toBe('measured')
    expect(screen.getByTestId('card-subtitle').textContent).toContain('Measured since 2026-10-18')
    expect(screen.getByTestId('card-subtitle').textContent).toContain('not sales')
    expect(screen.getByTestId('chart-line').getAttribute('data-points')).toBe('2')
    expect(screen.getByTestId('chart-donut').getAttribute('data-slices')).toBe('WhatsApp:9|External site:5')
    expect(screen.getByTestId('link-activity-top').textContent).toContain('/l/abc2345')
    expect(document.body.textContent).not.toMatch(/unique visitors/i)
    expect(document.body.textContent).toContain('never added across days')
  })

  it('renders in Arabic', async () => {
    vi.mocked(client.getData).mockResolvedValue(summary())
    renderWithProviders(<LinkActivityWidget projectId="p" range={range} />, { locale: 'ar' })

    expect(await screen.findByText('فتحات بشرية')).toBeTruthy()
    expect(screen.getByTestId('chart-donut').getAttribute('data-slices')).toContain('واتساب:9')
  })
})

describe('AttributionCreditWidget', () => {
  it('shows the model choice, not a chart, until a project has chosen', async () => {
    vi.mocked(client.getData).mockResolvedValue({ model_comparison: {} })
    renderWithProviders(<AttributionCreditWidget projectId="p" range={range} />, { locale: 'en' })

    expect(await screen.findByTestId('attribution-settings')).toBeTruthy()
    expect(kind()).toBe('missing')
    expect(screen.queryByTestId('attribution-credit')).toBeNull()
  })

  it('draws unattributed orders as their own bar and slice, and switches models', async () => {
    vi.mocked(client.getData).mockResolvedValue(credit())
    renderWithProviders(<AttributionCreditWidget projectId="p" range={range} />, { locale: 'en' })

    expect(await screen.findByTestId('attribution-credit')).toBeTruthy()
    expect(screen.getByTestId('chart-bars').getAttribute('data-rows')).toBe('Smart links:2|Influencers:1|Unattributed:2')
    expect(screen.getByTestId('chart-donut').getAttribute('data-slices')).toBe('Verified evidence:3|No evidence:2')
    expect(kind()).toBe('measured')

    fireEvent.click(screen.getByTestId('attribution-model-last_click'))
    expect(screen.getByTestId('chart-bars').getAttribute('data-rows')).toBe('Smart links:3|Unattributed:2')
    expect(screen.getByTestId('attribution-model-last_click').getAttribute('aria-pressed')).toBe('true')
  })

  it('states what it excluded and withheld, and that a click is not a purchase', async () => {
    vi.mocked(client.getData).mockResolvedValue(credit())
    renderWithProviders(<AttributionCreditWidget projectId="p" range={range} />, { locale: 'en' })

    const notes = (await screen.findByTestId('attribution-credit-notes')).textContent ?? ''
    expect(notes).toContain('Fully refunded orders not shared out: 1')
    expect(notes).toContain('Orders with revenue withheld (no trustworthy exchange rate): 1')
    expect(notes).toContain('A click is not a purchase')
  })

  it('is labelled unattributed, never measured, when no order has verified evidence', async () => {
    vi.mocked(client.getData).mockResolvedValue(credit({
      coverage: { orders_credited: 2, orders_with_verified_touchpoints: 0, orders_without_evidence: 2, orders_excluded_fully_refunded: 0, orders_with_withheld_revenue: 0, share_with_verified_touchpoints: 0 },
      channels: [{ channel: 'unattributed', kind: 'unattributed', models: { linear: { orders: 2, revenue: 300 } } }],
    }))
    renderWithProviders(<AttributionCreditWidget projectId="p" range={range} />, { locale: 'en' })

    await screen.findByTestId('attribution-credit')
    expect(kind()).toBe('unattributed')
    expect(screen.getByTestId('chart-bars').getAttribute('data-rows')).toBe('Unattributed:2')
  })

  it('saves the chosen models through the settings endpoint', async () => {
    vi.mocked(client.getData).mockResolvedValue({ model_comparison: {} })
    vi.mocked(client.putData).mockResolvedValue({})
    renderWithProviders(<AttributionCreditWidget projectId="p" range={range} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('attribution-choose-time_decay'))
    fireEvent.click(screen.getByTestId('attribution-save'))

    await waitFor(() => expect(client.putData).toHaveBeenCalledWith('/projects/p/attribution-settings', { models: ['last_click', 'linear', 'time_decay'], lookback_days: 30 }))
  })
})
