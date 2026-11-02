import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { MetaSaasRegistry } from './MetaSaasRegistry'
import type { MetaAppRegistryState } from './api'
import { renderWithProviders } from '@/test/utils'

const registry: MetaAppRegistryState = {
  unattributed: 0,
  apps: [
    { profile: 'live', role: 'legacy_sync_only', app_id: '2522000000000001', configured: true, connections: 17, verified_connections: 0, unverified_connections: 17 },
    { profile: 'saas', role: 'primary', app_id: '2604968763258530', configured: true, connections: 2, verified_connections: 2, unverified_connections: 0 },
    { profile: 'candidate', role: 'test_only', app_id: null, configured: false, connections: 0, verified_connections: 0, unverified_connections: 0 },
  ],
}

/** META-MULTI-APP-001 — the registry shows role, public App ID, configured flag and the unverified count, and never a secret. */
describe('Meta app registry', () => {
  it.each([['ar', true, 'أساسي'], ['en', false, 'Primary']] as const)('lists every app with its role and counts (%s)', (_l, ar, primary) => {
    renderWithProviders(<MetaSaasRegistry registry={registry} ar={ar} />, { locale: ar ? 'ar' : 'en' })

    expect(screen.getByTestId('meta-registry-saas-role')).toHaveTextContent(primary)
    expect(screen.getByTestId('meta-registry-saas')).toHaveAttribute('data-role', 'primary')
    expect(screen.getByTestId('meta-registry-saas')).toHaveTextContent('2604968763258530')
    expect(screen.getByTestId('meta-registry-live')).toHaveAttribute('data-role', 'legacy_sync_only')
    expect(screen.getByTestId('meta-registry-live-connections')).toHaveTextContent('17')
    expect(screen.getByTestId('meta-registry-live-unverified')).toHaveTextContent('17')
    expect(screen.getByTestId('meta-registry-saas-unverified')).toHaveTextContent('0')
    expect(screen.getByTestId('meta-registry-candidate')).toHaveTextContent('—')
    expect(screen.queryByTestId('meta-registry-unattributed')).toBeNull()
  })

  it('names connections verified on an unregistered App ID', () => {
    renderWithProviders(<MetaSaasRegistry registry={{ ...registry, unattributed: 3 }} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('meta-registry-unattributed')).toHaveTextContent('3')
  })
})
