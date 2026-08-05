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
    id: 'p1', code: 'growth', name: 'Growth', name_ar: 'النمو', price_monthly: '300.00',
    price_annual: '3000.00', currency: 'SAR',
    is_active: true, is_public: true, features: [], limits: [], subscribers: { active: 2, total: 5 },
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
  /**
   * The console explains what a change reaches — and what it deliberately does not.
   *
   * The price IS editable now (PLAN-PAID-001 puts it under the platform owner's control), and the
   * screen has to say what that means: new subscribers only. That is not reassurance, it is how the
   * system works — a subscription captures `unit_amount` when it is assigned and every renewal reads
   * that column, so the catalogue governs quotes and the subscription governs bills.
   */
  it('explains that a price change reaches new subscribers only', async () => {
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-growth')).toBeInTheDocument())
    expect(screen.getByText(/leaves existing subscribers untouched/)).toBeInTheDocument()
    expect(screen.getByText(/applies to NEW subscribers only/)).toBeInTheDocument()
    expect(screen.queryByText(/price is not editable here/)).not.toBeInTheDocument()
  })

  /** Both prices are editable, and an empty annual field withdraws the plan from the yearly term. */
  it('offers the monthly and annual price, and saves only a real change with a reason', async () => {
    vi.mocked(updatePlan).mockResolvedValue({ plan: { id: 'p1', code: 'growth', name: 'Growth' } } as never)
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-prices-growth')).toBeInTheDocument())

    // Nothing has changed, so there is nothing to save — an owner opening the page cannot fill the
    // audit log with a save that changed nothing, and there is no reason field to fill in either.
    expect(screen.getByTestId('plan-save-growth')).toBeDisabled()
    expect(screen.queryByTestId('plan-reason-growth')).not.toBeInTheDocument()

    fireEvent.change(screen.getByTestId('plan-price-annual-growth'), { target: { value: '' } })

    // A change without an explanation is still not saveable: a commercial decision nobody wrote a
    // reason for is one nobody can defend later.
    expect(screen.getByTestId('plan-save-growth')).toBeDisabled()
    fireEvent.change(screen.getByTestId('plan-reason-growth'), { target: { value: 'Withdrawn from the annual term for Q4.' } })

    fireEvent.click(screen.getByTestId('plan-save-growth'))

    // An empty annual field is null, not zero: withdrawn from the term, not sold for nothing.
    await waitFor(() => expect(updatePlan).toHaveBeenCalledWith('p1', {
      price_monthly: '300.00',
      price_annual: null,
      features: { campaign_tracking: false, reports: false, ai_assist: false, white_label: false },
      reason: 'Withdrawn from the annual term for Q4.',
    }))
  })

  /** What a plan INCLUDES is data the owner edits, not a paragraph somebody rewrites. */
  it('turns a plan’s services on and off', async () => {
    vi.mocked(updatePlan).mockResolvedValue({ plan: { id: 'p1', code: 'growth', name: 'Growth' } } as never)
    vi.mocked(fetchPlans).mockResolvedValue({ plans: [plan({ features: { campaign_tracking: true, reports: false } })] })
    renderWithProviders(<BillingPage />, { route: '/admin/billing', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('plan-feature-growth-reports')).toBeInTheDocument())
    expect(screen.getByTestId('plan-feature-growth-campaign_tracking')).toBeChecked()
    expect(screen.getByTestId('plan-feature-growth-reports')).not.toBeChecked()

    fireEvent.click(screen.getByTestId('plan-feature-growth-reports'))
    fireEvent.change(screen.getByTestId('plan-reason-growth'), { target: { value: 'Reports are included from this month.' } })
    fireEvent.click(screen.getByTestId('plan-save-growth'))

    await waitFor(() => expect(updatePlan).toHaveBeenCalled())
    expect(vi.mocked(updatePlan).mock.calls[0][1].features).toMatchObject({ campaign_tracking: true, reports: true })
  })

  it('toggles a plan’s availability', async () => {
    // Built from the same factory as every other fixture — a hand-written partial drifted out of
    // shape and nothing caught it, because the typecheck was running against no files at all.
    vi.mocked(updatePlan).mockResolvedValue({ plan: plan({ is_active: false }) })
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
