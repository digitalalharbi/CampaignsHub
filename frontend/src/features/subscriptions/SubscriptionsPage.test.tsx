import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { SubscriptionsPage } from './SubscriptionsPage'
import type { CurrentSubscription, SubscriptionPlan } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, getPlans: vi.fn(), getCurrent: vi.fn(), changePlan: vi.fn() }
})

import { getCurrent, getPlans } from './api'

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
