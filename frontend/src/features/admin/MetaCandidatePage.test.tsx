import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { MetaCandidatePage } from './MetaCandidatePage'
import type { MetaCandidateRun, MetaCandidateState } from './api'
import { renderWithProviders, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    fetchMetaCandidate: vi.fn(),
    saveMetaCandidate: vi.fn(),
    startMetaCandidateTest: vi.fn(),
    promoteMetaCandidate: vi.fn(),
    rollbackMetaLive: vi.fn(),
    forgetMetaCandidateCredential: vi.fn(),
  }
})

import { fetchMetaCandidate, promoteMetaCandidate, rollbackMetaLive, saveMetaCandidate, startMetaCandidateTest } from './api'

const state = (over: Partial<MetaCandidateState['credentials']> = {}, run: MetaCandidateRun | null = null): MetaCandidateState => ({
  credentials: {
    profile: 'candidate',
    configured: true,
    missing: [],
    effective_scopes: ['ads_read'],
    redirect_uri: 'https://api.example.test/api/v1/oauth/ads/meta/callback',
    values: [
      { key: 'client_id', secret: false, present: true, source: 'stored', hint: '2222' },
      { key: 'client_secret', secret: true, present: true, source: 'stored', hint: 'BBBB' },
      { key: 'config_id', secret: false, present: true, source: 'environment', hint: '3333' },
    ],
    configured_at: null,
    ...over,
  },
  latest_run: run,
})

const failedRun: MetaCandidateRun = {
  id: 'r1', profile: 'candidate', status: 'failed', app_id_hint: '2222',
  started_at: '2026-09-17T10:00:00+00:00', finished_at: '2026-09-17T10:01:00+00:00', token_expires_at: null,
  granted_scopes: [], discovered_accounts: [],
  steps: [
    { key: 'oauth_start', status: 'ok' },
    { key: 'consent', status: 'ok' },
    { key: 'token_exchange', status: 'failed', error: { http_status: 400, code: 1, subcode: 33, type: 'OAuthException', fbtrace_id: 'TRACE123', message: 'Error validating client secret' } },
    { key: 'account_discovery', status: 'pending' },
    { key: 'ads_read', status: 'pending' },
  ],
}

describe('MetaCandidatePage (META-CANDIDATE-001)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('shows the scopes the candidate profile actually requests, not a hard-coded list', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue(state())
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    const scopes = await screen.findByTestId('meta-candidate-effective-scopes')
    expect(scopes).toHaveTextContent('ads_read')
    expect(scopes).not.toHaveTextContent('ads_management')
    expect(scopes).not.toHaveTextContent('business_management')
  })

  it('never renders a stored value, only a hint, and keeps inputs empty', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue(state())
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    const secret = await screen.findByTestId('meta-candidate-input-client_secret')
    expect(secret).toHaveValue('')
    expect(secret).toHaveAttribute('type', 'password')
    expect(screen.getByText(/••••BBBB/)).toBeInTheDocument()
  })

  it('shows every step of the latest run with Meta’s code and fbtrace_id', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue(state({}, failedRun))
    renderWithProviders(<MetaCandidatePage />, { route: '/admin/settings/integrations/meta-candidate?outcome=failed', locale: 'en' })

    expect(await screen.findByTestId('meta-candidate-step-token_exchange')).toHaveAttribute('data-status', 'failed')
    expect(screen.getByTestId('meta-candidate-step-token_exchange')).toHaveTextContent('code 1 · subcode 33 · OAuthException · fbtrace_id TRACE123')
    expect(screen.getByTestId('meta-candidate-step-oauth_start')).toHaveAttribute('data-status', 'ok')
    expect(screen.getByTestId('meta-candidate-step-ads_read')).toHaveAttribute('data-status', 'pending')
    expect(screen.getByTestId('meta-candidate-outcome')).toHaveTextContent(/failed/i)
  })

  it('cannot start a test while the candidate is incomplete', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue(state({ configured: false, missing: ['config_id'] }))
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    const run = await screen.findByTestId('meta-candidate-run')
    expect(run).toBeDisabled()
    fireEvent.click(run)
    await waitFor(() => expect(startMetaCandidateTest).not.toHaveBeenCalled())
  })

  it('refuses promotion with the server reason and offers no rollback when none exists', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue({
      ...state({}, failedRun),
      promotion: { eligible: false, reason: 'latest_round_trip_not_succeeded', rollback_available: false },
    })
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    expect(await screen.findByTestId('meta-candidate-promote')).toBeDisabled()
    expect(screen.getByTestId('meta-candidate-rollback')).toBeDisabled()
    expect(screen.getByTestId('meta-candidate-promotion-reason')).toHaveTextContent('did not pass end to end')
    fireEvent.click(screen.getByTestId('meta-candidate-promote'))
    expect(promoteMetaCandidate).not.toHaveBeenCalled()
  })

  it('promotes and rolls back only after an explicit confirmation', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue({
      ...state(),
      promotion: { eligible: true, reason: null, rollback_available: true },
    })
    const after = { ...state(), promotion: { eligible: false, reason: 'candidate_already_live', rollback_available: true } }
    vi.mocked(promoteMetaCandidate).mockResolvedValue(after)
    vi.mocked(rollbackMetaLive).mockResolvedValue(after)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValue(true)
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('meta-candidate-promote'))
    expect(promoteMetaCandidate).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('meta-candidate-promote'))
    await waitFor(() => expect(promoteMetaCandidate).toHaveBeenCalledTimes(1))

    fireEvent.click(screen.getByTestId('meta-candidate-rollback'))
    await waitFor(() => expect(rollbackMetaLive).toHaveBeenCalledTimes(1))
    confirm.mockRestore()
  })

  it('saves only the fields that were typed', async () => {
    vi.mocked(fetchMetaCandidate).mockResolvedValue(state())
    vi.mocked(saveMetaCandidate).mockResolvedValue({ ...state(), fields_changed: ['config_id'] })
    renderWithProviders(<MetaCandidatePage />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('meta-candidate-input-config_id'), { target: { value: 'cfg-9' } })
    fireEvent.click(screen.getByTestId('meta-candidate-save'))

    await waitFor(() => expect(saveMetaCandidate).toHaveBeenCalledWith({ client_id: undefined, client_secret: undefined, config_id: 'cfg-9' }))
  })
})
