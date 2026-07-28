import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ConnectionCenterPage } from './ConnectionCenterPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import type { Connector } from './api'

vi.mock('./api', () => ({
  listConnectors: vi.fn(),
  syncConnector: vi.fn(),
  getConnectionHistory: vi.fn(),
}))

import { getConnectionHistory, listConnectors, syncConnector } from './api'

function connector(overrides: Partial<Connector> = {}): Connector {
  return {
    provider: 'sandbox', label: 'Sandbox (demo data)', capabilities: ['metrics_sync', 'oauth'],
    is_sandbox: true, has_credentials: true, awaiting_external_dependency: false,
    state: 'sandbox_verified', state_label: 'Sandbox Verified', is_healthy: true,
    connection: null, ...overrides,
  }
}

describe('ConnectionCenterPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(listConnectors).mockResolvedValue([
      connector(),
      connector({ provider: 'meta_ads', label: 'Meta Ads', is_sandbox: false, has_credentials: false, awaiting_external_dependency: true, state: 'awaiting_credentials', state_label: 'Awaiting Credentials', is_healthy: false }),
    ])
    vi.mocked(getConnectionHistory).mockResolvedValue({ provider: 'sandbox', runs: [], errors: [], data_freshness: { last_run_at: null, last_status: null, metrics_upserted: 0 } })
  })
  afterEach(() => signOut())

  it('blocks without integrations.view', () => {
    signInWith([])
    renderWithProviders(<ConnectionCenterPage />)
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  it('asks to pick a project when none is selected', () => {
    useProject.getState().setCurrentProjectId(null)
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    expect(screen.getByText('Select a project')).toBeInTheDocument()
  })

  it('lists connectors with capabilities and the honest state badge', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    expect(await screen.findByText('Sandbox (demo data)')).toBeInTheDocument()
    expect(screen.getByText('Sandbox Verified')).toBeInTheDocument()
    // The real provider without credentials is honestly Awaiting — never "Connected".
    expect(screen.getByText('Awaiting Credentials')).toBeInTheDocument()
    // No badge ever reads a bare "Connected" — only the honest states are rendered.
    expect(screen.queryByText('Connected')).not.toBeInTheDocument()
    expect(screen.getAllByText('metrics_sync').length).toBeGreaterThan(0)
  })

  it('opens the sync history panel for a connector', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    await screen.findByText('Sandbox (demo data)')
    fireEvent.click(screen.getAllByText('Sync history')[0])
    await waitFor(() => expect(getConnectionHistory).toHaveBeenCalledWith('p1', 'sandbox'))
    expect(await screen.findByText('Data freshness')).toBeInTheDocument()
    expect(screen.getByText('Last run')).toBeInTheDocument()
  })

  it('does not call sync on initial render (only on demand)', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    await screen.findByText('Sandbox (demo data)')
    expect(syncConnector).not.toHaveBeenCalled()
  })
})
