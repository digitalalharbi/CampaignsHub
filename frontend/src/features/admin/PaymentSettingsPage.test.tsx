import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PaymentSettingsPage } from './PaymentSettingsPage'
import type { PaymentProviderSetting, PaymentSettings } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchPaymentSettings: vi.fn(),
    fetchPaymentWebhook: vi.fn(),
    fetchSecretRotation: vi.fn(),
    testPaymentProvider: vi.fn(),
    savePaymentGateway: vi.fn(),
    setPaymentGatewayEnabled: vi.fn(),
    setPrimaryPaymentGateway: vi.fn(),
  }
})

import { fetchPaymentSettings, savePaymentGateway, setPaymentGatewayEnabled, setPrimaryPaymentGateway, testPaymentProvider } from './api'

const gateway = (over: Partial<PaymentProviderSetting> = {}): PaymentProviderSetting => ({
  provider: 'moyasar', label: { ar: 'ميسر', en: 'Moyasar' }, role: 'primary', is_default: false, is_primary: false,
  implemented: true, status: 'awaiting_credentials', available: false, enabled: false, mode: null, environment: 'unset', source: 'none',
  fields: [
    { key: 'secret_key', label: { ar: 'المفتاح السري', en: 'Secret key' }, secret: true, required: true, present: false, value: null },
    { key: 'publishable_key', label: { ar: 'المفتاح العام', en: 'Publishable key' }, secret: false, required: false, present: false, value: null },
    { key: 'webhook_token', label: { ar: 'رمز', en: 'Webhook secret token' }, secret: true, required: true, present: false, value: null },
  ],
  requires: [{ key: 'MOYASAR_SECRET_KEY', present: false }],
  test: { status: null, at: null, message: null, current: false },
  last_rotated_at: null,
  webhook_url: 'https://api.example.test/api/v1/payments/webhook/moyasar',
  webhook: { scheme: 'A shared secret carried in the body as `secret_token`.', header: null, events: ['payment_paid'] },
  capabilities: { hosted_checkout: true, status_lookup: true, refund: false, recurring: true, webhook: true },
  methods: ['mada', 'visa'],
  docs: 'https://docs.moyasar.com',
  note: null,
  ...over,
})

const settings = (providers: PaymentProviderSetting[] = [gateway()], over: Partial<PaymentSettings> = {}): PaymentSettings => ({
  default: 'sandbox', primary: 'sandbox', primary_source: 'environment', currency: 'SAR', providers,
  mail: { state: 'awaiting_credentials', driver: 'smtp' },
  recurring: { ready: false, provider: 'moyasar', reason: 'no_gateway', saved_methods: 0 },
  ...over,
})

/**
 * PAY-GATEWAY-CONSOLE-001 — the gateways are configured from this page: keys typed in and stored write-only, then tested, enabled
 * (live confirmed) and made primary, each a separate act. A stored secret is never put back into a box.
 */
describe('PaymentSettingsPage', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('shows an unconfigured gateway as awaiting keys, with test and enable unavailable', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-status-moyasar')).toHaveTextContent(/Awaiting keys/i)
    expect(screen.getByTestId('payment-test-moyasar')).toBeDisabled()
    expect(screen.getByTestId('payment-enable-moyasar')).toBeDisabled()
    fireEvent.click(screen.getByTestId('payment-test-moyasar'))
    await waitFor(() => expect(testPaymentProvider).not.toHaveBeenCalled())
  })

  it('stores keys through the setup form and never shows a stored secret back', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({
      status: 'needs_test', mode: 'test', source: 'console',
      fields: [
        { key: 'secret_key', label: { ar: 'س', en: 'Secret key' }, secret: true, required: true, present: true, value: null },
        { key: 'webhook_token', label: { ar: 'ر', en: 'Webhook secret token' }, secret: true, required: true, present: false, value: null },
      ],
    })]))
    vi.mocked(savePaymentGateway).mockResolvedValue({ changed: ['webhook_token'], provider: gateway() })
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-setup-moyasar'))
    const secret = screen.getByTestId('payment-field-moyasar-secret_key') as HTMLInputElement
    expect(secret.type).toBe('password')
    expect(secret.value).toBe('')
    expect(secret.placeholder).toMatch(/Stored/i)

    fireEvent.change(screen.getByTestId('payment-field-moyasar-webhook_token'), { target: { value: 'New-Token-123' } })
    fireEvent.click(screen.getByTestId('payment-save-moyasar'))

    await waitFor(() => expect(savePaymentGateway).toHaveBeenCalledWith('moyasar', { webhook_token: 'New-Token-123', mode: 'test' }))
    expect(await screen.findByTestId('payment-saved-moyasar')).toHaveTextContent(/Saved/i)
  })

  it('runs a real connection test and shows the gateway’s own refusal', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({ status: 'needs_test', mode: 'test', source: 'console' })]))
    vi.mocked(testPaymentProvider).mockResolvedValue({ provider: 'moyasar', reachable: false, status: 'failed', error: 'Moyasar refused the invoice: 401' })
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-test-moyasar'))

    await waitFor(() => expect(testPaymentProvider).toHaveBeenCalledWith('moyasar'))
    expect(await screen.findByTestId('payment-test-result-moyasar')).toHaveTextContent(/401/)
  })

  it('says when the keys changed after the last passing test', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({
      status: 'needs_test', mode: 'test', test: { status: 'passed', at: '2026-10-31T10:00:00Z', message: null, current: false },
    })]))
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-last-test-moyasar')).toHaveTextContent(/keys changed after the last test/i)
  })

  it('asks before enabling live keys, and only then calls the server with the confirmation', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({
      status: 'ready', mode: 'live', environment: 'live', test: { status: 'passed', at: null, message: null, current: true },
    })]))
    vi.mocked(setPaymentGatewayEnabled).mockResolvedValue({ provider: gateway() })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-enable-moyasar'))
    expect(setPaymentGatewayEnabled).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('payment-enable-moyasar'))
    await waitFor(() => expect(setPaymentGatewayEnabled).toHaveBeenCalledWith('moyasar', true, true))
    confirm.mockRestore()
  })

  it('makes an enabled gateway primary after saying that existing payments stay where they are', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({ status: 'enabled', enabled: true, available: true, mode: 'test' })]))
    vi.mocked(setPrimaryPaymentGateway).mockResolvedValue(settings())
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-make-primary-moyasar'))

    expect(confirm.mock.calls[0][0]).toMatch(/existing payments and subscriptions stay/i)
    await waitFor(() => expect(setPrimaryPaymentGateway).toHaveBeenCalledWith('moyasar'))
    confirm.mockRestore()
  })

  it('shows the webhook URL with a copy button and the gateway’s scheme', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings())
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('payment-webhook-moyasar'))

    const panel = screen.getByTestId('payment-webhook-panel-moyasar')
    expect(panel).toHaveTextContent('https://api.example.test/api/v1/payments/webhook/moyasar')
    expect(panel).toHaveTextContent(/secret_token/)
    expect(screen.getByTestId('payment-webhook-copy-moyasar')).toBeInTheDocument()
  })

  it('shows a gateway with no adapter as not implemented, with no setup offered', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings([gateway({ provider: 'hyperpay', label: { ar: 'هايبر باي', en: 'HyperPay' }, implemented: false, status: 'not_implemented', fields: [], methods: [] })]))
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-status-hyperpay')).toHaveTextContent(/Not implemented/i)
    expect(screen.queryByTestId('payment-setup-hyperpay')).not.toBeInTheDocument()
  })

  it('says whether renewals can be taken and how many cards exist', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings(undefined, { recurring: { ready: true, provider: 'moyasar', reason: 'ready', saved_methods: 0 } }))
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    const block = await screen.findByTestId('payment-recurring-state')
    expect(block).toHaveAttribute('data-ready', 'true')
    expect(screen.getByTestId('payment-recurring-cards').textContent).toMatch(/0/)
  })

  it('reports the notification channel honestly', async () => {
    vi.mocked(fetchPaymentSettings).mockResolvedValue(settings(undefined, { mail: { state: 'sandbox', driver: 'log' } }))
    renderWithProviders(<PaymentSettingsPage />, { locale: 'en' })

    expect(await screen.findByTestId('payment-mail-state')).toHaveTextContent(/reach nobody/i)
  })
})
