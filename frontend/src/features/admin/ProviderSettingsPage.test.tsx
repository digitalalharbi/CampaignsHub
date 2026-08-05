import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProviderSettingsPage } from './ProviderSettingsPage'
import type { IntegrationProvider } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchIntegrationProviders: vi.fn(),
    saveIntegrationProvider: vi.fn(),
    testIntegrationProvider: vi.fn(),
    setIntegrationProviderEnabled: vi.fn(),
    forgetIntegrationCredential: vi.fn(),
  }
})

import {
  fetchIntegrationProviders, saveIntegrationProvider, setIntegrationProviderEnabled,
} from './api'

const provider = (over: Partial<IntegrationProvider> = {}): IntegrationProvider => ({
  key: 'google',
  kind: 'advertising',
  label: 'Google Ads API',
  label_ar: 'واجهة جوجل أدز',
  fields: [
    { key: 'client_id', label: 'OAuth client ID', label_ar: 'معرّف عميل OAuth', secret: false, required: true, where: 'Cloud console', where_ar: 'وحدة كلاود' },
    { key: 'client_secret', label: 'OAuth client secret', label_ar: 'سر عميل OAuth', secret: true, required: true, where: 'Same entry', where_ar: 'المدخل نفسه' },
    { key: 'developer_token', label: 'Developer token', label_ar: 'رمز المطوّر', secret: true, required: true, where: 'API Center', where_ar: 'مركز الـ API' },
  ],
  scopes: ['https://www.googleapis.com/auth/adwords'],
  effective_scopes: ['https://www.googleapis.com/auth/adwords'],
  uses_pkce: false,
  supports_refresh: true,
  token_note: 'A refresh token is issued only on first consent.',
  token_note_ar: 'لا يُصدر رمز التجديد إلا في أول موافقة.',
  webhooks: 'polling_only',
  webhook_signature_header: null,
  webhook_url: null,
  redirect_uri: 'https://example.test/api/v1/oauth/ads/google/callback',
  prerequisites: ['A developer token approved for Basic Access.'],
  prerequisites_ar: ['رمز مطوّر معتمد بمستوى Basic Access.'],
  docs_url: 'https://developers.google.com/google-ads/api/docs/start',
  rate_limit_note: 'Operations are capped per developer token per day.',
  pagination_note: 'nextPageToken on search.',
  state: 'awaiting_credentials',
  enabled: true,
  environment: 'sandbox',
  missing: ['developer_token'],
  connectable: false,
  values: [
    { key: 'client_id', present: true, source: 'stored', hint: '4321' },
    { key: 'client_secret', present: true, source: 'stored', hint: 'wxyz' },
    { key: 'developer_token', present: false, source: null, hint: null },
  ],
  last_tested_at: null,
  last_test_status: null,
  last_test_message: null,
  last_rotated_at: null,
  configured_at: '2026-08-01T10:00:00+00:00',
  ...over,
})

const listing = (providers: IntegrationProvider[]) => ({
  providers,
  summary: {
    total: providers.length,
    connectable: providers.filter((p) => p.connectable).length,
    needs_attention: providers.filter((p) => p.state === 'configuration_error').length,
  },
})

/**
 * PROVCFG-002 — the platform operator's provider console.
 *
 * The tests worth having here are the ones about what the page must NEVER do: display a stored
 * value, pre-fill a secret input, or call a complete-but-untested configuration ready.
 */
describe('ProviderSettingsPage', () => {
  beforeEach(() => {
    vi.mocked(fetchIntegrationProviders).mockResolvedValue(listing([provider()]))
  })

  afterEach(() => {
    vi.clearAllMocks()
    signOut()
  })

  it('names the exact missing credential rather than saying "not configured"', async () => {
    renderWithProviders(<ProviderSettingsPage />)

    const row = await screen.findByTestId('provider-row-google')
    expect(row).toHaveAttribute('data-state', 'awaiting_credentials')
    expect(row.textContent).toContain('developer_token')
  })

  it('offers exactly one action on a row, and the detail lives behind it', async () => {
    renderWithProviders(<ProviderSettingsPage />)

    const row = await screen.findByTestId('provider-row-google')
    expect(row.querySelectorAll('button')).toHaveLength(1)
    expect(screen.queryByTestId('provider-dialog-google')).toBeNull()

    fireEvent.click(screen.getByTestId('provider-configure-google'))
    expect(await screen.findByTestId('provider-dialog-google')).toBeInTheDocument()
  })

  /**
   * The single most important assertion in this file.
   *
   * A page that pre-filled a stored secret would put it in the DOM, in the page source, and in every
   * screenshot an operator ever took of this screen.
   */
  it('never pre-fills a stored credential, and shows only a four-character hint', async () => {
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    const input = await screen.findByTestId('provider-input-google-client_secret')
    expect(input).toHaveValue('')
    expect(input).toHaveAttribute('type', 'password')

    const state = screen.getByTestId('provider-field-state-google-client_secret')
    expect(state).toHaveAttribute('data-present', 'true')
    expect(state.textContent).toContain('wxyz')
  })

  it('submits only the fields that were typed into, so an untouched secret is left alone', async () => {
    vi.mocked(saveIntegrationProvider).mockResolvedValue({ ...provider(), fields_changed: ['developer_token'] })
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    fireEvent.change(screen.getByTestId('provider-input-google-developer_token'), { target: { value: 'dev-token' } })
    fireEvent.click(screen.getByTestId('provider-save-google'))

    await waitFor(() => expect(saveIntegrationProvider).toHaveBeenCalledWith('google', {
      developer_token: 'dev-token',
      environment: 'sandbox',
    }))
  })

  it('refuses to test a configuration that is still missing a required value', async () => {
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    expect(screen.getByTestId('provider-test-run-google')).toBeDisabled()
  })

  /** «جاهز للربط» is not «جاهز للإنتاج». A complete form is not evidence. */
  it('separates a complete configuration from a proven one', async () => {
    vi.mocked(fetchIntegrationProviders).mockResolvedValue(listing([
      provider({ key: 'meta', state: 'ready_to_connect', missing: [], connectable: true }),
      provider({
        key: 'tiktok', state: 'production_ready', missing: [], connectable: true,
        environment: 'production', last_test_status: 'passed', last_tested_at: '2026-08-04T09:00:00+00:00',
      }),
    ]))

    renderWithProviders(<ProviderSettingsPage />)

    expect(await screen.findByTestId('provider-state-meta')).toHaveTextContent('Ready to connect')
    expect(screen.getByTestId('provider-state-tiktok')).toHaveTextContent('Production ready')
  })

  it('shows the provider\'s own refusal on a failed configuration', async () => {
    vi.mocked(fetchIntegrationProviders).mockResolvedValue(listing([
      provider({
        state: 'configuration_error', missing: [], last_test_status: 'failed',
        last_test_message: 'The provider does not recognise this client id and secret.',
      }),
    ]))

    renderWithProviders(<ProviderSettingsPage />)

    expect(await screen.findByTestId('provider-row-google'))
      .toHaveTextContent('does not recognise this client id')
  })

  /** Disabling is destructive-sounding and is not; the page has to say which, and record why. */
  it('requires a reason before a provider can be disabled', async () => {
    vi.mocked(setIntegrationProviderEnabled).mockResolvedValue(provider({ enabled: false }))
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    expect(screen.getByTestId('provider-toggle-google')).toBeDisabled()

    fireEvent.change(screen.getByTestId('provider-disable-reason-google'), {
      target: { value: 'Rotating the app registration.' },
    })
    fireEvent.click(screen.getByTestId('provider-toggle-google'))

    await waitFor(() => expect(setIntegrationProviderEnabled)
      .toHaveBeenCalledWith('google', false, 'Rotating the app registration.'))
  })

  /** A provider that cannot receive events is not shown a webhook URL to register. */
  it('states how a provider is kept up to date, and offers no webhook URL when there is none', async () => {
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    expect(screen.getByTestId('provider-webhooks-google').textContent)
      .toContain('the scheduled sync is the source')
    expect(screen.queryByText('Webhook URL')).toBeNull()
  })

  it('surfaces the redirect URI to be copied into the provider console', async () => {
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    expect(screen.getByText('https://example.test/api/v1/oauth/ads/google/callback')).toBeInTheDocument()
  })

  /** The prerequisites are the reason a correct key still fails, so they come before the form. */
  it('states what must be obtained outside this product', async () => {
    renderWithProviders(<ProviderSettingsPage />)
    fireEvent.click(await screen.findByTestId('provider-configure-google'))

    expect(screen.getByText('A developer token approved for Basic Access.')).toBeInTheDocument()
  })
})
