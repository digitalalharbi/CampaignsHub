import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Routes, Route, useLocation } from 'react-router-dom'
import { IntegrationsPage } from './IntegrationsPage'
import { AgencyScopeSwitcher } from '@/features/agency/AgencyScopeSwitcher'
import type { ClientIntegrationRow, ConnectionHub as Hub, HubConnection, IntegrationStatus, IntegrationStatuses } from './api'
import { renderWithProviders } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'

/** Nov overhaul, task 7 — the integrations centre: stays put on a client change, all clients at once, «متصل», the facts of a source. */
const state = vi.hoisted(() => ({
  hub: { connections: [], connectable: [] } as Hub,
  overview: [] as ClientIntegrationRow[],
  projects: [] as Array<{ id: string; name: string; client_workspace_id: string | null }>,
  clients: [] as Array<{ id: string; name: string }>,
}))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchConnectionHub: () => Promise.resolve(state.hub),
  fetchConnectionHubWithRemoved: () => Promise.resolve(state.hub),
  fetchClientsIntegrationOverview: () => Promise.resolve(state.overview),
  fetchEstate: () => Promise.resolve({ projects: [] }),
  listConnectors: () => Promise.resolve([]),
  fetchIntegrationAudit: () => Promise.resolve({ entries: [], page_sizes: [10], meta: { total: 0, per_page: 10, current_page: 1, last_page: 1 } }),
  fetchMetaApps: () => Promise.resolve({ default: 'live', apps: [{ key: 'live', available: true }] }),
}))
vi.mock('./ConnectionWizard', () => ({ ConnectionWizard: () => <div /> }))
vi.mock('@/features/commerce/StoresPanel', () => ({ StoresPanel: () => <div /> }))
vi.mock('@/features/measurement/PropertiesPanel', () => ({ PropertiesPanel: () => <div /> }))
vi.mock('./SourcePlatformRows', () => ({ SourcePlatformRows: () => null }))
vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve(state.clients),
  listProjects: () => Promise.resolve(state.projects),
}))

const st = (s: string, ar: string, en: string, action: IntegrationStatus['action'] = null): IntegrationStatus => ({ state: s, label_ar: ar, label_en: en, since: null, as_of: null, detail: null, action })
const healthy = (): IntegrationStatuses => ({
  authorisation: st('connected', 'مفوَّض', 'Authorised'), binding: st('bound', 'مرتبط بمشروع', 'Linked'), sync: st('succeeded', 'نجحت آخر مزامنة', 'ok'),
  freshness: st('fresh', 'حديثة', 'Fresh'), error: st('none', 'لا خطأ', 'No error'),
})
const conn = (over: Partial<HubConnection> = {}): HubConnection => ({
  id: 'c-1', provider: 'snapchat', label: 'Snapchat Ads', label_ar: 'سناب شات', connection_name: 'Snapchat', authorised_by: null, provider_account: null,
  removed_at: null, abandoned: false, authorised_at: '2026-09-01T09:00:00+00:00', selected_accounts: 2, discovered_accounts: 3, connection_state: 'CONNECTED',
  sync_state: 'SUCCEEDED', needs_attention: false, last_success_at: new Date(Date.now() - 3 * 3600_000).toISOString(), next_sync_at: null, projects: [], has_parent: true,
  discovery_blocked_reason: null, token_expires_at: null, statuses: healthy(), status_profile: null, ...over,
})
const SNAP = { key: 'snapchat', label: 'Snapchat Ads', label_ar: 'سناب شات', kind: 'advertising', has_parent: true, auth: 'oauth' } as const
const row = (over: Partial<ClientIntegrationRow> = {}): ClientIntegrationRow => ({ client_id: 'k1', client_name: 'Alpha', projects: 1, sources: 1, platforms: ['meta'], needs_action: false, issues: [], last_success_at: null, ...over })

beforeEach(() => {
  useProject.setState({ currentProjectId: 'p-1' })
  useAgencyClient.setState({ currentClientId: null })
  state.hub = { connections: [], connectable: [SNAP] }
  state.overview = []
  state.projects = [{ id: 'p-1', name: 'Razzah', client_workspace_id: 'k1' }]
  state.clients = []
})

describe('platforms tab', () => {
  it('a healthy connected platform says «متصل» and its re-link is a secondary button', async () => {
    state.hub = { connections: [conn()], connectable: [SNAP] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/app/integrations' })

    fireEvent.click(await screen.findByTestId('hub-section-link-platforms'))
    expect(await screen.findByTestId('hub-platform-connected-snapchat')).toHaveTextContent('متصل')
    const relink = screen.getByTestId('hub-platform-link-snapchat')
    expect(relink).toHaveTextContent('إعادة الربط')
    expect(relink.className).toContain('border-border-strong')
  })

  it('a platform that asks for an action says so instead of «متصل»', async () => {
    const asks = healthy()
    asks.authorisation = st('reauth_required', 'يلزم إعادة المصادقة', 'Re-authorisation required', { code: 'reauthorize', label_ar: 'أعد المصادقة', label_en: 'Re-authorise' })
    state.hub = { connections: [conn({ statuses: asks, connection_state: 'REAUTH_REQUIRED' })], connectable: [SNAP] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/app/integrations' })

    fireEvent.click(await screen.findByTestId('hub-section-link-platforms'))
    expect(await screen.findByTestId('hub-platform-attention-snapchat')).toHaveTextContent('يحتاج إجراء')
    expect(screen.queryByTestId('hub-platform-connected-snapchat')).toBeNull()
  })
})

describe('what a source shows', () => {
  it('authorisation, last successful sync, data age and — when it failed — the reason in plain words', async () => {
    state.hub = { connections: [conn({ needs_attention: true, sync_state: 'FAILED', last_error_category: 'token_invalidated' })], connectable: [SNAP] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/app/integrations' })

    fireEvent.click(await screen.findByTestId('hub-expand-c-1'))
    expect(screen.getByTestId('hub-authorisation-c-1')).toHaveTextContent('مفوَّض')
    expect(screen.getByTestId('hub-data-age-c-1')).toHaveTextContent('قبل 3 ساعات')
    expect(screen.getByTestId('hub-failure-c-1')).toHaveTextContent('لم تعد المنصة تقبل التفويض')
  })

  it('a healthy source has no failure line', async () => {
    state.hub = { connections: [conn()], connectable: [SNAP] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/app/integrations' })

    fireEvent.click(await screen.findByTestId('hub-expand-c-1'))
    expect(screen.queryByTestId('hub-failure-c-1')).toBeNull()
  })
})

describe('the agency view of every client', () => {
  it('lists all clients at once and filters to «يحتاج إجراء»', async () => {
    state.overview = [
      row({ client_id: 'k1', client_name: 'Alpha', needs_action: true, issues: [{ provider: 'meta', account: 'Main', reason: 'failed', error_category: 'token_invalidated', last_synced_at: null }] }),
      row({ client_id: 'k2', client_name: 'Beta' }),
      row({ client_id: 'k3', client_name: 'Delta', sources: 0, platforms: [] }),
    ]
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/agency/integrations?view=all' })

    expect(await screen.findByTestId('all-clients-row-k1')).toHaveTextContent('لم تعد المنصة تقبل التفويض')
    expect(screen.getByTestId('all-clients-row-k2')).toBeInTheDocument()
    expect(screen.getByTestId('all-clients-row-k3')).toHaveTextContent('لا مصادر')
    expect(screen.getByTestId('all-clients-needs-count')).toHaveTextContent('1')

    fireEvent.click(screen.getByTestId('all-clients-filter-action'))
    expect(screen.getByTestId('all-clients-row-k1')).toBeInTheDocument()
    expect(screen.queryByTestId('all-clients-row-k2')).toBeNull()
    expect(screen.queryByTestId('all-clients-row-k3')).toBeNull()
  })

  it('opening a client makes it the working one and returns to the client view on the same page', async () => {
    state.overview = [row({ client_id: 'k1', client_name: 'Alpha' })]
    useProject.setState({ currentProjectId: null })
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/agency/integrations?view=all' })

    fireEvent.click(await screen.findByTestId('all-clients-open-k1'))
    await waitFor(() => expect(useAgencyClient.getState().currentClientId).toBe('k1'))
    await waitFor(() => expect(screen.queryByTestId('all-clients-integrations')).toBeNull())
  })

  it('the second view exists for the agency only', async () => {
    renderWithProviders(<IntegrationsPage />, { locale: 'ar', route: '/app/integrations' })
    await screen.findByTestId('hub-section-link-platforms')
    expect(screen.queryByTestId('integrations-view')).toBeNull()
  })
})

describe('changing the client in the side menu', () => {
  function Where() {
    const l = useLocation()

    return <span data-testid="where">{l.pathname}{l.search}</span>
  }

  it('keeps the person on /agency/integrations', async () => {
    state.clients = [{ id: 'k1', name: 'Alpha' }, { id: 'k2', name: 'Beta' }]
    state.projects = [{ id: 'p-1', name: 'Razzah', client_workspace_id: 'k1' }, { id: 'p-2', name: 'Other', client_workspace_id: 'k2' }, { id: 'p-3', name: 'Other 2', client_workspace_id: 'k2' }]
    useProject.setState({ currentProjectId: null })
    useAgencyClient.setState({ currentClientId: 'k1' })
    renderWithProviders(
      <>
        <AgencyScopeSwitcher />
        <Routes><Route path="/agency/integrations" element={<IntegrationsPage />} /><Route path="*" element={<span data-testid="elsewhere" />} /></Routes>
        <Where />
      </>,
      { locale: 'ar', route: '/agency/integrations' },
    )

    fireEvent.click(await screen.findByTestId('agency-scope-client'))
    fireEvent.click(await screen.findByTestId('agency-scope-client-client-k2'))

    await waitFor(() => expect(useAgencyClient.getState().currentClientId).toBe('k2'))
    expect(screen.getByTestId('where')).toHaveTextContent('/agency/integrations')
    expect(screen.queryByTestId('elsewhere')).toBeNull()
    // CONS-CLIENT-PROJECT — a client with several projects now opens in its most recently active one (picker kept),
    // where it used to open on the chooser. Still on the same page.
    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('p-2'))
    expect(screen.getByTestId('where')).toHaveTextContent('/agency/integrations')
  })
})
