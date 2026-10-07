import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { SpendLimitsPage } from './SpendLimitsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn(),
  postData: vi.fn(),
  deleteData: vi.fn(),
}))

import { getEnvelope } from '@/lib/api/client'

/**
 * VIZ-BUDGET-001 — «which of these is running hot», as a shape.
 *
 * The page answers «how is this limit doing» one card at a time, and a workspace with nine limits is
 * nine cards to scan and a pace figure on each to compare in your head against 1.0. That is the
 * comparison `RatioBars` was written for: a line drawn once at 1.0, every limit ranked against it,
 * worst first.
 *
 * ## Pace is `consumed ÷ expected-by-now`, which is why it may cross currencies
 *
 * It is not «how much of the limit is gone» — that is `utilisation`. It is whether the spend is ahead
 * of the clock, against the ELAPSED share of the limit. Being unitless, a limit in riyals pacing at
 * 1.3 and one in dollars pacing at 1.3 are running hot by the same proportion, and comparing them
 * breaks no money rule because there is no money in the comparison.
 *
 * ## What it refuses
 *
 * A limit whose spend could not be compared has `pace: null` — withheld, partial, or in another
 * currency. There is no zero to draw: «pacing at 0» would mean «spending nothing», a claim about a
 * limit whose spend we could not read at all, and it would rank the limits we know least about as the
 * best-behaved ones. They are withheld and COUNTED.
 */
const reading = (over: Record<string, unknown> = {}) => ({
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'internal_monitoring',
  amount: 10_000, currency: 'SAR',
  period: { from: '2026-08-01', to: '2026-08-31', days: 31 },
  elapsed_days: 15, consumed: 4_500, consumed_currency: 'SAR', remaining: 5_500,
  utilisation: 0.45, pace: 0.93, projected_period_spend: 9_300,
  projected_exhaustion: { date: null, reason: 'not_within_period' },
  thresholds: [80, 100], state: 'ok', basis: 'comparable',
  ...over,
})

function route(limits: unknown[]) {
  vi.mocked(getEnvelope).mockResolvedValue({
    data: limits,
    meta: {
      enforcement: 'internal_monitoring',
      enforcement_note_en: 'It does not stop delivery on any ad platform.',
      enforcement_note_ar: 'ولا يوقف عرض الإعلانات على أي منصة.',
      today: '2026-08-15',
    },
  } as never)
}

describe('the spend limits page draws how the workspace is tracking', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('ranks every limit by its pace against a line drawn at 1.0', async () => {
    route([
      reading({ id: 'hot', pace: 1.4, state: 'over' }),
      reading({ id: 'cool', pace: 0.6 }),
    ])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('limit-pace-row-hot')).toBeInTheDocument()
    expect(screen.getByTestId('limit-pace-row-cool')).toBeInTheDocument()
    expect(screen.getByTestId('limit-pace-reference')).toHaveTextContent(/On plan/i)
  })

  it('puts the limit running hottest at the top, because that is the one to open', async () => {
    route([reading({ id: 'cool', pace: 0.6 }), reading({ id: 'hot', pace: 1.4, state: 'over' })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    await screen.findByTestId('limit-pace-row-hot')
    const order = screen.getAllByTestId(/^limit-pace-row-/).map((n) => n.getAttribute('data-testid'))
    expect(order[0]).toBe('limit-pace-row-hot')
  })

  it('compares a riyal limit with a dollar one, because a pace carries no currency', async () => {
    route([
      reading({ id: 'sar', currency: 'SAR', pace: 1.2 }),
      reading({ id: 'usd', currency: 'USD', consumed_currency: 'USD', pace: 0.8 }),
    ])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('limit-pace-row-sar')).toBeInTheDocument()
    expect(screen.getByTestId('limit-pace-row-usd')).toBeInTheDocument()
  })

  it('withholds a limit whose spend could not be compared, and counts it', async () => {
    route([
      reading({ id: 'ok1' }),
      reading({ id: 'blind', pace: null, consumed: null, state: 'unknown', basis: 'currency_mismatch' }),
    ])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    await screen.findByTestId('limit-pace-row-ok1')
    expect(screen.queryByTestId('limit-pace-row-blind')).toBeNull()
    expect(screen.getByTestId('limit-pace-withheld')).toHaveTextContent('1')
  })

  it('declines the ranking entirely when no limit has a comparable pace', async () => {
    route([reading({ id: 'blind', pace: null, consumed: null, state: 'unknown' })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('limit-pace-empty')).toBeInTheDocument()
    expect(screen.queryByTestId('limit-pace-reference')).toBeNull()
  })

  it('divides the limits by the state each one is in', async () => {
    route([
      reading({ id: 'a', state: 'ok' }), reading({ id: 'b', state: 'ok' }),
      reading({ id: 'c', state: 'approaching' }), reading({ id: 'd', state: 'over' }),
    ])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('limit-states-segment-ok')).toHaveStyle({ width: '50%' })
    expect(screen.getByTestId('limit-states-segment-over')).toHaveStyle({ width: '25%' })
  })

  it('gives «not comparable» its own band rather than letting it read as fine', async () => {
    route([reading({ id: 'a', state: 'ok' }), reading({ id: 'b', state: 'unknown', pace: null })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    const legend = await screen.findByTestId('limit-states-legend')
    expect(legend).toHaveTextContent(/Not comparable/i)
    expect(screen.getByTestId('limit-states-segment-unknown')).toHaveStyle({ width: '50%' })
  })

  it('draws nothing at all when the workspace has no limits', async () => {
    route([])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    await screen.findByTestId('spend-limits-intro')
    expect(screen.queryByTestId('limit-states-bar')).toBeNull()
    expect(screen.queryByTestId('limit-pace-empty')).toBeNull()
  })
})
