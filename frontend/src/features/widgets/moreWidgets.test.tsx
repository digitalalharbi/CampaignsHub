import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import * as client from '@/lib/api/client'
import { useProject } from '@/stores/project'
import { ReportViewsWidget } from './ReportViewsWidget'
import { CommerceOutcomesWidget } from './CommerceOutcomesWidget'
import { WidgetsTab } from '@/features/settings/tabs/WidgetsTab'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof client>()), getData: vi.fn(), putData: vi.fn() }))
vi.mock('@/features/analytics/charts', () => ({
  ChartCard: ({ title, subtitle, action, children }: { title: string; subtitle?: string; action?: React.ReactNode; children: React.ReactNode }) => (
    <section><h3>{title}</h3>{subtitle && <p data-testid="card-subtitle">{subtitle}</p>}{action}{children}</section>
  ),
  MetricLineChart: ({ data }: { data: unknown[] }) => <div data-testid="chart-line" data-points={data.length} />,
  PlatformDonutChart: ({ data }: { data: Array<{ name: string; value: number }> }) => <div data-testid="chart-donut" data-slices={data.map((d) => `${d.name}:${d.value}`).join('|')} />,
  RankingBarChart: ({ data }: { data: Array<{ label: string; revenue?: number }> }) => <div data-testid="chart-bars" data-rows={data.map((d) => `${d.label}:${d.revenue}`).join('|')} />,
}))

const range = { from: '2026-10-14', to: '2026-10-21' }
const kind = () => document.querySelector('[data-kind]')?.getAttribute('data-kind')

beforeEach(() => vi.clearAllMocks())

describe('ReportViewsWidget', () => {
  const views = (over: Record<string, unknown> = {}) => ({
    state: 'measured', period: { from: '2026-10-14', to: '2026-10-21', timezone: 'Asia/Riyadh' }, opens: 9, machine_opens: 4, last_opened_at: '2026-10-20T09:00:00Z',
    series: [{ period: '2026-10-19', opens: 4 }, { period: '2026-10-20', opens: 5 }],
    top_reports: [{ id: 'r1', name: 'October report', opens: 9, last_opened_at: '2026-10-20T09:00:00Z' }],
    basis: { counts: 'opens_not_people', machines_excluded: true }, ...over,
  })

  it('says no link has been opened rather than drawing a zero', async () => {
    vi.mocked(client.getData).mockResolvedValue(views({ state: 'no_views', opens: 0, machine_opens: 0, series: [], top_reports: [], last_opened_at: null }))
    renderWithProviders(<ReportViewsWidget projectId="p" range={range} />, { locale: 'en' })

    expect(await screen.findByText('No report link has been opened yet')).toBeTruthy()
    expect(kind()).toBe('missing')
    expect(screen.queryByTestId('report-views')).toBeNull()
  })

  it('shows opens, the machine opens left out, the trend and the top reports — and never visitors', async () => {
    vi.mocked(client.getData).mockResolvedValue(views())
    renderWithProviders(<ReportViewsWidget projectId="p" range={range} />, { locale: 'en' })

    expect((await screen.findByTestId('report-views-opens')).textContent).toBe('9')
    expect(screen.getByTestId('report-views-machines').textContent).toBe('4')
    expect(kind()).toBe('measured')
    expect(screen.getByTestId('chart-line').getAttribute('data-points')).toBe('2')
    expect(screen.getByTestId('report-views-top').textContent).toContain('October report')
    expect(screen.getByTestId('card-subtitle').textContent).toContain('Opens, not people')
    expect(document.body.textContent).not.toMatch(/visitors/i)
  })

  it('renders in Arabic', async () => {
    vi.mocked(client.getData).mockResolvedValue(views())
    renderWithProviders(<ReportViewsWidget projectId="p" range={range} />, { locale: 'ar' })

    await screen.findByTestId('report-views-opens')
    expect(screen.getByText('فتح روابط التقارير')).toBeTruthy()
    expect(screen.getByTestId('report-views-machines').previousElementSibling?.textContent).toMatch(/مستبعدة/)
  })
})

describe('CommerceOutcomesWidget', () => {
  const funnel = (over: Record<string, unknown> = {}) => ({
    totals: { reporting_currency: 'USD', revenue: 4200, refunded: 100, cancelled_orders: 2, orders: 30, attributed_orders: 18, unattributed_orders: 12 },
    derived: { aov: 140 },
    comparisons: { products: [{ name: 'Abaya', quantity: 10, revenue: 2000 }, { name: 'Scarf', quantity: 5, revenue: 900 }] },
    coverage: { stores: 1, orders_with_money_withheld: 0, orders_in_window: 30 },
    ...over,
  })

  it('says no store is connected instead of drawing zeros, and does not call it measured', async () => {
    vi.mocked(client.getData).mockResolvedValue(funnel({ coverage: { stores: 0, orders_with_money_withheld: 0, orders_in_window: 0 } }))
    renderWithProviders(<CommerceOutcomesWidget projectId="p" range={range} />, { locale: 'en' })

    expect(await screen.findByText('No store is connected to this project')).toBeTruthy()
    expect(kind()).toBe('missing')
    expect(screen.queryByTestId('commerce-outcomes')).toBeNull()
  })

  it('shows orders, net revenue in the reporting currency, the unattributed share as its own slice, and the top products', async () => {
    vi.mocked(client.getData).mockResolvedValue(funnel())
    renderWithProviders(<CommerceOutcomesWidget projectId="p" range={range} />, { locale: 'en' })

    expect((await screen.findByTestId('commerce-orders')).textContent).toBe('30')
    expect(screen.getByTestId('commerce-revenue').textContent).toContain('4')
    expect(screen.getByTestId('commerce-revenue').textContent).toMatch(/USD|\$/)
    expect(screen.getByTestId('chart-donut').getAttribute('data-slices')).toBe('Tied to a platform:18|Unattributed:12')
    expect(screen.getByTestId('chart-bars').getAttribute('data-rows')).toBe('Abaya:2000|Scarf:900')
    expect(kind()).toBe('measured')
  })

  it('states the orders whose revenue is withheld, because a short total looks like a complete one', async () => {
    vi.mocked(client.getData).mockResolvedValue(funnel({ coverage: { stores: 1, orders_with_money_withheld: 3, orders_in_window: 30 } }))
    renderWithProviders(<CommerceOutcomesWidget projectId="p" range={range} />, { locale: 'en' })

    expect((await screen.findByTestId('commerce-withheld')).textContent).toContain('3 order(s) have revenue withheld')
  })
})

describe('Settings → Widgets', () => {
  const layout = (widgets: unknown[]) => ({ surface: 'dashboard', sizes: ['third', 'half', 'full'], widgets })
  const w = (over: Record<string, unknown>) => ({ key: 'report_views', visible: false, order: 60, size: 'half', kind: 'trend', module: null, reason: 'hidden_by_project', title: { en: 'Report link opens', ar: 'فتح روابط التقارير' }, ...over })

  it('asks for a project first', () => {
    useProject.getState().setCurrentProjectId(null)
    renderWithProviders(<WidgetsTab />, { locale: 'en' })
    expect(screen.getByText('Choose a project')).toBeTruthy()
  })

  it('lets the owner choose a core widget, which is how an existing project opts in', async () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(client.getData).mockResolvedValue(layout([w({})]))
    vi.mocked(client.putData).mockResolvedValue(layout([]))
    renderWithProviders(<WidgetsTab />, { locale: 'en' })

    expect(await screen.findByTestId('widget-row-report_views')).toBeTruthy()
    fireEvent.click(screen.getByTestId('widget-switch-report_views'))
    fireEvent.click(screen.getByTestId('widgets-save'))
    await waitFor(() => expect(client.putData).toHaveBeenCalledWith('/projects/p1/widgets/dashboard', { items: [{ key: 'report_views', visible: true, order: 10, size: 'half' }] }))
  })

  it('words the client-link surface as what the project ALLOWS', async () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(client.getData).mockResolvedValue(layout([w({ visible: true, reason: null })]))
    renderWithProviders(<WidgetsTab />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('widgets-surface-report'))
    expect(await screen.findByText(/What this project ALLOWS on a client link/)).toBeTruthy()
    expect(await screen.findByText('Allow on client links')).toBeTruthy()
  })
})
