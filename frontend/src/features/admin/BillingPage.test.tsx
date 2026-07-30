import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { BillingPage } from './BillingPage'
import type { PlatformPlan, PlatformRevenue } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchPlans: vi.fn(),
    fetchSubscriptions: vi.fn(),
    fetchRevenue: vi.fn(),
    updatePlan: vi.fn(),
  }
})

import { fetchPlans, fetchRevenue, fetchSubscriptions, updatePlan } from './api'

function plan(over: Partial<PlatformPlan> = {}): PlatformPlan {
  return {
    id: 'p1', code: 'growth', name: 'Growth', price_monthly: '300.00', currency: 'SAR',
    is_active: true, features: [], limits: [], subscribers: { active: 2, total: 5 },
    ...over,
  }
}

function revenue(over: Partial<PlatformRevenue> = {}): PlatformRevenue {
  return {
    committed_monthly: [
      { currency: 'SAR', monthly: '600.00', subscriptions: 2 },
      { currency: 'USD', monthly: '80.00', subscriptions: 1 },
    ],
    collection_status: 'not_implemented',
    note: 'Committed subscription value only.',
    ...over,
  }
}

describe('BillingPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [plan()] })
    vi.mocked(fetchSubscriptions).mockResolvedValue({ subscriptions: [], meta: { total: 0 } })
    vi.mocked(fetchRevenue).mockResolvedValue(revenue())
  })

  /** A plan with 5 subscribers of whom 2 are active has 2 customers — one number would say 5. */
  it('shows active subscribers against the total, not one blended count', async () => {
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-growth')).toBeInTheDocument())
    expect(screen.getByTestId('plan-growth')).toHaveTextContent('2 active of 5')
  })

  /** Deactivating stops new sign-ups; the page must say that rather than leave it to be guessed. */
  it('explains that deactivating leaves existing subscribers alone', async () => {
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-growth')).toBeInTheDocument())
    expect(screen.getByText(/leaves existing subscribers untouched/)).toBeInTheDocument()
    expect(screen.getByText(/price is not editable here/)).toBeInTheDocument()
  })

  it('toggles a plan’s availability', async () => {
    vi.mocked(updatePlan).mockResolvedValue({ plan: { id: 'p1', name: 'Growth', is_active: false } })
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-growth')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('switch'))

    await waitFor(() => expect(updatePlan).toHaveBeenCalledWith('p1', { is_active: false }))
  })

  /**
   * The assertion this page exists to protect. The figure is a forward commitment, and the ledger it
   * is NOT drawn from belongs to agencies invoicing their own clients. Showing it as revenue would be
   * the most flattering lie the console could tell, so the qualification must be on screen — and
   * ABOVE the number, because a figure that size is read first.
   */
  it('says the figure is committed value and not money collected', async () => {
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('billing-tab-revenue')).toBeInTheDocument())
    fireEvent.click(screen.getByTestId('billing-tab-revenue'))

    await waitFor(() => expect(screen.getByTestId('revenue-honesty')).toBeInTheDocument())
    const note = screen.getByTestId('revenue-honesty')
    expect(note).toHaveTextContent('not money collected')
    expect(note).toHaveTextContent('agencies billing their own clients')
  })

  /** Currencies stand side by side. A blended total looks authoritative and means nothing. */
  it('reports each currency separately', async () => {
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    fireEvent.click(await screen.findByTestId('billing-tab-revenue'))

    await waitFor(() => expect(screen.getByTestId('committed-SAR')).toBeInTheDocument())
    expect(screen.getByTestId('committed-SAR')).toHaveTextContent('600 SAR')
    expect(screen.getByTestId('committed-USD')).toHaveTextContent('80 USD')
  })

  /** No active subscriptions means nothing committed — not a zero dressed as a result. */
  it('says nothing is committed rather than showing a zero', async () => {
    vi.mocked(fetchRevenue).mockResolvedValue(revenue({ committed_monthly: [] }))
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    fireEvent.click(await screen.findByTestId('billing-tab-revenue'))

    await waitFor(() =>
      expect(screen.getByText('No active subscriptions, so nothing is committed.')).toBeInTheDocument(),
    )
  })
})
