import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { UsageMetersPage } from './UsageMetersPage'
import { TenantCreditsPanel } from './TenantCreditsPanel'
import { renderWithProviders } from '@/test/utils'
import type { AdminUsageMeter } from './api'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchUsageMeters: vi.fn(), updateUsageMeter: vi.fn(), fetchWorkspaceCredits: vi.fn(), adjustWorkspaceCredits: vi.fn() }
})

import { adjustWorkspaceCredits, fetchUsageMeters, fetchWorkspaceCredits, updateUsageMeter } from './api'

const meter = (over: Partial<AdminUsageMeter>): AdminUsageMeter => ({
  code: 'sms_verifications', name: 'SMS verification codes', name_ar: 'رموز التحقق عبر SMS', unit: 'message', funded_by: 'platform',
  unit_cost: null, currency: 'SAR', charges_credits: false, charges: false, included_per_plan: {}, is_active: true, ...over,
})

/** PRICING-USAGE-CREDITS-001 — the own-key meter offers no cost or charging; a platform meter saves cost, charging and allowances. */
describe('UsageMetersPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('never offers charging on the customer’s own key, and saves a platform meter with its reason', async () => {
    vi.mocked(fetchUsageMeters).mockResolvedValue({ meters: [
      meter({ code: 'ai_tokens_own_key', name: 'AI tokens on your own key', funded_by: 'customer', unit: 'token' }),
      meter({}),
    ] })
    vi.mocked(updateUsageMeter).mockResolvedValue({ meter: meter({ unit_cost: '0.25', charges_credits: true, charges: true }) })
    renderWithProviders(<UsageMetersPage />, { locale: 'en' })

    expect(await screen.findByTestId('admin-meter-ai_tokens_own_key')).toHaveTextContent(/never charged/i)
    expect(screen.queryByTestId('admin-meter-cost-ai_tokens_own_key')).not.toBeInTheDocument()
    expect(screen.queryByTestId('admin-meter-charges-ai_tokens_own_key')).not.toBeInTheDocument()
    expect(screen.getByTestId('admin-meter-state-sms_verifications')).toHaveTextContent('Recorded only')

    fireEvent.change(screen.getByTestId('admin-meter-cost-sms_verifications'), { target: { value: '0.25' } })
    fireEvent.click(screen.getByTestId('admin-meter-charges-sms_verifications'))
    fireEvent.change(screen.getByTestId('admin-meter-included-sms_verifications-team'), { target: { value: '100' } })
    expect(screen.getByTestId('admin-meter-save-sms_verifications')).toBeDisabled()
    fireEvent.change(screen.getByTestId('admin-meter-reason-sms_verifications'), { target: { value: 'Twilio KSA rate' } })
    fireEvent.click(screen.getByTestId('admin-meter-save-sms_verifications'))

    await waitFor(() => expect(updateUsageMeter).toHaveBeenCalledWith('sms_verifications', {
      unit_cost: 0.25, charges_credits: true, included_per_plan: { solo: null, team: 100, agency_2026: null }, reason: 'Twilio KSA rate',
    }))
  })

  it('adjusts a workspace balance once per form, with a reason', async () => {
    vi.mocked(fetchWorkspaceCredits).mockResolvedValue({ balance: '20.00', currency: 'SAR', meters: [
      { code: 'ai_tokens_own_key', name: 'AI tokens on your own key', name_ar: 'x', used: 1200, included: null, cost: '0.00', credits_charged: '0.00', uncovered: '0.00', funded_by: 'customer' },
    ], transactions: [{ id: 1, kind: 'grant', amount: '20.00', balance_after: '20.00', reason: 'Launch goodwill', actor_id: 1, at: '2026-11-01T10:00:00Z' }] })
    vi.mocked(adjustWorkspaceCredits).mockResolvedValue({ balance: '30.00', transaction_id: 2 })
    renderWithProviders(<TenantCreditsPanel tenantId="t1" ar={false} />, { locale: 'en' })

    expect(await screen.findByTestId('tenant-credits-balance')).toHaveTextContent('20.00 SAR')
    expect(screen.getByTestId('tenant-credits-meter-ai_tokens_own_key')).toHaveTextContent(/customer's key/i)
    fireEvent.change(screen.getByTestId('tenant-credits-amount'), { target: { value: '10' } })
    expect(screen.getByTestId('tenant-credits-submit')).toBeDisabled()
    fireEvent.change(screen.getByTestId('tenant-credits-reason'), { target: { value: 'Support goodwill' } })
    fireEvent.click(screen.getByTestId('tenant-credits-submit'))

    await waitFor(() => expect(adjustWorkspaceCredits).toHaveBeenCalledTimes(1))
    const [tenant, amount, reason, key] = vi.mocked(adjustWorkspaceCredits).mock.calls[0]
    expect([tenant, amount, reason]).toEqual(['t1', 10, 'Support goodwill'])
    expect(key.length).toBeGreaterThanOrEqual(8)
  })
})
