import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { UsageCard } from './UsageCard'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getUsage: vi.fn() }
})

import { getUsage } from './api'

/** PRICING-USAGE-CREDITS-001 — own-key usage against the key's cap, said to be paid to the provider; platform meters with allowance. */
describe('UsageCard', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows each own key against its cap and says it is never taken from the balance', async () => {
    vi.mocked(getUsage).mockResolvedValue({
      period: '2026-11', prices_exclude_vat: true,
      meters: [
        { code: 'ai_tokens_own_key', name: 'AI tokens on your own key', name_ar: 'x', unit: 'token', funded_by: 'customer', charges_credits: false, unit_cost: null, currency: 'SAR', used: 4500, included: null, cost: '0.00', credits_charged: '0.00', uncovered: '0.00' },
        { code: 'sms_verifications', name: 'SMS verification codes', name_ar: 'y', unit: 'message', funded_by: 'platform', charges_credits: false, unit_cost: null, currency: 'SAR', used: 18, included: 20, cost: '0.00', credits_charged: '0.00', uncovered: '0.00' },
      ],
      own_keys: [{ id: 'k1', provider: 'anthropic', key: '••••1234', monthly_token_limit: 5000, used: 4500, scope: 'project', scope_name: 'Spring' }],
      credits: { balance: '0.00', currency: 'SAR', recent: [] },
    })
    renderWithProviders(<UsageCard ar={false} />, { locale: 'en' })

    expect(await screen.findByTestId('usage-key-k1')).toHaveTextContent('4,500 / 5,000 tokens')
    expect(screen.getByTestId('usage-key-meter-k1')).toHaveAttribute('aria-valuenow', '90')
    expect(screen.getByTestId('usage-key-scope-k1')).toHaveTextContent('Project: Spring')
    expect(screen.getByTestId('usage-card')).toHaveTextContent(/never taken from your balance/i)
    expect(screen.getByTestId('usage-meter-sms_verifications')).toHaveTextContent('18 / 20')
    expect(screen.queryByTestId('usage-meter-ai_tokens_own_key')).not.toBeInTheDocument()
    expect(screen.getByTestId('usage-credits')).toHaveTextContent('0.00 SAR')
  })
})
