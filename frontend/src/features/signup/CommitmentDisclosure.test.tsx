import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CommitmentDisclosure } from './CommitmentDisclosure'
import type { PlanQuote } from './api'
import { renderWithProviders } from '@/test/utils'

const base: PlanQuote = {
  plan_code: 'solo', currency: 'SAR', interval: 'annual', due_now: '1990.00', due_later: null, renews_in_days: 365,
  trial_days: 0, trial_fee: null, regular_monthly: '199.00', commitment_months: 0, total_committed: null,
  remaining_committed_payments: 0, next_payment_on: '2027-11-01',
}

/** PRICING-VAT-001 — the VAT and the amount the card is asked for are shown before it is asked. */
describe('CommitmentDisclosure — VAT', () => {
  it('adds 15% and states the total on a VAT-exclusive price', () => {
    renderWithProviders(<CommitmentDisclosure ar={false} agreed={false} onAgreedChange={() => {}} quote={{ ...base,
      vat: { now: { net: '1990.00', tax: '298.50', gross: '2288.50', treatment: 'basic_15', rate: 0.15, prices_include_vat: false }, later: null } }} />, { locale: 'en' })

    expect(screen.getByTestId('quote-vat')).toHaveTextContent('298.50 SAR')
    expect(screen.getByTestId('quote-total')).toHaveTextContent('2288.50 SAR')
  })

  it('says the VAT is included on an all-in price and adds nothing', () => {
    renderWithProviders(<CommitmentDisclosure ar={false} agreed={false} onAgreedChange={() => {}} quote={{ ...base, currency: 'USD', due_now: '49.00',
      vat: { now: { net: '42.61', tax: '6.39', gross: '49.00', treatment: 'basic_15', rate: 0.15, prices_include_vat: true }, later: null } }} />, { locale: 'en' })

    expect(screen.queryByTestId('quote-total')).not.toBeInTheDocument()
    expect(screen.getByTestId('quote-vat-included')).toHaveTextContent('Includes VAT of 6.39 USD')
  })
})
