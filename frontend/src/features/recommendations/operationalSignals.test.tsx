import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
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

import { listRecommendations } from './api'
import { listAlertEvents } from '@/features/alerts/api'
import { getCreativePulse } from '@/features/content/pulse'
import { useSpendLimits } from '@/features/budget/spendLimitsApi'

/**
 * RECOMMENDATIONS-ACTION-CENTER-002 — an account with no written notes still has things to do.
 *
 * The page listed recommendations somebody had WRITTEN, so an account where nobody had written one
 * showed nothing at all — while the product knew, at that moment, that a budget was breached, a
 * creative had fatigued and a token was about to expire. Three engines, three surfaces, and the page
 * named «what should I do» showed none of them.
 *
 * The load-bearing case is the last one: each source fails independently. An action centre that
 * shows nothing because one of three sidecars is unreachable is worse than one showing two of them.
 */
const limit = {
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'monitor_only', amount: 1000,
  currency: 'SAR', period: { from: '2026-07-01', to: '2026-07-31', days: 31 }, elapsed_days: 20,
  consumed: 1200, consumed_currency: 'SAR', remaining: -200, utilisation: 1.2, pace: 1.8,
  projected_period_spend: 1800, projected_exhaustion: { date: null, reason: 'already_reached' },
  thresholds: [80], state: 'over', basis: null,
}

const setSources = (over: {
  alerts?: unknown[]
  limits?: unknown[]
  fatigued?: unknown[]
} = {}) => {
  vi.mocked(listRecommendations).mockResolvedValue([] as never)
  vi.mocked(listAlertEvents).mockResolvedValue({ events: over.alerts ?? [], total: 0, counts: {} } as never)
  vi.mocked(useSpendLimits).mockReturnValue({ data: { limits: over.limits ?? [] } } as never)
  vi.mocked(getCreativePulse).mockResolvedValue({ fatigue: { fatigued: { items: over.fatigued ?? [] } } } as never)
}

describe('what the product noticed, on the page named for it', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' } as never)
    signInWith(['campaigns.view', 'reports.approve'])
  })
  afterEach(() => signOut())

  it('shows a breached spend limit even when nobody has written a recommendation', async () => {
    setSources({ limits: [limit] })

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    const signal = await screen.findByTestId('signal-budget')
    expect(signal).toHaveAttribute('data-severity', 'critical')
    expect(signal).toHaveTextContent(/past an internal spend limit/i)
  })

  /** The money carries its unit — the governor's own currency, never a bare number. */
  it('states the limit in the currency the governor read it in', async () => {
    setSources({ limits: [limit] })

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('signal-budget')).toHaveTextContent('1,000 SAR')
  })

  /**
   * And it says these are DERIVED, once and plainly.
   *
   * A reader who takes a signal for somebody's recommendation will act on it with more confidence
   * than the evidence carries.
   */
  it('does not present a derived signal as advice somebody gave', async () => {
    setSources({ limits: [limit] })

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    await screen.findByTestId('signal-budget')
    expect(screen.getByTestId('operational-signals')).toHaveTextContent(/not recommendations anybody wrote/i)
  })

  it('shows a fatigued creative with the assessor’s own sentence', async () => {
    setSources({
      fatigued: [{ id: 'c1', name: 'Eid film', fatigue: { status: 'fatigued', signals: [], reason_ar: 'س', reason_en: 'CTR has halved over ten days' } }],
    })

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('signal-creative')).toHaveTextContent('CTR has halved over ten days')
  })

  /** Nothing noticed is nothing shown — not an empty heading over a blank list. */
  it('draws no signals block when every engine is quiet', async () => {
    setSources()

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    await screen.findByTestId('recommendations-page-ready').catch(() => null)
    expect(screen.queryByTestId('operational-signals')).toBeNull()
  })

  /**
   * One source down must not take the others with it.
   *
   * These are a sidecar to the list below; a page that fails to render because a signal source is
   * unreachable is a worse outcome than a page missing a signal.
   */
  it('still shows what it can when one source is unreachable', async () => {
    vi.mocked(listRecommendations).mockResolvedValue([] as never)
    vi.mocked(listAlertEvents).mockRejectedValue(new Error('offline'))
    vi.mocked(useSpendLimits).mockReturnValue({ data: { limits: [limit] } } as never)
    vi.mocked(getCreativePulse).mockRejectedValue(new Error('offline'))

    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('signal-budget')).toBeVisible()
  })
})
