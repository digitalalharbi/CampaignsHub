import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { SpendLimitsPage } from './SpendLimitsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getEnvelope: vi.fn(),
  postData: vi.fn(),
  deleteData: vi.fn(),
}))

import { deleteData, getEnvelope, postData } from '@/lib/api/client'

/**
 * BUDGET-GOVERNANCE-001 — the page that must never let a reader believe it stops anything.
 *
 * `unified_campaigns.total_budget` is the plan set INSIDE the platform, and the platform enforces
 * it. A row here is a number an agency set for itself, over a scope no single platform can see, and
 * nothing enforces it. An operator who believes otherwise will not go and pause the campaigns, and
 * the money keeps going out with a green screen in front of it.
 */
const reading = (over: Record<string, unknown> = {}) => ({
  id: 'l1',
  scope: 'project',
  scope_id: null,
  enforcement: 'internal_monitoring',
  amount: 10_000,
  currency: 'SAR',
  period: { from: '2026-08-01', to: '2026-08-31', days: 31 },
  elapsed_days: 15,
  consumed: 4_500,
  consumed_currency: 'SAR',
  remaining: 5_500,
  utilisation: 0.45,
  pace: 0.93,
  projected_period_spend: 9_300,
  projected_exhaustion: { date: null, reason: 'not_within_period' },
  thresholds: [80, 100],
  state: 'ok',
  basis: 'comparable',
  ...over,
})

function route(limits: unknown[]) {
  vi.mocked(getEnvelope).mockResolvedValue({
    data: limits,
    meta: {
      enforcement: 'internal_monitoring',
      enforcement_note_en: 'CampaignsHub watches spend against this limit and warns. It does not stop delivery on any ad platform.',
      enforcement_note_ar: 'يراقب CampaignsHub الإنفاق مقابل هذا الحد ويُنبّه — ولا يوقف عرض الإعلانات على أي منصة.',
      today: '2026-08-15',
    },
  } as never)
}

describe('the page says what nothing else on it can', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('states that nothing here stops delivery, in the reader’s language', async () => {
    route([reading()])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limits-enforcement'))
      .toHaveTextContent('does not stop delivery on any ad platform')
  })

  it('says it in Arabic too', async () => {
    route([reading()])
    renderWithProviders(<SpendLimitsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('spend-limits-enforcement')).toHaveTextContent('لا يوقف عرض الإعلانات')
  })

  it('shows the figures a limit is judged on', async () => {
    route([reading()])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limit-l1-amount')).toHaveTextContent('10K SAR')
    expect(screen.getByTestId('spend-limit-l1-consumed')).toHaveTextContent('4.5K SAR')
    expect(screen.getByTestId('spend-limit-l1-utilisation')).toHaveTextContent('45%')
  })
})

describe('what it refuses to show', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  /**
   * Spend nobody could convert is not «0% used».
   *
   * That reading would report safety the product cannot see — the exact failure the whole feature
   * exists to prevent, arriving through the feature meant to prevent it.
   */
  it('shows a dash and the reason when there is no comparable figure', async () => {
    route([reading({
      consumed: null, remaining: null, utilisation: null, pace: null,
      state: 'unknown', basis: 'partial',
      projected_exhaustion: { date: null, reason: 'no_comparable_spend' },
    })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limit-l1-consumed')).toHaveTextContent('—')
    expect(screen.getByTestId('spend-limit-l1-utilisation')).toHaveTextContent('—')
    expect(screen.getByTestId('spend-limit-l1-state')).toHaveTextContent('Cannot be measured')
    expect(screen.getByTestId('spend-limit-l1-basis')).toHaveTextContent('could not be converted')
  })

  /** A projection is a strong sentence, and its absence gets a reason rather than a blank line. */
  it('explains why there is no date instead of leaving it empty', async () => {
    route([reading({ projected_exhaustion: { date: null, reason: 'too_early' } })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limit-l1-projection'))
      .toHaveTextContent('one day multiplied by thirty is not a forecast')
  })

  it('states the date when the rate supports one', async () => {
    route([reading({ projected_exhaustion: { date: '2026-08-22', reason: 'projected' } })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limit-l1-projection')).toHaveTextContent('2026-08-22')
  })

  /** An over-budget limit shows the overspend rather than clamping it out of sight. */
  it('shows the overspend as a negative remaining', async () => {
    route([reading({ consumed: 11_200, remaining: -1_200, utilisation: 1.12, state: 'over' })])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    expect(await screen.findByTestId('spend-limit-l1-state')).toHaveTextContent('Over the limit')
    expect(screen.getByText('-1.2K SAR')).toBeInTheDocument()
  })
})

/**
 * BUDGET-GOVERNANCE-001 — the operator can set the limit the empty state tells them to set.
 *
 * The page listed limits, priced them, paced them and projected them, and its empty state invited
 * the reader to «set a limit for a project, a platform, an account or a campaign» — with no control
 * anywhere on the page that could set one. The endpoint had been there since the feature's first
 * commit; nothing called it.
 */
describe('creating a limit', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'campaigns.budget.change'])
  })
  afterEach(() => signOut())

  it('opens a form from the page and posts what the operator filled in', async () => {
    route([])
    vi.mocked(postData).mockResolvedValue(reading() as never)
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('spend-limit-new'))
    fireEvent.change(screen.getByTestId('spend-limit-amount'), { target: { value: '10000' } })
    fireEvent.click(screen.getByTestId('spend-limit-submit'))

    await waitFor(() => expect(vi.mocked(postData)).toHaveBeenCalled())
    const body = vi.mocked(postData).mock.calls.at(-1)?.[1] as Record<string, unknown>
    expect(body.scope).toBe('project')
    expect(body.amount).toBe(10_000)
    expect(body.currency).toBe('SAR')
    // The server always warns at 100; these are the ones asked for before it.
    expect(body.thresholds).toEqual([75, 90])
  })

  /**
   * A scoped limit with no identifier would silently become «the whole project».
   *
   * The server refuses it with a 422. The form refuses it first, by leaving the submit disabled
   * until the platform is chosen — an operator meeting a validation error for a field the form
   * never asked about has been failed twice.
   */
  it('will not submit a platform limit until the platform is chosen', async () => {
    route([])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('spend-limit-new'))
    fireEvent.change(screen.getByTestId('spend-limit-scope'), { target: { value: 'platform' } })
    fireEvent.change(screen.getByTestId('spend-limit-amount'), { target: { value: '4000' } })

    expect(screen.getByTestId('spend-limit-submit')).toBeDisabled()

    fireEvent.change(screen.getByTestId('spend-limit-scope-id'), { target: { value: 'tiktok' } })
    expect(screen.getByTestId('spend-limit-submit')).toBeEnabled()
  })

  /** The dialog repeats the one sentence the whole feature rests on, where the limit is created. */
  it('says what the limit does not do, in the form itself', async () => {
    route([])
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('spend-limit-new'))

    expect(screen.getByTestId('spend-limit-form')).toHaveTextContent('does not stop delivery on any ad platform')
  })

  it('removes a limit through the endpoint that deactivates it', async () => {
    route([reading()])
    vi.mocked(deleteData).mockResolvedValue(null as never)
    renderWithProviders(<SpendLimitsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('spend-limit-l1-remove'))

    await waitFor(() => expect(vi.mocked(deleteData)).toHaveBeenCalled())
    expect(vi.mocked(deleteData).mock.calls.at(-1)?.[0]).toBe('/projects/p1/spend-limits/l1')
  })
})
