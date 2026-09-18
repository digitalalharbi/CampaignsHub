import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { RecommendationsPage } from './RecommendationsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listRecommendations: vi.fn(),
  setRecommendationStatus: vi.fn(),
}))
vi.mock('@/features/alerts/api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listAlertEvents: vi.fn(),
}))
vi.mock('@/features/content/pulse', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  getCreativePulse: vi.fn(),
}))
vi.mock('@/features/budget/spendLimitsApi', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  useSpendLimits: vi.fn(),
}))
vi.mock('@/features/analytics/api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  useSummary: vi.fn(),
  useCampaigns: vi.fn(),
  useTimeseries: vi.fn(),
}))

import { listRecommendations } from './api'
import { listAlertEvents } from '@/features/alerts/api'
import { getCreativePulse } from '@/features/content/pulse'
import { useSpendLimits } from '@/features/budget/spendLimitsApi'
import { useCampaigns, useSummary, useTimeseries } from '@/features/analytics/api'

/**
 * RECOMMENDATIONS-VISUAL-001 — the action centre is figures, not sentences.
 *
 * Each card must show how serious, what kind, what it is about, the figures before and now, the
 * impact where one can be stated, one action and the way to the evidence. And the page must tell a
 * reader the difference between «nothing to do», «still reading» and «could not read».
 */
const CAMPAIGN = 'App\\Domains\\Campaigns\\Models\\UnifiedCampaign'

const limit = {
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'internal_monitoring', amount: 1000,
  currency: 'SAR', period: { from: '2026-09-01', to: '2026-09-30', days: 30 }, elapsed_days: 20,
  consumed: 1200, consumed_currency: 'SAR', remaining: -200, utilisation: 1.2, pace: 1.8,
  projected_period_spend: 1800, projected_exhaustion: { date: null, reason: 'already_reached' },
  thresholds: [80], state: 'over', basis: 'comparable',
}

const roasDrop = {
  id: 'a1', project_id: 'p1', rule_id: 'r', type: 'roas_drop', entity_type: CAMPAIGN, entity_id: 'c1',
  status: 'open', severity: 'critical', context: { roas_previous: 4, roas_current: 2 },
  notification_id: null, task_id: null, last_triggered_at: null, snoozed_until: null, resolved_at: null, created_at: null,
}

const days = (n: number) => Array.from({ length: n }, (_, i) => ({ date: `2026-09-${String(i + 1).padStart(2, '0')}`, roas: 4 - i * 0.1 }))

function setSources(over: { alerts?: unknown[]; limits?: unknown[]; pulse?: unknown; series?: unknown[] } = {}) {
  vi.mocked(listRecommendations).mockResolvedValue([] as never)
  vi.mocked(listAlertEvents).mockResolvedValue({ events: over.alerts ?? [], total: 0, counts: {} } as never)
  vi.mocked(useSpendLimits).mockReturnValue({ data: { limits: over.limits ?? [] }, isLoading: false, isError: false } as never)
  vi.mocked(getCreativePulse).mockResolvedValue((over.pulse ?? { currency: 'SAR', fatigue: { fatigued: { items: [] }, alerts: { items: [] } }, fastest_growing: { items: [] }, declining: { items: [] } }) as never)
  vi.mocked(useSummary).mockReturnValue({ data: { currency: 'SAR' } } as never)
  vi.mocked(useCampaigns).mockReturnValue({ data: [{ campaign_id: 'c1', campaign_name: 'Riyadh launch', provider: 'meta' }] } as never)
  vi.mocked(useTimeseries).mockReturnValue({ data: over.series ?? days(14), isLoading: false } as never)
}

describe('a finding card carries its figures, its action and its evidence', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' } as never)
    signInWith(['campaigns.view', 'reports.approve'])
  })
  afterEach(() => signOut())

  it('shows severity, nature, subject, before and current, a trend and the evidence link', async () => {
    setSources({ alerts: [roasDrop] })
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    const card = await screen.findByTestId('finding-alert:a1')
    expect(card).toHaveAttribute('data-severity', 'critical')
    expect(within(card).getByTestId('finding-nature')).toHaveTextContent('Problem')
    expect(within(card).getByTestId('finding-subject')).toHaveTextContent('Riyadh launch')
    const roas = within(card).getByTestId('kpi-roas')
    /* The catalogue's name, never the raw key a reader cannot parse. */
    expect(roas).toHaveTextContent('Return on ad spend')
    expect(roas).not.toHaveTextContent(/^roas/)
    expect(within(roas).getByTestId('kpi-current')).toHaveTextContent('2.00×')
    expect(within(roas).getByTestId('kpi-before')).toHaveTextContent('4.00×')
    expect(within(roas).getByTestId('kpi-change')).toHaveTextContent('50%')
    expect(within(card).getByTestId('finding-trend')).toBeInTheDocument()
    expect(within(card).getByTestId('finding-action')).toHaveTextContent(/review targeting/i)
    expect(within(card).getByTestId('finding-evidence')).toHaveAttribute('href', expect.stringContaining('/campaigns/p1/c1'))
  })

  it('states a measurable impact with its currency and its basis', async () => {
    setSources({ limits: [limit] })
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    const impact = await screen.findByTestId('finding-impact')
    expect(impact).toHaveTextContent('200 SAR')
    expect(impact).toHaveTextContent(/in the limit’s currency/i)
  })

  it('draws no trend line through fewer than two days', async () => {
    setSources({ alerts: [roasDrop], series: days(1) })
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    const card = await screen.findByTestId('finding-alert:a1')
    expect(within(card).queryByTestId('finding-trend')).toBeNull()
  })

  it('filters the queue to opportunities', async () => {
    setSources({
      alerts: [roasDrop, { ...roasDrop, id: 'a2', severity: 'info', type: 'metric_anomaly', context: { points: [{ date: '2026-09-14', metric: 'conversions', value: 90, baseline: 40, deviation: 4, direction: 'up' }] } }],
    })
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    await screen.findByTestId('finding-alert:a1')
    expect(screen.getByTestId('summary-opportunity')).toHaveTextContent('1')
    fireEvent.click(within(screen.getByTestId('action-center-nature')).getByRole('tab', { name: /opportunity/i }))
    expect(screen.queryByTestId('finding-alert:a1')).toBeNull()
    expect(screen.getByTestId('finding-alert:a2')).toHaveAttribute('data-nature', 'opportunity')
  })

  it('renders Arabic headlines and actions', async () => {
    setSources({ alerts: [roasDrop] })
    renderWithProviders(<RecommendationsPage />, { locale: 'ar' })

    const card = await screen.findByTestId('finding-alert:a1')
    expect(card).toHaveTextContent('تراجع العائد على الإنفاق')
    expect(within(card).getByTestId('finding-nature')).toHaveTextContent('مشكلة')
  })
})

describe('empty, loading and failed are three different screens', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' } as never)
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('says there is nothing to decide, and names what it checked, rather than drawing a fake card', async () => {
    setSources()
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    const empty = await screen.findByTestId('action-center-empty')
    expect(empty).toHaveTextContent(/nothing needs a decision/i)
    expect(empty).toHaveTextContent(/alerts, spend limits, creative performance/i)
    expect(screen.queryByTestId('action-center-list')).toBeNull()
  })

  it('does not call a failed source an empty one', async () => {
    setSources({ limits: [limit] })
    vi.mocked(listAlertEvents).mockRejectedValue(new Error('offline'))
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('finding-budget:l1')).toBeVisible()
    expect(await screen.findByTestId('action-center-degraded')).toHaveTextContent(/alerts/i)
  })

  it('shows a skeleton, not an empty state, while the sources are still being read', () => {
    setSources()
    vi.mocked(listAlertEvents).mockReturnValue(new Promise(() => {}) as never)
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(screen.getByTestId('action-center-loading')).toBeInTheDocument()
    expect(screen.queryByTestId('action-center-empty')).toBeNull()
  })
})
