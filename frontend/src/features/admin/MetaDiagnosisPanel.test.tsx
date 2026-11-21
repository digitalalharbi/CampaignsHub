import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { MetaDiagnosisPanel } from './MetaDiagnosisPanel'
import { renderWithProviders } from '@/test/utils'
import type { MetaDiagnosisConnection } from './metaDiagnosisApi'

vi.mock('./metaDiagnosisApi', () => ({ fetchMetaDiagnosis: vi.fn() }))

import { fetchMetaDiagnosis } from './metaDiagnosisApi'

const connection = (over: Partial<MetaDiagnosisConnection> = {}): MetaDiagnosisConnection => ({
  id: 'c1',
  tenant: 'Agency',
  name: 'Meta',
  app: 'live',
  status: 'error',
  state: 'needs_reconnect',
  token: { state: 'valid', expires_at: '2026-12-01T00:00:00+00:00' },
  scopes_granted: ['ads_read'],
  scopes_missing: ['business_management'],
  refusal: { kind: 'session_invalid', actor: 'customer', code: 190, subcode: 460, trace: 'AbC', action_ar: 'أعد الربط', action_en: 'Reconnect from this card' },
  last_error: 'Error validating access token · code 190 · subcode 460 · trace AbC',
  insights_denied_at: null,
  discovery_blocked_reason: null,
  last_discovery_succeeded_at: null,
  last_sync_at: '2026-10-25T10:00:00+00:00',
  accounts_total: 2,
  accounts_selected: 2,
  accounts_synced: 1,
  expired_media: 117,
  refusals_24h: { rate_limited: 3 },
  accounts: [
    {
      external_id: 'act_1',
      name: 'Hanka',
      status: 'active',
      health: 'revoked',
      selected: true,
      access_lost_at: null,
      last_metrics_at: null,
      error_category: 'token_invalidated',
      creatives: 200,
      expired_media: 117,
      structure: {
        status: 'failed',
        at: '2026-10-25T10:00:00+00:00',
        refusal: { kind: 'permission_missing', actor: 'customer', code: 200, subcode: null, trace: null, action_ar: 'امنح الصلاحية', action_en: 'Grant ads_read' },
        media: { asked: 40, resolved: 1, not_asked: 39, stopped_by: 'rate_limited' },
        media_renewal: { expired: 117, asked: 100, renewed: 0, still_expired: 117, stopped_by: null },
        usage: { max_call_count: 42, regain_minutes: 0 },
      },
      metrics: { status: 'failed', at: null, refusal: null },
    },
  ],
  ...over,
})

describe('the Meta diagnosis panel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('states the state, the code and the one action, with the evidence behind a disclosure', async () => {
    vi.mocked(fetchMetaDiagnosis).mockResolvedValue({ connections: [connection()] })
    renderWithProviders(<MetaDiagnosisPanel />, { locale: 'en' })

    const card = await screen.findByTestId('meta-diagnosis-c1')
    expect(card).toHaveAttribute('data-state', 'needs_reconnect')
    expect(within(card).getByText('Needs reconnecting')).toBeInTheDocument()
    expect(within(card).getByTestId('meta-diagnosis-action')).toHaveTextContent('Reconnect from this card')
    expect(within(card).getByTestId('meta-diagnosis-code')).toHaveTextContent('code 190')
    expect(within(card).getByTestId('meta-diagnosis-code')).toHaveTextContent('subcode 460')
    expect(within(card).getByText(/business_management/)).toBeInTheDocument()
    expect(within(card).getByTestId('meta-diagnosis-raw')).toHaveTextContent('Error validating access token')
  })

  it('names expired media on the account with what the renewal could do', async () => {
    vi.mocked(fetchMetaDiagnosis).mockResolvedValue({ connections: [connection()] })
    renderWithProviders(<MetaDiagnosisPanel />, { locale: 'en' })

    const account = await screen.findByTestId('meta-diagnosis-account-act_1')
    expect(account).toHaveTextContent('117')
    expect(account).toHaveTextContent('code 200')
    expect(within(account).getByTestId('meta-diagnosis-account-action')).toHaveTextContent('Grant ads_read')
    expect(account).toHaveTextContent(/stopped: rate limit/i)
  })

  it('writes Arabic copy with Latin digits', async () => {
    vi.mocked(fetchMetaDiagnosis).mockResolvedValue({ connections: [connection()] })
    renderWithProviders(<MetaDiagnosisPanel />, { locale: 'ar' })

    const card = await screen.findByTestId('meta-diagnosis-c1')
    expect(within(card).getByText('يحتاج إعادة ربط')).toBeInTheDocument()
    expect(within(card).getByTestId('meta-diagnosis-action')).toHaveTextContent('أعد الربط')
    expect(card.textContent).not.toMatch(/[٠-٩]/)
  })

  it('does not call a never-synced connection working', async () => {
    vi.mocked(fetchMetaDiagnosis).mockResolvedValue({
      connections: [connection({ state: 'never_synced', status: 'connected', refusal: null, last_error: null, last_sync_at: null, accounts: [], expired_media: 0 })],
    })
    renderWithProviders(<MetaDiagnosisPanel />, { locale: 'en' })

    expect(await screen.findByText('Connected, nothing synced yet')).toBeInTheDocument()
    expect(screen.queryByText(/^Syncing$/)).not.toBeInTheDocument()
  })

  it('says so when there is no connection, and when the read fails', async () => {
    vi.mocked(fetchMetaDiagnosis).mockResolvedValueOnce({ connections: [] })
    renderWithProviders(<MetaDiagnosisPanel />, { locale: 'en' })
    expect(await screen.findByText('No Meta connection on this install.')).toBeInTheDocument()
  })
})
