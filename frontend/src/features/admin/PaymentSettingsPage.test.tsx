import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PaymentSettingsPage } from './PaymentSettingsPage'
import type { PaymentSettings } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchPaymentSettings: vi.fn(),
    fetchPaymentWebhook: vi.fn(),
    fetchSecretRotation: vi.fn(),
    testPaymentProvider: vi.fn(),
  }
})

import { fetchPaymentSettings, testPaymentProvider } from './api'

const settings = (over: Partial<PaymentSettings> = {}): PaymentSettings => ({
  default: 'moyasar',
  currency: 'SAR',
  providers: [
    {
      provider: 'moyasar', label: { ar: 'ميسر', en: 'Moyasar' }, role: 'primary', is_default: true,
      status: 'awaiting_credentials', available: false, environment: 'unset',
      requires: [
        { key: 'MOYASAR_SECRET_KEY', present: false },
        { key: 'MOYASAR_WEBHOOK_TOKEN', present: false },
      ],
      webhook_url: 'https://example.test/api/v1/payments/webhook/moyasar',
    },
    {
      provider: 'stripe', label: { ar: 'سترايب', en: 'Stripe' }, role: 'alternative', is_default: false,
      status: 'awaiting_credentials', available: false, environment: 'unset',
      requires: [{ key: 'STRIPE_SECRET_KEY', present: false }],
      webhook_url: 'https://example.test/api/v1/payments/webhook/stripe',
    },
  ],
  mail: { state: 'awaiting_credentials', driver: 'smtp' },
  ...over,
})

/**
 * The gateways as an operator sees them (PAYSET-001).
 *
 * The claim throughout is that the page does not flatter the install: an incomplete provider is shown
 * as unusable, sandbox keys are labelled sandbox, and there is nowhere to type a secret.
 */
describe('PaymentSettingsPage', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('shows an unconfigured gateway as awaiting credentials, and cannot test it', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-status-moyasar')).toHaveTextContent(/Awaiting credentials/i)
    // A control that cannot work is disabled rather than offered.
    expect(screen.getByTestId('payment-test-moyasar')).toBeDisabled()

    fireEvent.click(screen.getByTestId('payment-test-moyasar'))
    await waitFor(() => expect(testPaymentProvider).not.toHaveBeenCalled())
  })

  /** Which gateway is OFFICIAL is a product decision, not a consequence of which keys exist. */
  it('names Moyasar the primary gateway even when nothing is configured', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-provider-moyasar')).toHaveTextContent(/Primary provider/i)
    expect(screen.getByTestId('payment-provider-stripe')).toHaveTextContent(/Alternative provider/i)
  })

  /** Exactly which piece is missing — not "not configured". */
  it('says which credential is missing', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-requirement-MOYASAR_WEBHOOK_TOKEN'))
      .toHaveAttribute('data-present', 'false')
  })

  /**
   * Sandbox is labelled sandbox.
   *
   * An operator certain they are in test mode while taking real money is the failure this label
   * exists to prevent.
   */
  it('labels sandbox keys as sandbox', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings({
      providers: [{
        provider: 'moyasar', label: { ar: 'ميسر', en: 'Moyasar' }, role: 'primary', is_default: true,
        status: 'live', available: true, environment: 'sandbox',
        requires: [{ key: 'MOYASAR_SECRET_KEY', present: true }],
        webhook_url: 'https://example.test/api/v1/payments/webhook/moyasar',
      }],
    }))

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    const card = await screen.findByTestId('payment-provider-moyasar')
    expect(card).toHaveAttribute('data-environment', 'sandbox')
    expect(screen.getByTestId('payment-environment-moyasar')).toHaveTextContent(/Sandbox keys/i)
  })

  it('runs a real connection test once a gateway is configured', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings({
      providers: [{
        provider: 'moyasar', label: { ar: 'ميسر', en: 'Moyasar' }, role: 'primary', is_default: true,
        status: 'live', available: true, environment: 'sandbox',
        requires: [{ key: 'MOYASAR_SECRET_KEY', present: true }],
        webhook_url: 'https://example.test/api/v1/payments/webhook/moyasar',
      }],
    }))
    vi.mocked(testPaymentProvider).mockResolvedValue({
      provider: 'moyasar', reachable: false, status: 'failed', error: 'Moyasar refused the invoice: 401',
    })

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-test-moyasar'))

    await waitFor(() => expect(testPaymentProvider).toHaveBeenCalledWith('moyasar'))
    // The gateway's own words: a generic failure hides whether the key is wrong or the account is
    // closed, which are different fixes.
    expect(await screen.findByTestId('payment-test-result-moyasar')).toHaveTextContent(/401/)
  })

  /** A notification channel that reaches nobody is reported, because half a payment system is that. */
  it('reports the notification channel honestly', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings({ mail: { state: 'sandbox', driver: 'log' } }))

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    const mail = await screen.findByTestId('payment-mail-state')
    expect(mail).toHaveAttribute('data-state', 'sandbox')
    expect(mail).toHaveTextContent(/reach nobody/i)
  })

  /** There is nowhere to type a secret, and that is the point. */
  it('offers no field for a gateway secret', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())

    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    await screen.findByTestId('admin-payment-settings')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.getByText(/No key can be changed from this page/i)).toBeInTheDocument()
  })
})
