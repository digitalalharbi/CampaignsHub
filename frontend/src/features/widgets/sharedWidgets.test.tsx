import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import * as client from '@/lib/api/client'
import { SharedWidgetsSection } from './SharedWidgetsSection'
import { ShareWidgetsPanel } from './ShareWidgetsPanel'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof client>()), getData: vi.fn(), putData: vi.fn() }))
vi.mock('@/features/analytics/charts', () => ({
  ChartCard: ({ title, subtitle, action, children }: { title: string; subtitle?: string; action?: React.ReactNode; children: React.ReactNode }) => (
    <section><h3>{title}</h3>{subtitle && <p>{subtitle}</p>}{action}{children}</section>
  ),
  MetricLineChart: ({ data }: { data: unknown[] }) => <div data-testid="chart-line" data-points={data.length} />,
  PlatformDonutChart: ({ data }: { data: Array<{ name: string; value: number }> }) => <div data-testid="chart-donut" data-slices={data.map((d) => `${d.name}:${d.value}`).join('|')} />,
  RankingBarChart: ({ data }: { data: Array<{ label: string; orders: number }> }) => <div data-testid="chart-bars" data-rows={data.map((d) => `${d.label}:${d.orders}`).join('|')} />,
}))

const linkOpens = {
  key: 'link_opens', kind: 'trend', size: 'full', title: { en: 'Link activity', ar: 'نشاط الروابط' },
  data: {
    state: 'measured', period: { from: '2026-10-14', to: '2026-10-21', timezone: 'Asia/Riyadh' }, tracking_since: '2026-10-18', opens: 12,
    series: [{ period: '2026-10-18', opens: 5 }, { period: '2026-10-19', opens: 7 }], by_destination: { external_url: 12 },
    basis: { counts: 'recorded_events', includes_sales: false },
  },
}
const attribution = {
  key: 'attribution_credit', kind: 'bars', size: 'half', title: { en: 'Attribution by channel', ar: 'الإسناد حسب القناة' },
  data: {
    version: 'v', evidence_basis: 'verified_marketing_touchpoints', models: ['linear'], lookback_days: 30,
    coverage: { orders_credited: 3, orders_with_verified_touchpoints: 2, orders_without_evidence: 1, orders_excluded_fully_refunded: 0, orders_with_withheld_revenue: 0, share_with_verified_touchpoints: 0.67 },
    channels: [{ channel: 'smart_link', kind: 'other', models: { linear: { orders: 2 } } }, { channel: 'unattributed', kind: 'unattributed', models: { linear: { orders: 1 } } }],
  },
}

const answer = (body: unknown, ok = true) => vi.fn().mockResolvedValue({ ok, json: async () => body })

describe('SharedWidgetsSection (what a client sees)', () => {
  const realFetch = globalThis.fetch
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => { globalThis.fetch = realFetch })

  it('draws nothing for a link with no widgets, for a refusal, and for a failed request', async () => {
    for (const [body, ok] of [[{ data: { widgets: [] } }, true], [{ message: 'no' }, false]] as const) {
      globalThis.fetch = answer(body, ok) as never
      const { container, unmount } = renderWithProviders(<SharedWidgetsSection token={`t-${String(ok)}`} />, { locale: 'en' })
      await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled())
      expect(container.querySelector('[data-testid="shared-widgets"]')).toBeNull()
      expect(document.body.textContent).not.toMatch(/error|unavailable/i)
      unmount()
    }
  })

  it('draws the widgets it is given, in order and width, and sends the link password', async () => {
    globalThis.fetch = answer({ data: { widgets: [linkOpens, attribution] } }) as never
    renderWithProviders(<SharedWidgetsSection token="tok" password="open-sesame" />, { locale: 'en' })

    expect(await screen.findByTestId('shared-widget-link_opens')).toBeTruthy()
    expect(screen.getByTestId('shared-widget-link_opens').className).toContain('lg:col-span-6')
    expect(screen.getByTestId('shared-widget-attribution_credit').className).toContain('lg:col-span-3')
    expect(screen.getByTestId('chart-bars').getAttribute('data-rows')).toBe('Smart links:2|Unattributed:1')
    expect(vi.mocked(globalThis.fetch).mock.calls[0][0]).toBe('/api/v1/reports/shared/tok/widgets')
    expect((vi.mocked(globalThis.fetch).mock.calls[0][1] as { headers: Record<string, string> }).headers['X-Report-Password']).toBe('open-sesame')
  })

  it('shows a client no agency diagnostics, no settings panel and no money', async () => {
    globalThis.fetch = answer({ data: { widgets: [linkOpens, attribution] } }) as never
    renderWithProviders(<SharedWidgetsSection token="tok" />, { locale: 'en' })

    await screen.findByTestId('shared-widget-link_opens')
    const text = document.body.textContent ?? ''
    expect(text).not.toMatch(/Machine opens|Devices per day|never added across days/)
    expect(screen.queryByTestId('link-activity-machines')).toBeNull()
    expect(screen.queryByTestId('link-activity-top')).toBeNull()
    expect(screen.queryByTestId('attribution-settings')).toBeNull()
    expect(text).toContain('not sales')
    expect(text).toContain('A click is not a purchase')
    expect(text).not.toMatch(/SAR|USD|\$|revenue/i)
  })

  it('renders in Arabic', async () => {
    globalThis.fetch = answer({ data: { widgets: [linkOpens] } }) as never
    renderWithProviders(<SharedWidgetsSection token="tok" />, { locale: 'ar' })

    expect(await screen.findByText('نشاط الروابط')).toBeTruthy()
    expect(screen.getByText('مرات فتح الروابط')).toBeTruthy()
  })
})

describe('ShareWidgetsPanel (what the link owner switches)', () => {
  beforeEach(() => vi.clearAllMocks())

  const layout = (widgets: unknown[]) => ({ surface: 'report', sizes: ['third', 'half', 'full'], widgets })
  const w = (over: Record<string, unknown>) => ({ key: 'link_opens', visible: false, order: 10, size: 'full', kind: 'trend', module: 'advanced_tracking', reason: 'hidden_by_link', title: { en: 'Link activity', ar: 'نشاط الروابط' }, ...over })

  it('draws nothing when no widget module is on', async () => {
    vi.mocked(client.getData).mockResolvedValue(layout([w({ reason: 'module_off' })]))
    const { container } = renderWithProviders(<ShareWidgetsPanel projectId="p" reportId="r" shareId="s" />, { locale: 'en' })

    await waitFor(() => expect(client.getData).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="share-widgets"]')).toBeNull()
  })

  it('shows them off by default, switches one on for this link only, and locks one the project forbids', async () => {
    vi.mocked(client.getData).mockResolvedValue(layout([w({}), w({ key: 'attribution_credit', module: 'attribution_intelligence', reason: 'hidden_by_project', title: { en: 'Attribution by channel', ar: 'الإسناد حسب القناة' } })]))
    vi.mocked(client.putData).mockResolvedValue(layout([]))
    renderWithProviders(<ShareWidgetsPanel projectId="p" reportId="r" shareId="s" />, { locale: 'en' })

    expect((await screen.findByTestId('share-widget-switch-link_opens')).getAttribute('aria-checked')).toBe('false')
    expect((screen.getByTestId('share-widget-switch-attribution_credit') as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByTestId('share-widget-row-attribution_credit').textContent).toContain('The project does not allow this on client links')

    fireEvent.click(screen.getByTestId('share-widget-switch-link_opens'))
    await waitFor(() => expect(client.putData).toHaveBeenCalledWith('/projects/p/reports/r/shares/s/widgets', {
      items: [
        { key: 'link_opens', visible: true, order: 10, size: 'full' },
        { key: 'attribution_credit', visible: false, order: 20, size: 'full' },
      ],
    }))
  })
})
