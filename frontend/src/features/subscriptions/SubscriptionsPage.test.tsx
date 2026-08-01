import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { SubscriptionsPage } from './SubscriptionsPage'
import type { CurrentSubscription, SubscriptionPlan } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    getPlans: vi.fn(), getCurrent: vi.fn(),
    quotePlanChange: vi.fn(), requestPlanChange: vi.fn(), cancelPlanChange: vi.fn(),
  }
})

import { getCurrent, getPlans, quotePlanChange, requestPlanChange } from './api'

const plans: SubscriptionPlan[] = [
  { code: 'starter', name: 'Starter', price_monthly: '0', currency: 'USD', features: { ai_assist: false }, limits: { projects: 3 } },
  { code: 'growth', name: 'Growth', price_monthly: '99', currency: 'USD', features: { ai_assist: true }, limits: { projects: 25 } },
  { code: 'scale', name: 'Scale', price_monthly: '299', currency: 'USD', features: { white_label: true }, limits: { projects: null } },
]

const current: CurrentSubscription = {
  subscription: { status: 'active', seats: 5, current_period_end: '2026-08-01T00:00:00Z' },
  plan: plans[1],
  is_default_plan: false,
  usage: {
    projects: { limit: 25, used: 4, remaining: 21 },
    reports_per_month: { limit: null, used: 8, remaining: null },
  },
}

describe('SubscriptionsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getPlans).mockResolvedValue(plans)
    vi.mocked(getCurrent).mockResolvedValue(current)
  })
  afterEach(() => signOut())

  it('blocks the whole page without subscriptions.view', () => {
    signInWith([])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  it('renders the current plan, usage/limits with honest "Unlimited", and the catalogue', async () => {
    signInWith(['subscriptions.view'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })

    // Current plan name + status.
    expect(await screen.findByText('Active')).toBeInTheDocument()
    expect(screen.getAllByText('Growth').length).toBeGreaterThan(0)
    // Usage row values (Latin digits) + unlimited where the plan does not cap.
    expect(screen.getByText('21')).toBeInTheDocument()
    expect(screen.getAllByText('Unlimited').length).toBeGreaterThan(0)
    // Catalogue shows all plans.
    expect(screen.getByText('Starter')).toBeInTheDocument()
    expect(screen.getByText('Scale')).toBeInTheDocument()
  })

  it('hides the change-plan action without subscriptions.manage and shows the honest note', async () => {
    signInWith(['subscriptions.view'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })
    await screen.findByText('Starter')
    expect(screen.queryByRole('button', { name: /Switch to this plan/i })).not.toBeInTheDocument()
    expect(screen.getByText(/subscriptions\.manage/)).toBeInTheDocument()
  })

  it('shows a change-plan action for non-current plans with subscriptions.manage', async () => {
    signInWith(['subscriptions.view', 'subscriptions.manage'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })
    await waitFor(() => expect(screen.getAllByRole('button', { name: /Switch to this plan/i }).length).toBeGreaterThan(0))
  })
})

/**
 * Changing plan part-way through a paid period (PAY-002).
 *
 * The claim under test is not «a button exists». It is that the customer is shown what it costs
 * BEFORE anything is committed, that the credit for time they have already paid for is visible as a
 * deduction rather than folded into a total, and that an upgrade is never presented as having
 * happened while its payment is still outstanding.
 */
describe('SubscriptionsPage — mid-term plan change', () => {
  const upgradeQuote = {
    from: { plan: 'growth', plan_name: 'Growth', interval: 'monthly', unit_amount: '99.00' },
    to: { plan: 'scale', plan_name: 'Scale', interval: 'monthly' },
    quote: {
      direction: 'upgrade' as const, remaining_days: 20, period_days: 30, unused_fraction: 0.666667,
      credit: '66.00', new_period_price: '299.00', prorated_new: '199.33', due_now: '133.33',
      currency: 'USD', effective: 'immediate' as const, effective_at: null,
    },
  }

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getPlans).mockResolvedValue(plans)
    vi.mocked(getCurrent).mockResolvedValue(current)
  })
  afterEach(() => signOut())

  it('shows the numbers, including the credit, before anything is committed', async () => {
    vi.mocked(quotePlanChange).mockResolvedValue(upgradeQuote)
    signInWith(['subscriptions.view', 'subscriptions.manage'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })

    // Growth is the current plan and has no switch button, so the two here are Starter and Scale.
    const switchButtons = await screen.findAllByRole('button', { name: /Switch to this plan/i })
    fireEvent.click(switchButtons[switchButtons.length - 1])

    expect(await screen.findByTestId('due-now')).toHaveTextContent('133.33 USD')
    // The credit is shown as a deduction. Folding it into the total looks like being charged twice.
    expect(screen.getByText('− 66.00 USD')).toBeInTheDocument()
    expect(screen.getByTestId('proration-note')).toHaveTextContent(/only once the gateway confirms/i)
    // Reviewing is not committing.
    expect(requestPlanChange).not.toHaveBeenCalled()
  })

  /** A downgrade must say plainly that nothing is taken and nothing is given back. */
  it('says a downgrade costs nothing and starts at the period end', async () => {
    vi.mocked(quotePlanChange).mockResolvedValue({
      ...upgradeQuote,
      to: { plan: 'starter', plan_name: 'Starter', interval: 'monthly' },
      quote: {
        ...upgradeQuote.quote, direction: 'downgrade' as const, credit: '66.00',
        new_period_price: '0.00', prorated_new: '0.00', due_now: '0.00',
        effective: 'period_end' as const, effective_at: '2026-08-01T00:00:00Z',
      },
    })
    signInWith(['subscriptions.view', 'subscriptions.manage'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })

    fireEvent.click((await screen.findAllByRole('button', { name: /Switch to this plan/i }))[0])

    expect(await screen.findByTestId('due-now')).toHaveTextContent('0.00 USD')
    expect(screen.getByTestId('proration-note')).toHaveTextContent(/Nothing is charged and nothing is refunded/i)
    expect(screen.getByTestId('confirm-plan-change')).toHaveTextContent(/at period end/i)
  })

  /**
   * An upgrade waiting on payment is NOT the current plan, and the banner has to say so.
   *
   * This is the assumption a customer will otherwise make on their own — they picked a plan, they
   * saw a confirmation, so they have it. They do not, until the gateway says the money moved.
   */
  it('reports an unpaid upgrade as pending rather than as the plan in force', async () => {
    vi.mocked(getCurrent).mockResolvedValue({
      ...current,
      subscription: {
        ...current.subscription!,
        scheduled_change: {
          plan: 'scale', plan_name: 'Scale', billing_interval: 'monthly',
          unit_amount: '299.00', effective_at: null, awaiting_payment: true,
        },
      },
    })
    signInWith(['subscriptions.view', 'subscriptions.manage'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })

    const banner = await screen.findByTestId('pending-plan-change')
    expect(banner).toHaveAttribute('data-awaiting-payment', 'true')
    expect(banner).toHaveTextContent(/your current plan has not changed/i)
    // …and the plan in force is still the one they paid for.
    expect(screen.getAllByText('Growth').length).toBeGreaterThan(0)
  })

  it('shows a booked downgrade with the date it starts', async () => {
    vi.mocked(getCurrent).mockResolvedValue({
      ...current,
      subscription: {
        ...current.subscription!,
        scheduled_change: {
          plan: 'starter', plan_name: 'Starter', billing_interval: 'monthly',
          unit_amount: '0.00', effective_at: '2026-08-01T00:00:00Z', awaiting_payment: false,
        },
      },
    })
    signInWith(['subscriptions.view', 'subscriptions.manage'])
    renderWithProviders(<SubscriptionsPage />, { locale: 'en' })

    const banner = await screen.findByTestId('pending-plan-change')
    expect(banner).toHaveAttribute('data-awaiting-payment', 'false')
    expect(banner).toHaveTextContent('2026-08-01')
    expect(screen.getByTestId('withdraw-plan-change')).toBeInTheDocument()
  })
})
