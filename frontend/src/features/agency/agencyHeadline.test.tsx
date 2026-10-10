import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AgencyAlerts, AgencyHeadline, AgencyTrendAndPlatforms } from './AgencyHeadline'
import type { AgencyOverview } from './api'
import type { AlertEvent } from '@/features/alerts/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({ fetchAgencyOverview: vi.fn() }))
vi.mock('@/features/alerts/api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listAlertEvents: vi.fn() }))
import { fetchAgencyOverview } from './api'
import { listAlertEvents } from '@/features/alerts/api'

/**
 * DASHBOARD-FIRST-SCREEN-001 — the first screen states money, results, cost and movement under the
 * money and coverage contracts, and opens the surface that answers each in depth.
 */
const daysBetween = (from: string, to: string) => Math.round((Date.parse(to) - Date.parse(from)) / 86400000) + 1
const PROVIDERS = ['meta', 'google']
const complete = { state: 'complete', expected_contributors: PROVIDERS, included_contributors: PROVIDERS, excluded_contributors: [], partial_contributors: [], reported_through: {} }
const truncated = { state: 'partial', expected_contributors: PROVIDERS, included_contributors: [], excluded_contributors: PROVIDERS, partial_contributors: PROVIDERS, reported_through: { meta: '2026-09-27', google: '2026-09-27' } }

const totals = (spend: number, conversions: number, coverage: object) => ({
  spend, revenue: spend * 4, conversions, clicks: 1000, impressions: 50000, cpa: spend / conversions, roas: 4,
  spend_withheld_rows: 0, spend_original: 0, revenue_withheld_rows: 0, revenue_original: 0, money_original_currencies: 0, money_original_currency: null,
  coverage, spend_coverage: coverage, revenue_coverage: coverage,
})

const overview = (over: Partial<AgencyOverview> = {}): AgencyOverview => ({
  scope: { client_count: 2, project_count: 3, is_restricted: false },
  period: { from: '2026-09-11', to: '2026-10-10' },
  previous_period: { from: '2026-08-12', to: '2026-09-10' },
  currency: 'SAR',
  current: totals(61_200, 741, complete),
  previous: totals(50_000, 600, complete),
  by_provider: [
    { provider: 'google', ...totals(24_700, 300, complete) },
    { provider: 'meta', ...totals(21_900, 441, complete) },
  ],
  timeseries: [{ date: '2026-09-11', spend: 2000, revenue: 8000 }, { date: '2026-09-12', spend: 2100, revenue: 8300 }],
  freshness: { last_synced_at: '2026-10-09T20:03:00Z' },
  ...over,
})

const alert = (id: string, severity: AlertEvent['severity'], type = 'budget_risk'): AlertEvent => ({
  id, project_id: 'p1', rule_id: 'r', type, entity_type: 'App\\Domains\\Campaigns\\Models\\UnifiedCampaign', entity_id: 'c1',
  status: 'open', severity, context: {}, notification_id: null, task_id: null, last_triggered_at: null, snoozed_until: null, resolved_at: null, created_at: '2026-10-09T00:00:00Z',
})

describe('the agency headline', () => {
  beforeEach(() => {
    signInWith(['clients.view'])
    vi.mocked(listAlertEvents).mockResolvedValue({ events: [], total: 0, counts: { open: 0, snoozed: 0, resolved: 0, open_critical: 0 } })
  })
  afterEach(() => signOut())

  it('states spend, results, cost and ROAS in the window’s currency, with movement when both windows are whole', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview())
    renderWithProviders(<AgencyHeadline ar={false} />, { locale: 'en' })
    const spend = await screen.findByTestId('agency-kpi-spend')
    expect(spend).toHaveTextContent('61.2K SAR')
    expect(screen.getByTestId('agency-kpi-results')).toHaveTextContent('741')
    expect(screen.getByTestId('agency-kpi-cpa')).toHaveTextContent('SAR')
    expect(screen.getByTestId('agency-kpi-roas')).toHaveTextContent('4.00×')
    // Movement against the previous window, shown because both windows are complete.
    expect(within(spend).getByText(/22/)).toBeInTheDocument()
    expect(screen.queryByTestId('agency-coverage-note')).not.toBeInTheDocument()
    expect(screen.getByTestId('agency-freshness')).toBeInTheDocument()
  })

  it('withholds the movement and says why when the current window stopped short', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview({ current: totals(40_000, 500, truncated) }))
    renderWithProviders(<AgencyHeadline ar={false} />, { locale: 'en' })
    const spend = await screen.findByTestId('agency-kpi-spend')
    expect(spend).toHaveTextContent('40K SAR')
    expect(within(spend).queryByText(/%/)).not.toBeInTheDocument()
    expect(screen.getByTestId('agency-coverage-note')).toHaveTextContent('2026-09-27')
  })

  it('prints bare figures and says so when the rows name no single currency', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview({ currency: null }))
    renderWithProviders(<AgencyHeadline ar={false} />, { locale: 'en' })
    const spend = await screen.findByTestId('agency-kpi-spend')
    expect(spend).not.toHaveTextContent('SAR')
    expect(screen.getByTestId('agency-currency-note')).toBeInTheDocument()
  })

  it('ranks the platforms and links each to the library filtered to it', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview())
    renderWithProviders(<AgencyTrendAndPlatforms ar={false} />, { locale: 'en' })
    const google = await screen.findByTestId('agency-platform-google')
    expect(google).toHaveAttribute('href', '/agency/content?providers=google')
    expect(screen.getByTestId('agency-platforms')).toHaveTextContent('24.7K SAR')
  })

  it('lists the open alerts most severe first, each opening its campaign', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview())
    vi.mocked(listAlertEvents).mockResolvedValue({ events: [alert('a1', 'info'), alert('a2', 'critical'), alert('a3', 'warning')], total: 3, counts: { open: 3, snoozed: 0, resolved: 0, open_critical: 1 } })
    renderWithProviders(<AgencyAlerts ar={false} />, { locale: 'en' })
    await screen.findByTestId('agency-alert-a2')
    const list = screen.getByTestId('agency-alerts')
    const links = within(list).getAllByRole('link').filter((l) => l.getAttribute('data-testid')?.startsWith('agency-alert-'))
    expect(links.map((l) => l.getAttribute('data-testid'))).toEqual(['agency-alert-a2', 'agency-alert-a3', 'agency-alert-a1'])
    expect(links[0]).toHaveAttribute('href', '/agency/campaigns/p1/c1')
  })

  it('lets the operator choose the window, and asks the server for exactly that window', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview())
    renderWithProviders(<AgencyHeadline ar={false} />, { route: '/agency/dashboard', locale: 'en' })
    await screen.findByTestId('agency-kpi-spend')
    const first = vi.mocked(fetchAgencyOverview).mock.calls[0]![0] as { from: string; to: string }
    expect(daysBetween(first.from, first.to)).toBe(30)
    fireEvent.click(screen.getByRole('button', { name: '7 days' }))
    await waitFor(() => expect(vi.mocked(fetchAgencyOverview).mock.calls.length).toBeGreaterThan(1))
    const last = vi.mocked(fetchAgencyOverview).mock.calls.at(-1)![0] as { from: string; to: string }
    expect(daysBetween(last.from, last.to)).toBe(7)
  })

  it('says there is nothing to read when the operator reaches no project', async () => {
    vi.mocked(fetchAgencyOverview).mockResolvedValue(overview({ current: null, previous: null, by_provider: [], timeseries: [], currency: null }))
    renderWithProviders(<><AgencyHeadline ar={false} /><AgencyTrendAndPlatforms ar={false} /></>, { locale: 'en' })
    expect(await screen.findByTestId('agency-headline-empty')).toBeInTheDocument()
    expect(await screen.findByTestId('agency-trend-empty')).toBeInTheDocument()
  })
})
