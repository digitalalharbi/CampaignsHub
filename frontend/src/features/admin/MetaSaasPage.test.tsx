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

const state = (routing: NonNullable<MetaCandidateState['routing']>): MetaCandidateState => ({
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
})

/** META-SAAS-APP-001 — the third app's page: its own endpoints, a routing choice for NEW connections, and no promote. */
describe('SaaS Meta app page', () => {
  it('reads the SaaS endpoints, offers no promotion, and refuses the switch until a test passed', async () => {
    vi.mocked(fetchMetaSaas).mockResolvedValue(state({ default_for_new_connections: 'live', eligible: false, reason: 'no_successful_test' }))
    renderWithProviders(<MetaSaasPage />, { locale: 'en' })

    expect(await screen.findByTestId('admin-meta-saas')).toBeInTheDocument()
    expect(fetchMetaCandidate).not.toHaveBeenCalled()
    expect(screen.queryByTestId('meta-candidate-promotion')).not.toBeInTheDocument()
    expect(screen.getByTestId('meta-saas-routing-current')).toHaveTextContent('Live 4 · SaaS 0')
    expect(screen.getByTestId('meta-saas-routing-reason')).toHaveTextContent('The connection test has not passed yet.')
    expect(screen.getByTestId('meta-saas-use-saas')).toBeDisabled()
    expect(promoteMetaCandidate).not.toHaveBeenCalled()
  })

  it('switches NEW connections to the SaaS app once eligible', async () => {
    vi.mocked(fetchMetaSaas).mockResolvedValue(state({ default_for_new_connections: 'live', eligible: true, reason: null }))
    vi.mocked(setMetaSaasRouting).mockResolvedValue(state({ default_for_new_connections: 'saas', eligible: true, reason: null }))
    renderWithProviders(<MetaSaasPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('meta-saas-use-saas'))
    await waitFor(() => expect(vi.mocked(setMetaSaasRouting).mock.calls[0]?.[0]).toBe('saas'))
    expect(await screen.findByTestId('meta-saas-use-live')).toBeInTheDocument()
  })
})
