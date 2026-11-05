import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TenantSubscriptionPanel } from './TenantSubscriptionPanel'
import type { TenantSubscriptionOverview, TenantSubscriptionState } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchTenantSubscription: vi.fn(), actOnTenantSubscription: vi.fn() }
})

import { actOnTenantSubscription, fetchTenantSubscription } from './api'

const paid = (over: Partial<TenantSubscriptionState> = {}): TenantSubscriptionState => ({
  status: 'active', plan_code: 'solo', plan_name: 'Solo', billing_interval: 'monthly', unit_amount: '990.00', currency: 'SAR',
  current_period_end: '2027-01-31T00:00:00Z', trial_ends_at: null, grace_ends_at: null, commercial_basis: null, open_ended: false, next_charge: null, ...over,
})

const overview = (subscription: TenantSubscriptionState | null, over: Partial<TenantSubscriptionOverview> = {}): TenantSubscriptionOverview => ({
  subscription,
  plans: [{ code: 'solo', name: 'Solo', currency: 'SAR', price_monthly: '990.00', price_annual: '9900.00' }, { code: 'team', name: 'Team', currency: 'USD', price_monthly: '50.00', price_annual: null }],
  history: [],
  ...over,
})

const open = async (o: TenantSubscriptionOverview, locale: 'ar' | 'en' = 'en') => {
  vi.mocked(fetchTenantSubscription).mockResolvedValue(o)
  renderWithProviders(<TenantSubscriptionPanel tenantId="t-1" ar={locale === 'ar'} />, { locale })
  await screen.findByTestId('tenant-subscription-state')
}

/** ADMIN-FULL-CONTROL-001 — the actions follow the state, each asks for what it needs, and money stays in its own currency. */
describe('TenantSubscriptionPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(actOnTenantSubscription).mockResolvedValue({ subscription: paid(), public_intake_affected: false })
  })

  it('shows the price in the currency of the row', async () => {
    await open(overview(paid({ currency: 'USD', unit_amount: '50.00' })))

    expect(screen.getByTestId('tenant-subscription-state')).toHaveTextContent('50.00 USD')
    expect(screen.getByTestId('tenant-subscription-state')).not.toHaveTextContent('SAR')
  })

  it('offers no way to convert a paid subscription, and no extension of open-ended access', async () => {
    await open(overview(paid()))
    expect(screen.queryByTestId('subscription-action-convert_paid')).not.toBeInTheDocument()
    expect(screen.getByTestId('subscription-action-extend')).toBeInTheDocument()
  })

  it('open-ended access says so plainly, shows no price, and offers its ways out', async () => {
    await open(overview(paid({ open_ended: true, commercial_basis: 'complimentary', current_period_end: null })), 'ar')

    expect(screen.getByTestId('tenant-subscription-open-ended')).toHaveTextContent('دخول مباشر دون دفع')
    expect(screen.getByTestId('tenant-subscription-state')).not.toHaveTextContent('990')
    expect(screen.queryByTestId('subscription-action-extend')).not.toBeInTheDocument()
    expect(screen.getByTestId('subscription-action-convert_paid')).toBeInTheDocument()
    expect(screen.getByTestId('subscription-action-convert_discount')).toBeInTheDocument()
    expect(screen.getByTestId('subscription-action-end')).toBeInTheDocument()
  })

  it('complimentary needs a reason and a yes, and sends both', async () => {
    await open(overview(paid({ status: 'past_due' })))

    fireEvent.click(screen.getByTestId('subscription-action-complimentary'))
    expect(screen.getByTestId('subscription-hint')).toHaveTextContent(/asks for nothing/)
    expect(screen.getByTestId('subscription-submit')).toBeDisabled()

    fireEvent.change(screen.getByTestId('subscription-reason'), { target: { value: 'A partner workspace' } })
    expect(screen.getByTestId('subscription-submit')).toBeDisabled()

    fireEvent.click(screen.getByTestId('subscription-confirm-complimentary'))
    fireEvent.click(screen.getByTestId('subscription-submit'))

    await waitFor(() => expect(actOnTenantSubscription).toHaveBeenCalledWith('t-1', { action: 'complimentary', reason: 'A partner workspace', confirm_complimentary: true }))
  })

  it('a workspace with no subscription must name a plan before anything is sent', async () => {
    await open(overview(null))

    fireEvent.click(screen.getByTestId('subscription-action-complimentary'))
    fireEvent.change(screen.getByTestId('subscription-reason'), { target: { value: 'The owner\'s own workspace' } })
    fireEvent.click(screen.getByTestId('subscription-confirm-complimentary'))
    expect(screen.getByTestId('subscription-submit')).toBeDisabled()

    fireEvent.change(screen.getByTestId('subscription-plan'), { target: { value: 'solo' } })
    fireEvent.click(screen.getByTestId('subscription-submit'))

    await waitFor(() => expect(vi.mocked(actOnTenantSubscription).mock.calls[0][1]).toMatchObject({ action: 'complimentary', plan_code: 'solo', interval: 'monthly' }))
  })

  it('a discount is validated before it is sent and shows the next charge', async () => {
    await open(overview(paid({ open_ended: true, commercial_basis: 'complimentary', current_period_end: null })))

    fireEvent.click(screen.getByTestId('subscription-action-convert_discount'))
    fireEvent.change(screen.getByTestId('subscription-reason'), { target: { value: 'Partner rate agreed' } })
    fireEvent.change(screen.getByTestId('subscription-discount'), { target: { value: '150' } })
    expect(screen.getByTestId('subscription-submit')).toBeDisabled()
  })

  it('shows a next charge that is waiting, in the currency of that charge', async () => {
    await open(overview(paid({ next_charge: { discount_percent: 50, amount: '495.00', currency: 'SAR' } })))

    expect(screen.getByTestId('tenant-subscription-next-charge')).toHaveTextContent('495.00 SAR')
  })

  it('a server refusal is shown, and the form stays open', async () => {
    vi.mocked(actOnTenantSubscription).mockRejectedValue(new Error('This account was closed.'))
    await open(overview(paid()))

    fireEvent.click(screen.getByTestId('subscription-action-end'))
    fireEvent.change(screen.getByTestId('subscription-reason'), { target: { value: 'Contract finished' } })
    fireEvent.click(screen.getByTestId('subscription-submit'))

    expect(await screen.findByTestId('subscription-error')).toBeInTheDocument()
    expect(screen.getByTestId('subscription-form')).toBeInTheDocument()
  })

  it('names the actions in the record, not their keys', async () => {
    await open(overview(paid(), { history: [{ action: 'complimentary', at: '2026-11-05T10:00:00Z', by: 'Owner', reason: 'A partner' }] }), 'ar')

    expect(screen.getByTestId('subscription-history')).toHaveTextContent('دخول مباشر دون دفع')
    expect(screen.getByTestId('subscription-history')).not.toHaveTextContent('complimentary')
  })
})
