import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { MetaSaasPage } from './MetaCandidatePage'
import type { MetaCandidateState } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMetaSaas: vi.fn(), setMetaSaasRouting: vi.fn(), fetchMetaCandidate: vi.fn(), promoteMetaCandidate: vi.fn() }
})

import { fetchMetaCandidate, fetchMetaSaas, promoteMetaCandidate, setMetaSaasRouting } from './api'

const state = (routing: NonNullable<MetaCandidateState['routing']>, readiness?: MetaCandidateState['readiness']): MetaCandidateState => ({
  credentials: {
    profile: 'saas', configured: true, missing: [], effective_scopes: ['ads_read'], redirect_uri: 'https://api.example.test/api/v1/oauth/ads/meta/callback',
    values: [
      { key: 'client_id', secret: false, present: true, source: 'stored', hint: '7777' },
      { key: 'client_secret', secret: true, present: true, source: 'stored', hint: 'CCCC' },
      { key: 'config_id', secret: false, present: true, source: 'stored', hint: '8888' },
    ],
    configured_at: null,
  },
  latest_run: null,
  routing,
  connections_by_app: { live: 4, saas: 0 },
  readiness,
})

const PRE = ['credentials_configured', 'round_trip_passed', 'test_journey_complete', 'meta_approval_recorded', 'live_still_configured']
const preview = { existing_live_connections: 2, existing_live_tenants: 1, existing_saas_connections: 0, existing_connections_moved: 0 }
const modes = (current: 'live' | 'saas_test' | 'saas_primary', unmet: string[] = []): NonNullable<MetaCandidateState['modes']> => ({
  current,
  primary: { enabled: current === 'saas_primary', eligible: unmet.length === 0, preconditions: PRE.map((key) => ({ key, met: !unmet.includes(key) })), preview },
})
const withModes = (m: NonNullable<MetaCandidateState['modes']>): MetaCandidateState => ({ ...state({ default_for_new_connections: m.current === 'saas_primary' ? 'saas' : 'live', eligible: false, reason: null }), modes: m })

/** META-SAAS-APP-001 — the third app's page: its own endpoints, no promote, and the Live / SaaS Test / SaaS Primary modes. */
describe('SaaS Meta app page', () => {
  it('reads the SaaS endpoints, offers no promotion, and keeps Primary locked with the conditions not met', async () => {
    vi.mocked(fetchMetaSaas).mockResolvedValue(withModes(modes('live', ['round_trip_passed', 'test_journey_complete', 'meta_approval_recorded'])))
    renderWithProviders(<MetaSaasPage />, { locale: 'en' })

    expect(await screen.findByTestId('admin-meta-saas')).toBeInTheDocument()
    expect(fetchMetaCandidate).not.toHaveBeenCalled()
    expect(screen.queryByTestId('meta-candidate-promotion')).not.toBeInTheDocument()
    expect(screen.getByTestId('meta-saas-mode-live')).toHaveAttribute('aria-current', 'true')
    expect(screen.getByTestId('meta-saas-precondition-test_journey_complete')).toHaveAttribute('data-met', 'false')
    expect(screen.getByTestId('meta-saas-precondition-credentials_configured')).toHaveAttribute('data-met', 'true')
    expect(screen.getByTestId('meta-saas-primary-locked')).toBeInTheDocument()
    expect(screen.getByTestId('meta-saas-primary-enable')).toBeDisabled()
    expect(screen.getByTestId('meta-saas-primary-approve')).toBeDisabled()
    expect(screen.getByTestId('meta-saas-preview-moved')).toHaveTextContent(String(preview.existing_connections_moved))
    expect(promoteMetaCandidate).not.toHaveBeenCalled()
  })

  it.each([['ar'], ['en']] as const)('needs both named statements before Primary, then offers only a revert (%s)', async (locale) => {
    vi.mocked(fetchMetaSaas).mockResolvedValue(withModes(modes('saas_test')))
    vi.mocked(setMetaSaasRouting).mockResolvedValue(withModes(modes('saas_primary')))
    renderWithProviders(<MetaSaasPage />, { locale })

    const enable = await screen.findByTestId('meta-saas-primary-enable')
    expect(enable).toBeDisabled()
    fireEvent.click(screen.getByTestId('meta-saas-primary-approve'))
    expect(enable).toBeDisabled()
    fireEvent.click(screen.getByTestId('meta-saas-primary-acknowledge'))
    fireEvent.click(enable)
    await waitFor(() => expect(vi.mocked(setMetaSaasRouting).mock.calls.at(-1)?.[0]).toBe('saas'))
    expect(await screen.findByTestId('meta-saas-primary-revert')).toBeInTheDocument()
    expect(screen.getByTestId('meta-saas-mode-saas_primary')).toHaveAttribute('aria-current', 'true')
  })
})
