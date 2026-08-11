import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CurrencyRatesPage } from './CurrencyRatesPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchCurrencyRates: vi.fn(), recordCurrencyRate: vi.fn() }
})

import { fetchCurrencyRates, recordCurrencyRate } from './api'

/**
 * FX-FEED-001 — the page's job is to keep two states apart.
 *
 * The conversion ENGINE is verified. The rate SUPPLY is a decision nobody has made on this install,
 * and saying so is different from saying the engine is broken. A screen that blurred them would
 * either claim a capability that is absent or report working software as faulty.
 */

const payload = (over: Record<string, unknown> = {}) => ({
  feed: {
    state: 'awaiting_configuration',
    driver: null,
    label: null,
    stale_after_days: 3,
    last_rate_date: null,
    rates: 0,
  },
  unmet_pairs: [
    { base: 'USD', quote: 'SAR', withheld: 412, earliest: '2026-06-01', latest: '2026-08-01', sources: ['advertising'] },
  ],
  rates: [],
  ...over,
})

describe('CurrencyRatesPage', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  /** «No publisher chosen» is stated as such — and the refusal to invent a rate is stated with it. */
  it('says no source is configured and that no rate is invented to fill the gap', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(payload())

    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    const state = await screen.findByTestId('fx-feed-state')
    expect(state.textContent).toMatch(/لم يُختَر مزوّد أسعار/)
    expect(state.textContent).toMatch(/لا يُخترَع سعر/)
  })

  /**
   * The withheld figures are what make the decision concrete.
   *
   * «No feed configured» is a checkbox nobody actions; «USD→SAR, 412 figures withheld» is an argument.
   */
  it('shows what the missing feed has already cost, per pair', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(payload())

    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    const unmet = await screen.findByTestId('fx-unmet')
    expect(unmet.textContent).toMatch(/USD/)
    expect(unmet.textContent).toMatch(/SAR/)
    expect(unmet.textContent).toMatch(/412/)
  })

  /** A configured source is a different verdict, not a different colour of the same one. */
  it('reports a ready source distinctly from an absent one', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(payload({
      feed: {
        state: 'ready', driver: 'App\\Rates\\Some', label: 'Some feed',
        stale_after_days: 3, last_rate_date: '2026-08-10', rates: 120,
      },
      unmet_pairs: [],
    }))

    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    const state = await screen.findByTestId('fx-feed-state')
    expect(state.textContent).toMatch(/Some feed/)
    expect(state.textContent).not.toMatch(/لم يُختَر مزوّد أسعار/)
    expect((await screen.findByTestId('fx-unmet-empty')).textContent).toMatch(/لا توجد أرقام محجوبة/)
  })

  /** An operator can enter a rate by hand — the path that keeps an unconfigured install usable. */
  it('records a hand-entered rate', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(payload())
    vi.mocked(recordCurrencyRate).mockResolvedValue({
      base: 'USD', quote: 'SAR', rate: 3.75, rate_date: '2026-06-01', source: 'manual:owner@campaignshub.io',
    })

    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.change(await screen.findByTestId('fx-base'), { target: { value: 'usd' } })
    fireEvent.change(screen.getByTestId('fx-rate'), { target: { value: '3.75' } })
    fireEvent.submit(screen.getByTestId('fx-rate-form'))

    await waitFor(() => expect(recordCurrencyRate).toHaveBeenCalled())
    // Upper-cased before it leaves the page: a rate filed under «usd» would never be found again.
    expect(vi.mocked(recordCurrencyRate).mock.calls[0][0].base).toBe('USD')
  })

  /** A refusal from the server is shown rather than swallowed into a silent no-op. */
  it('shows why a rate was refused', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(payload())
    vi.mocked(recordCurrencyRate).mockRejectedValue(new Error('The rate date must be a date before or equal to today.'))

    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.change(await screen.findByTestId('fx-base'), { target: { value: 'USD' } })
    fireEvent.change(screen.getByTestId('fx-rate'), { target: { value: '3.75' } })
    fireEvent.submit(screen.getByTestId('fx-rate-form'))

    expect((await screen.findByTestId('fx-error')).textContent).toBeTruthy()
  })
})
