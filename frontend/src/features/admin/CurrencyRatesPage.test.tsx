import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CurrencyRatesPage } from './CurrencyRatesPage'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual, fetchCurrencyRates: vi.fn(), recordCurrencyRate: vi.fn(), previewConversion: vi.fn(), recomputeConverted: vi.fn(),
    updateFxPolicy: vi.fn(), fetchRateHistory: vi.fn(), setWorkspaceDisplayCurrency: vi.fn(),
  }
})

import { fetchCurrencyRates, previewConversion, recomputeConverted, recordCurrencyRate, updateFxPolicy } from './api'

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

describe('CurrencyRatesPage — FX-ENGINE-001', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  const full = () => payload({
    policy: { display_currency: 'USD', auto_source_enabled: false, auto_source: 'ecb_reference', auto_source_label: 'ECB' },
    currencies: [
      { currency: 'EUR', accounts: 2, providers: ['google'], status: 'missing', rate: null, rate_date: null, kind: null, source: null, recorded_by: null, withheld: 14 },
      { currency: 'GBP', accounts: 1, providers: ['meta'], status: 'stale', rate: 1.25, rate_date: '2026-11-01', kind: 'manual', source: 'manual:a@example.test', recorded_by: 'a@example.test', withheld: 0 },
      { currency: 'SAR', accounts: 3, providers: ['snapchat'], status: 'valid', rate: 0.266666666667, rate_date: '1986-06-01', kind: 'pegged', source: 'official-peg:SAMA', recorded_by: null, withheld: 0 },
    ],
    alerts: [{ id: 'a1', kind: 'fx_rate_missing', severity: 'critical', currency: 'EUR', accounts: 2, withheld: 14, rate_date: null, since: null }],
    workspaces: [],
    guard: { checked_from: '2026-11-12', stored_without_rate: 0, unit_unknown_but_converted: 0, bypasses: {}, ok: true },
  })

  it('lists the currencies in use with ✓ / ⚠ / ✗ status and the withheld count', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(full())
    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    expect((await screen.findByTestId('fx-cur-EUR')).textContent).toMatch(/بلا سعر/)
    expect(screen.getByTestId('fx-cur-EUR').textContent).toMatch(/14/)
    expect(screen.getByTestId('fx-cur-GBP').textContent).toMatch(/قديم/)
    expect(screen.getByTestId('fx-cur-SAR').textContent).toMatch(/مثبّت/)
    expect(screen.getByTestId('fx-alerts').textContent).toMatch(/EUR/)
  })

  it('«سجّل» prefills the form and a pegged rate is sent as pegged with its end and note', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(full())
    vi.mocked(recordCurrencyRate).mockResolvedValue({ base: 'EUR', quote: 'USD', rate: 1.12, rate_date: '2026-11-09', source: 'manual:x' })
    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('fx-pick-EUR'))
    expect((screen.getByTestId('fx-base') as HTMLInputElement).value).toBe('EUR')
    fireEvent.change(screen.getByTestId('fx-rate'), { target: { value: '1.12' } })
    fireEvent.change(screen.getByTestId('fx-note'), { target: { value: 'bank ref' } })
    fireEvent.click(screen.getByTestId('fx-pegged'))
    fireEvent.submit(screen.getByTestId('fx-rate-form'))

    await waitFor(() => expect(recordCurrencyRate).toHaveBeenCalled())
    expect(vi.mocked(recordCurrencyRate).mock.calls[0][0]).toMatchObject({ base: 'EUR', quote: 'USD', rate: 1.12, note: 'bank ref', kind: 'pegged' })
  })

  it('the calculator shows 253 EUR = 283.36 $ and the rate that made it', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(full())
    vi.mocked(previewConversion).mockResolvedValue({
      original_amount: 253, original_currency: 'EUR', date: '2026-11-10', currency: 'USD', converted_amount: 283.36, rate: 1.12,
      rate_date: '2026-11-09', source: 'manual:a@example.test', kind: 'manual', recorded_by: 'a@example.test', stale: false, withheld: false,
      pending_label: null, rates_page: null, legs: [], via: 'fx.convert',
    })
    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.submit(await screen.findByTestId('fx-preview'))
    const out = await screen.findByTestId('fx-preview-result')
    expect(out.textContent).toMatch(/283\.36 \$/)
    expect(out.textContent).toMatch(/1 EUR = 1\.12 USD/)
    expect(out.textContent).toMatch(/2026-11-09/)
  })

  it('recompute previews the differences first and applies only on «تطبيق»', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(full())
    const summary = { from: '2026-11-01', to: '2026-11-15', applied: false, examined: 2, changed: 2, released: 1, withheld: 0, unchanged: 0,
      by_currency: [{ currency: 'EUR', rows: 2, changed: 2, before: 105, after: 395.36 }], entity: { examined: 0, changed: 0 }, budgets_refreshed: 0 }
    vi.mocked(recomputeConverted).mockResolvedValueOnce(summary).mockResolvedValueOnce({ ...summary, applied: true, budgets_refreshed: 1 })
    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.change(await screen.findByLabelText('إعادة حساب الأرقام المحوّلة · يبدأ'), { target: { value: '2026-11-01' } })
    fireEvent.change(screen.getByLabelText('إعادة حساب الأرقام المحوّلة · ينتهي'), { target: { value: '2026-11-15' } })
    fireEvent.click(screen.getByTestId('fx-recompute-dry'))
    expect((await screen.findByTestId('fx-recompute-summary')).textContent).toMatch(/395\.36/)
    expect(vi.mocked(recomputeConverted).mock.calls[0][0]).toMatchObject({ apply: false })

    fireEvent.click(screen.getByTestId('fx-recompute-apply'))
    await waitFor(() => expect(recomputeConverted).toHaveBeenCalledTimes(2))
    expect(vi.mocked(recomputeConverted).mock.calls[1][0]).toMatchObject({ apply: true })
  })

  it('the display currency toggles between USD and SAR', async () => {
    vi.mocked(fetchCurrencyRates).mockResolvedValue(full())
    vi.mocked(updateFxPolicy).mockResolvedValue({ display_currency: 'SAR', auto_source_enabled: false, auto_source: 'ecb_reference', auto_source_label: 'ECB' })
    renderWithProviders(<CurrencyRatesPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('fx-display-SAR'))
    await waitFor(() => expect(vi.mocked(updateFxPolicy).mock.calls[0][0]).toEqual({ display_currency: 'SAR' }))
  })
})
