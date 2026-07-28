import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ConnectionCenterPage, providerCategory } from './ConnectionCenterPage'
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

describe('providerCategory mapping', () => {
  it('maps the 16 providers into their category buckets, Google Drive → files', () => {
    expect(providerCategory('meta_ads')).toBe('ads')
    expect(providerCategory('google_ads')).toBe('ads')
    expect(providerCategory('ga4')).toBe('analytics')
    expect(providerCategory('google_tag_manager')).toBe('analytics')
    expect(providerCategory('salla')).toBe('stores')
    expect(providerCategory('shopify')).toBe('stores')
    expect(providerCategory('google_drive')).toBe('files')
    expect(providerCategory('crm')).toBe('other')
    // Unknown providers fall to "other" (honest default).
    expect(providerCategory('mystery_provider')).toBe('other')
  })
})

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

  it('renders category tabs, a grid of cards, and honest state badges', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    // Grid cards
    expect(await screen.findByText('Sandbox (demo data)')).toBeInTheDocument()
    expect(screen.getByText('Meta Ads')).toBeInTheDocument()
    // Honest badges — never a bare "Connected".
    expect(screen.getByText('Sandbox Verified')).toBeInTheDocument()
    expect(screen.getByText('Awaiting Credentials')).toBeInTheDocument()
    expect(screen.queryByText('Connected')).not.toBeInTheDocument()
    // Category tabs are derived from the present connectors (Ads + Other, plus All).
    const tabs = screen.getAllByRole('tab')
    const tabNames = tabs.map((t) => t.textContent?.trim())
    expect(tabNames).toContain('All')
    expect(tabNames).toContain('Ads')
    expect(tabNames).toContain('Other')
  })

  it('filters the grid by category tab', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    await screen.findByText('Sandbox (demo data)')
    // Switch to the Ads tab — only Meta Ads remains, Sandbox (Other) is filtered out.
    fireEvent.click(screen.getByRole('tab', { name: /Ads/ }))
    expect(screen.getByText('Meta Ads')).toBeInTheDocument()
    expect(screen.queryByText('Sandbox (demo data)')).not.toBeInTheDocument()
  })

  it('opens the details drawer with account, capabilities and sync history', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    fireEvent.click(await screen.findByText('Sandbox (demo data)'))
    // Drawer dialog opens with detail sections.
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.getByText('Permissions & capabilities')).toBeInTheDocument()
    expect(screen.getAllByText('metrics_sync').length).toBeGreaterThan(0)
    // History loads for the opened provider.
    await waitFor(() => expect(getConnectionHistory).toHaveBeenCalledWith('p1', 'sandbox'))
    expect(screen.getByText('Sync history')).toBeInTheDocument()
    expect(await screen.findByText('Last run')).toBeInTheDocument()
  })

  it('does not call sync on initial render (only on demand)', async () => {
    signInWith(['integrations.view'])
    renderWithProviders(<ConnectionCenterPage />)
    await screen.findByText('Sandbox (demo data)')
    expect(syncConnector).not.toHaveBeenCalled()
  })
})
