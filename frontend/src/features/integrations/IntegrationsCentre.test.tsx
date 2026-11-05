import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import { findingsOf } from './IntegrationAudit'
import { sortSources } from './ConnectionHub'
import { statusTone } from './StatusChips'
import type {
  ConnectionHub as Hub, EstateProject, HubConnection, IntegrationAuditEntry, IntegrationStatus, IntegrationStatuses,
} from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEG-UNIFIED-002 (Owner contract 2026-11-05 §1.3, §1.12, §1.13) — the Integrations Centre.
 *
 * One Arabic RTL, Project-First page in three sections — «المشاريع», «المصادر المرتبطة», «التدقيق» — with
 * five independent statuses read from the server, the PLATFORM account as the identity of a source,
 * search, sort, re-authorise and remove on the row, and nothing discovered ever linked or synced by
 * merely being seen.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const estate = vi.hoisted(() => ({ projects: [] as EstateProject[] }))
const projects = vi.hoisted(() => ({ list: [] as Array<{ id: string; name: string; client_workspace_id: string | null }> }))
const calls = vi.hoisted(() => ({
  started: [] as Array<{ provider: string; projectId?: string | null }>,
  removed: [] as string[],
  audit: [] as Array<Record<string, unknown>>,
  writes: [] as string[],
}))
const audit = vi.hoisted(() => ({ entries: [] as IntegrationAuditEntry[], total: 0 }))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  const write = (name: string) => () => { calls.writes.push(name); return Promise.resolve({}) }
  return {
    ...actual,
    fetchConnectionHub: () => Promise.resolve(hub.data),
    fetchConnectionHubWithRemoved: () => Promise.resolve(hub.data),
    fetchEstate: () => Promise.resolve({ projects: estate.projects }),
    listConnectors: () => Promise.resolve([]),
    listAccounts: () => Promise.resolve({
      accounts: [], summary: { total: 0, linked: 0, unlinked: 0 },
      meta: { total: 0, per_page: 10, current_page: 1, last_page: 1 },
    }),
    getAccountLogs: () => Promise.resolve({ runs: [] }),
    fetchMetaApps: () => Promise.resolve({ default: 'live', apps: [{ key: 'live', available: true }] }),
    startPlatformOAuth: (provider: string, _workspace?: string | null, projectId?: string | null) => {
      calls.started.push({ provider, projectId })
      return Promise.resolve({ authorization_url: `https://platform.test/${provider}` })
    },
    removeConnection: (id: string) => { calls.removed.push(id); return Promise.resolve({ removed: true, disabled_bindings: 0 }) },
    fetchIntegrationAudit: (query: Record<string, unknown> = {}) => {
      calls.audit.push(query)
      const perPage = (query.perPage as number | undefined) ?? 10
      return Promise.resolve({
        entries: audit.entries.slice(0, perPage),
        page_sizes: [10, 25, 50],
        meta: { total: audit.total, per_page: perPage, current_page: (query.page as number | undefined) ?? 1, last_page: Math.max(1, Math.ceil(audit.total / perPage)) },
      })
    },
    // Every WRITE the centre could make on its own. Rendering the page must call none of them.
    bindAccountToProject: write('bind'),
    confirmAccountSelection: write('confirm'),
    applyAccountSelection: write('apply'),
    syncAccountNow: write('sync'),
    backfillAccount: write('backfill'),
    refreshDiscoveredAccounts: write('refresh'),
  }
})

vi.mock('./ConnectionWizard', () => ({ ConnectionWizard: () => <div data-testid="wizard-stub" /> }))
vi.mock('@/features/commerce/StoresPanel', () => ({ StoresPanel: () => <div data-testid="stores-stub" /> }))
vi.mock('@/features/measurement/PropertiesPanel', () => ({ PropertiesPanel: () => <div data-testid="properties-stub" /> }))

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
  listProjects: () => Promise.resolve(projects.list),
}))

function status(state: string, labelAr: string, labelEn: string, action: IntegrationStatus['action'] = null): IntegrationStatus {
  return { state, label_ar: labelAr, label_en: labelEn, since: null, as_of: null, detail: null, action }
}

/** A working source: authorised, linked, synced, fresh, no error. */
function healthy(over: Partial<IntegrationStatuses> = {}): IntegrationStatuses {
  return {
    authorisation: status('connected', 'مفوَّض', 'Authorised'),
    binding: status('bound', 'مرتبط بمشروع', 'Linked to a project'),
    sync: status('succeeded', 'نجحت آخر مزامنة', 'Last sync succeeded'),
    freshness: status('fresh', 'حديثة', 'Fresh'),
    error: status('none', 'لا خطأ', 'No error'),
    ...over,
  }
}

const REAUTH = { code: 'reauthorize', label_ar: 'أعد المصادقة', label_en: 'Re-authorise the connection' }

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1',
    provider: 'snapchat',
    label: 'Snapchat Ads',
    label_ar: 'سناب شات',
    connection_name: 'Snapchat',
    authorised_by: { name: 'Agency Owner', email: 'owner@campaignshub.io' },
    provider_account: { label: 'Snap Person', email: 'snap@person.test', fetched_at: '2026-11-05T09:00:00+00:00' },
    removed_at: null,
    abandoned: false,
    authorised_at: '2026-09-01T09:00:00+00:00',
    selected_accounts: 2,
    discovered_accounts: 321,
    connection_state: 'CONNECTED',
    sync_state: 'SUCCEEDED',
    needs_attention: false,
    last_success_at: '2026-11-04T09:00:00+00:00',
    next_sync_at: '2026-11-05T10:00:00+00:00',
    projects: [{ id: 'p-1', name: 'Razzah Avenue' }],
    has_parent: true,
    discovery_blocked_reason: null,
    token_expires_at: null,
    statuses: healthy(),
    status_profile: { kind: 'advertising', auth: 'oauth', refresh: true, token_expiry: true, webhooks: 'polling_only', freshness_mode: 'polling' },
    ...over,
  }
}

const META = { key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true, auth: 'oauth' } as const

function entry(over: Partial<IntegrationAuditEntry> = {}): IntegrationAuditEntry {
  return {
    id: 'a-1', action: 'integration.connection.removed', category: 'authorisation', actor_name: 'Agency Owner',
    provider: 'snapchat', provider_label: 'Snapchat Ads', provider_label_ar: 'سناب شات',
    subject_kind: 'connection', subject_name: null, created_at: '2026-11-05T09:00:00+00:00',
    ...over,
  }
}

beforeEach(() => {
  hub.data = { connections: [], connectable: [] }
  estate.projects = []
  projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' }]
  calls.started = []
  calls.removed = []
  calls.audit = []
  calls.writes = []
  audit.entries = []
  audit.total = 0
})

describe('the Integrations Centre: three sections, Arabic and RTL', () => {
  it('draws «المشاريع», «المصادر المرتبطة» and «التدقيق», in that order, right to left', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    const centre = await screen.findByTestId('connection-hub')
    expect(centre).toHaveAttribute('dir', 'rtl')

    const projectsSection = await screen.findByTestId('hub-section-projects')
    const sources = await screen.findByTestId('hub-section-sources')
    const auditSection = screen.getByTestId('hub-section-audit')

    expect(within(projectsSection).getByRole('heading', { level: 2 })).toHaveTextContent('المشاريع')
    expect(within(sources).getByRole('heading', { level: 2 })).toHaveTextContent('المصادر المرتبطة')
    expect(within(auditSection).getByRole('heading', { level: 2 })).toHaveTextContent('التدقيق')

    const FOLLOWING = Node.DOCUMENT_POSITION_FOLLOWING
    expect(projectsSection.compareDocumentPosition(sources) & FOLLOWING).toBeTruthy()
    expect(sources.compareDocumentPosition(auditSection) & FOLLOWING).toBeTruthy()

    // The three are named once more as the way to reach them.
    expect(screen.getByTestId('hub-section-link-projects')).toHaveTextContent('المشاريع')
    expect(screen.getByTestId('hub-section-link-sources')).toHaveTextContent('المصادر المرتبطة')
    expect(screen.getByTestId('hub-section-link-audit')).toHaveTextContent('التدقيق')
  })

  /** Feature first: the working sections come before the figures that summarise them. */
  it('puts the summary figures under the three working sections, not in the page head', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    const summary = await screen.findByTestId('hub-summary')
    const auditSection = screen.getByTestId('hub-section-audit')
    expect(auditSection.compareDocumentPosition(summary) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    expect(within(summary).getByTestId('hub-kpi-sources')).toHaveTextContent('1')
    expect(within(screen.getByTestId('integrations-intro')).queryByTestId('hub-kpi-sources')).toBeNull()
  })

  it('writes every count with Latin digits in Arabic', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    estate.projects = [{ project_id: 'p-1', project_name: 'Razzah Avenue', providers: ['snapchat'], accounts: 2, last_synced_at: null, needs_attention: 1 }]
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    await screen.findByTestId('hub-row-c-1')
    await screen.findByTestId('estate-attention')
    expect(screen.getByTestId('connection-hub').textContent).not.toMatch(/[٠-٩]/)
    expect(screen.getByTestId('hub-accounts-c-1')).toHaveTextContent('اختير 2 من 321')
  })
})

describe('the Integrations Centre: five independent statuses', () => {
  it('draws the server\'s five statuses as five chips, in Arabic, each under its own name', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('status-authorisation-c-1')).toHaveTextContent('التفويض: مفوَّض')
    expect(screen.getByTestId('status-binding-c-1')).toHaveTextContent('الربط: مرتبط بمشروع')
    expect(screen.getByTestId('status-sync-c-1')).toHaveTextContent('المزامنة: نجحت آخر مزامنة')
    expect(screen.getByTestId('status-freshness-c-1')).toHaveTextContent('حداثة البيانات: حديثة')
    expect(screen.getByTestId('status-error-c-1')).toHaveTextContent('الخطأ: لا خطأ')
    expect(within(screen.getByTestId('status-chips-c-1')).getAllByRole('listitem')).toHaveLength(5)
  })

  /** The collapse this exists to prevent: a good authorisation must not hide a failed sync, nor the reverse. */
  it('lets no status imply another: authorised, yet the sync failed and the data is stale', async () => {
    hub.data = {
      connections: [connection({
        statuses: healthy({
          sync: status('failed', 'فشلت آخر مزامنة', 'Last sync failed'),
          freshness: status('stale', 'متأخرة', 'Stale'),
          error: status('error', 'خطأ', 'Error', { code: 'retry_sync', label_ar: 'أعد المزامنة', label_en: 'Run the sync again' }),
        }),
      })],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('status-authorisation-c-1')).toHaveTextContent('Authorisation: Authorised')
    expect(screen.getByTestId('status-binding-c-1')).toHaveTextContent('Link: Linked to a project')
    expect(screen.getByTestId('status-sync-c-1')).toHaveTextContent('Sync: Last sync failed')
    expect(screen.getByTestId('status-freshness-c-1')).toHaveTextContent('Freshness: Stale')
    expect(screen.getByTestId('status-error-c-1')).toHaveTextContent('Error: Error')
  })

  it('says a discovered source is not linked, and offers the way to choose', async () => {
    hub.data = {
      connections: [connection({
        selected_accounts: 0,
        statuses: healthy({
          binding: status('discovered_unbound', 'مكتشف وغير مرتبط', 'Discovered, not linked'),
          sync: status('never_started', 'لم تبدأ المزامنة', 'Never synced'),
          freshness: status('unknown', 'غير معروفة', 'Unknown'),
        }),
      })],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('status-binding-c-1')).toHaveTextContent('الربط: مكتشف وغير مرتبط')
    expect(screen.getByTestId('status-sync-c-1')).toHaveTextContent('المزامنة: لم تبدأ المزامنة')
    expect(screen.getByTestId('hub-unfinished-c-1')).toHaveTextContent('اختر الحسابات')
  })

  it('colours a state by its dimension, and never dresses an unknown word as good or bad', () => {
    expect(statusTone('authorisation', 'connected')).toBe('success')
    expect(statusTone('authorisation', 'reauth_required')).toBe('danger')
    expect(statusTone('binding', 'discovered_unbound')).toBe('warning')
    expect(statusTone('sync', 'partial')).toBe('warning')
    expect(statusTone('freshness', 'withheld')).toBe('warning')
    // «No error» is an absence, so it is quiet rather than green.
    expect(statusTone('error', 'none')).toBe('neutral')
    expect(statusTone('sync', 'a_state_from_the_future')).toBe('neutral')
  })

  /** An older response without `statuses` keeps the two facts this page always stated. */
  it('falls back to the authorisation and sync chips when the server sends no statuses', async () => {
    hub.data = { connections: [connection({ statuses: null, connection_state: 'REAUTH_REQUIRED', sync_state: 'FAILED' })], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-auth-c-1')).toHaveTextContent('Needs reconnecting')
    expect(screen.getByTestId('hub-sync-c-1')).toHaveTextContent('Sync failed')
    expect(screen.queryByTestId('status-chips-c-1')).toBeNull()
  })
})

describe('the Integrations Centre: the platform account is the identity', () => {
  it('names the platform account on the row, in the drawer and in the audit finding, and never the workspace email', async () => {
    hub.data = {
      connections: [connection({ statuses: healthy({ authorisation: status('reauth_required', 'يلزم إعادة المصادقة', 'Re-authorisation required', REAUTH) }) })],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-platform-account-c-1')).toHaveTextContent('Platform account: snap@person.test')
    expect(screen.getByTestId('hub-added-by-c-1')).toHaveTextContent('Added by: Agency Owner')
    expect(screen.getByTestId('audit-finding-c-1-authorisation')).toHaveTextContent('Platform account: snap@person.test')

    fireEvent.click(screen.getByTestId('hub-open-c-1'))
    expect(await screen.findByTestId('drawer-platform-account')).toHaveTextContent('Platform account: snap@person.test')
    expect(screen.getByTestId('drawer-added-by')).toHaveTextContent('Added by: Agency Owner')

    expect(document.body).not.toHaveTextContent('owner@campaignshub.io')
  })

  it('says the platform account was not recorded rather than substituting the workspace user', async () => {
    hub.data = { connections: [connection({ provider_account: null })], connectable: [] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('hub-platform-account-c-1')).toHaveTextContent('لم يُسجَّل حساب المنصة')
    expect(document.body).not.toHaveTextContent('owner@campaignshub.io')
  })
})

describe('the Integrations Centre: linked sources — search, sort, re-authorise, remove', () => {
  const two = (): HubConnection[] => [
    connection(),
    connection({
      id: 'c-2', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا', connection_name: 'Meta',
      provider_account: { label: 'Sara Meta', email: 'sara@meta.test', fetched_at: null },
      projects: [{ id: 'p-2', name: 'Nasel' }],
      last_success_at: '2026-11-05T08:00:00+00:00',
      statuses: healthy({ authorisation: status('reauth_required', 'يلزم إعادة المصادقة', 'Re-authorisation required', REAUTH) }),
    }),
  ]

  it('finds a source by its platform, its platform account or a project it feeds', async () => {
    hub.data = { connections: two(), connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    const search = await screen.findByTestId('hub-search')

    fireEvent.change(search, { target: { value: 'sara@meta' } })
    expect(screen.getByTestId('hub-row-c-2')).toBeInTheDocument()
    expect(screen.queryByTestId('hub-row-c-1')).toBeNull()

    fireEvent.change(search, { target: { value: 'razzah' } })
    expect(screen.getByTestId('hub-row-c-1')).toBeInTheDocument()
    expect(screen.queryByTestId('hub-row-c-2')).toBeNull()

    fireEvent.change(search, { target: { value: 'ميتا' } })
    expect(screen.getByTestId('hub-row-c-2')).toBeInTheDocument()

    fireEvent.change(search, { target: { value: 'nothing-like-this' } })
    expect(screen.getByTestId('hub-no-match')).toBeInTheDocument()
    expect(screen.queryByTestId('hub-row-c-1')).toBeNull()
  })

  it('sorts by what needs action, by last sync and by name, and leaves the server order as the default', async () => {
    hub.data = { connections: two(), connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    const order = () => screen.getAllByTestId(/^hub-row-/).map((row) => row.getAttribute('data-testid'))
    await screen.findByTestId('hub-row-c-1')
    expect(order()).toEqual(['hub-row-c-1', 'hub-row-c-2'])

    fireEvent.change(screen.getByTestId('hub-sort'), { target: { value: 'attention' } })
    expect(order()).toEqual(['hub-row-c-2', 'hub-row-c-1'])

    fireEvent.change(screen.getByTestId('hub-sort'), { target: { value: 'name' } })
    expect(order()).toEqual(['hub-row-c-2', 'hub-row-c-1']) // Meta Ads, Snapchat Ads

    fireEvent.change(screen.getByTestId('hub-sort'), { target: { value: 'provider' } })
    expect(order()).toEqual(['hub-row-c-1', 'hub-row-c-2'])

    // Never synced sorts last under «last sync».
    const never = connection({ id: 'c-3', last_success_at: null })
    expect(sortSources([never, ...two()], 'last_sync', false).map((c) => c.id)).toEqual(['c-2', 'c-1', 'c-3'])
  })

  it('re-authorises from the row through the centre\'s own flow, for the project the source feeds', async () => {
    hub.data = { connections: two(), connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    // The broken source gets the loud button; the working one the quiet one.
    expect(await screen.findByTestId('hub-reauth-c-2')).toHaveAttribute('data-due', 'true')
    expect(screen.getByTestId('hub-reauth-c-1')).toHaveAttribute('data-due', 'false')

    projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' }, { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' }]
    fireEvent.click(screen.getByTestId('hub-reauth-c-2'))

    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Reconnect — Meta')
    await waitFor(() => expect(screen.getByTestId('flow-destination')).toHaveValue('p-2'))
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-2' }]))
  })

  it('offers re-authorising in the row menu too, and not at all for an API-key source', async () => {
    hub.data = {
      connections: [
        connection(),
        connection({ id: 'c-key', provider: 'openai_ads', label: 'OpenAI Ads', label_ar: 'إعلانات OpenAI', status_profile: { auth: 'api_key', refresh: false, token_expiry: false, webhooks: null, freshness_mode: 'polling' } }),
      ],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-reauth-c-1')
    expect(screen.queryByTestId('hub-reauth-c-key')).toBeNull()

    fireEvent.click(screen.getByTestId('hub-actions-desktop-c-key'))
    expect(await screen.findByTestId('hub-action-menu')).toBeInTheDocument()
    expect(screen.queryByTestId('action-reauthorise')).toBeNull()
    fireEvent.click(screen.getByTestId('action-close'))

    fireEvent.click(screen.getByTestId('hub-actions-desktop-c-1'))
    fireEvent.click(await screen.findByTestId('action-reauthorise'))
    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Reconnect — Snapchat')
  })

  it('removes a source from the row only after the confirmation, through the existing endpoint', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-detach-c-1'))
    expect(await screen.findByTestId('remove-source-dialog')).toHaveTextContent('Stored figures and reports stay')
    expect(calls.removed).toEqual([])

    fireEvent.click(screen.getByTestId('remove-source-confirm'))
    await waitFor(() => expect(calls.removed).toEqual(['c-1']))
  })
})

describe('the Integrations Centre: Project-First', () => {
  it('lists every project, including one nothing feeds yet, and says so without calling it healthy', async () => {
    projects.list = [
      { id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' },
      { id: 'p-new', name: 'Brand New Client', client_workspace_id: 'ws-2' },
    ]
    estate.projects = [{ project_id: 'p-1', project_name: 'Razzah Avenue', providers: ['snapchat'], accounts: 2, last_synced_at: null, needs_attention: 0 }]
    hub.data = { connections: [], connectable: [META] }
    renderWithProviders(<IntegrationsPage />)

    await waitFor(() => expect(screen.getAllByTestId('estate-row')).toHaveLength(2))
    const fresh = screen.getAllByTestId('estate-row').find((row) => row.getAttribute('data-project') === 'p-new')!
    expect(fresh).toHaveTextContent('Brand New Client')
    expect(within(fresh).getByTestId('estate-unlinked')).toHaveTextContent('No source linked yet')
    expect(fresh).not.toHaveTextContent('Healthy')

    // And the journey starts from that row, with the project already decided.
    fireEvent.click(screen.getByTestId('estate-link-p-new'))
    fireEvent.click(screen.getByTestId('estate-link-p-new-meta'))
    await waitFor(() => expect(screen.getByTestId('flow-destination')).toHaveValue('p-new'))
    expect(screen.queryByTestId('provider-picker')).toBeNull()
  })

  it('states the journey in order — project, platform, authorise, discover, choose, confirm, verify, sync', async () => {
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    const steps = within(await screen.findByTestId('hub-flow-steps')).getAllByRole('listitem').map((li) => li.getAttribute('data-testid'))
    expect(steps).toEqual([
      'hub-flow-step-project', 'hub-flow-step-provider', 'hub-flow-step-authorise', 'hub-flow-step-discover',
      'hub-flow-step-choose', 'hub-flow-step-confirm', 'hub-flow-step-verify', 'hub-flow-step-sync',
    ])
    expect(screen.getByTestId('hub-flow-step-project')).toHaveTextContent('1')
    expect(screen.getByTestId('hub-flow-step-project')).toHaveTextContent('اختر المشروع')
    expect(screen.getByTestId('hub-discovery-rule')).toHaveTextContent('لا يُربط بأي مشروع ولا يُزامَن تلقائيًا')
  })

  it('asks for the project before the platform when connecting from the page head', async () => {
    projects.list = [
      { id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' },
      { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' },
    ]
    hub.data = { connections: [connection()], connectable: [META] }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect'))

    // Two projects: nothing is assumed, and no platform can be pressed yet.
    const project = await screen.findByTestId('picker-project')
    expect(project).toHaveValue('')
    expect(screen.getByTestId('provider-pick-meta')).toBeDisabled()
    expect(screen.getByTestId('picker-project-first')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('provider-pick-meta'))
    expect(screen.queryByTestId('connection-flow')).toBeNull()

    fireEvent.change(project, { target: { value: 'p-2' } })
    expect(screen.getByTestId('provider-pick-meta')).toBeEnabled()
    fireEvent.click(screen.getByTestId('provider-pick-meta'))

    await waitFor(() => expect(screen.getByTestId('flow-destination')).toHaveValue('p-2'))
    fireEvent.click(screen.getByTestId('flow-authorise'))
    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-2' }]))
  })

  it('searches, sorts and pages the projects at 10, 25 and 50', async () => {
    projects.list = Array.from({ length: 27 }, (_, i) => ({ id: `p-${i + 1}`, name: `Client ${String(i + 1).padStart(2, '0')}`, client_workspace_id: null }))
    estate.projects = [
      { project_id: 'p-27', project_name: 'Client 27', providers: ['meta'], accounts: 4, last_synced_at: '2026-11-05T08:00:00+00:00', needs_attention: 2 },
    ]
    renderWithProviders(<IntegrationsPage />)

    await waitFor(() => expect(screen.getAllByTestId('estate-row')).toHaveLength(10))
    expect(screen.getByTestId('estate-pager-position')).toHaveTextContent('1 / 3')
    expect(within(screen.getByTestId('estate-page-size')).getAllByRole('option').map((o) => o.textContent)).toEqual(['10', '25', '50'])

    fireEvent.change(screen.getByTestId('estate-page-size'), { target: { value: '25' } })
    expect(screen.getAllByTestId('estate-row')).toHaveLength(25)
    fireEvent.click(screen.getByTestId('estate-pager-next'))
    expect(screen.getAllByTestId('estate-row')).toHaveLength(2)

    fireEvent.change(screen.getByTestId('estate-page-size'), { target: { value: '50' } })
    expect(screen.getAllByTestId('estate-row')).toHaveLength(27)

    // The one that needs attention comes first when asked, and the one with accounts too.
    fireEvent.change(screen.getByTestId('estate-sort'), { target: { value: 'attention' } })
    expect(screen.getAllByTestId('estate-row')[0]).toHaveAttribute('data-project', 'p-27')
    fireEvent.change(screen.getByTestId('estate-sort'), { target: { value: 'name' } })
    expect(screen.getAllByTestId('estate-row')[0]).toHaveAttribute('data-project', 'p-1')

    fireEvent.change(screen.getByTestId('estate-search'), { target: { value: 'client 07' } })
    expect(screen.getAllByTestId('estate-row')).toHaveLength(1)
    fireEvent.change(screen.getByTestId('estate-search'), { target: { value: 'meta' } })
    expect(screen.getAllByTestId('estate-row')[0]).toHaveAttribute('data-project', 'p-27')
    fireEvent.change(screen.getByTestId('estate-search'), { target: { value: 'zzz' } })
    expect(screen.getByTestId('estate-no-match')).toBeInTheDocument()
  })

  /** Discovery is not a link and a link is not a sync: seeing the page changes nothing. */
  it('links and syncs nothing by merely showing what was discovered', async () => {
    hub.data = {
      connections: [connection({ selected_accounts: 0, discovered_accounts: 321, statuses: healthy({ binding: status('discovered_unbound', 'مكتشف وغير مرتبط', 'Discovered, not linked') }) })],
      connectable: [META],
    }
    estate.projects = [{ project_id: 'p-1', project_name: 'Razzah Avenue', providers: [], accounts: 0, last_synced_at: null, needs_attention: 0 }]
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-row-c-1')
    fireEvent.click(screen.getByTestId('estate-toggle-p-1'))
    await screen.findByTestId('estate-accounts')
    fireEvent.click(screen.getByTestId('hub-open-c-1'))
    await screen.findByTestId('drawer-platform-account')

    expect(calls.writes).toEqual([])
    expect(calls.started).toEqual([])
    expect(calls.removed).toEqual([])
  })
})

describe('the Integrations Centre: audit', () => {
  it('lists what needs action with the server\'s remedy, and performs it through the existing flow', async () => {
    hub.data = {
      connections: [
        connection(),
        connection({
          id: 'c-2', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا',
          statuses: healthy({
            authorisation: status('reauth_required', 'يلزم إعادة المصادقة', 'Re-authorisation required', REAUTH),
            error: status('error', 'خطأ', 'Error', REAUTH),
          }),
        }),
      ],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    const finding = await screen.findByTestId('audit-finding-c-2-authorisation')
    expect(finding).toHaveTextContent('ميتا')
    expect(finding).toHaveTextContent('التفويض: يلزم إعادة المصادقة')
    expect(screen.getByTestId('audit-remedy-c-2-authorisation')).toHaveTextContent('أعد المصادقة')
    // One remedy, one row: the error status naming the same act is not a second problem.
    expect(screen.queryByTestId('audit-finding-c-2-error')).toBeNull()
    // The working source raises nothing.
    expect(screen.queryByTestId('audit-finding-c-1-authorisation')).toBeNull()

    fireEvent.click(screen.getByTestId('audit-act-c-2-authorisation'))
    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('إعادة المصادقة — ميتا')
  })

  it('says nothing needs action when every status is clean, and treats «wait» as a state rather than a finding', async () => {
    const waiting = connection({ statuses: healthy({ sync: status('running', 'المزامنة جارية', 'Syncing', { code: 'wait', label_ar: 'لا إجراء مطلوب الآن', label_en: 'Nothing to do now' }) }) })
    hub.data = { connections: [waiting], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('audit-attention-empty')).toHaveTextContent('Nothing needs action right now.')
    expect(findingsOf(waiting)).toEqual([])
    expect(findingsOf(connection({ removed_at: '2026-11-01T00:00:00+00:00', statuses: healthy({ error: status('error', 'خطأ', 'Error') }) }))).toEqual([])
  })

  it('opens the selection editor for a source whose remedy is choosing accounts', async () => {
    hub.data = {
      connections: [connection({ statuses: healthy({ binding: status('discovered_unbound', 'مكتشف وغير مرتبط', 'Discovered, not linked', { code: 'choose_accounts', label_ar: 'اختر الحسابات التي تريد ربطها', label_en: 'Choose the accounts to link' }) }) })],
      connectable: [],
    }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('audit-act-c-1-binding'))
    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Edit the selection — Snapchat')
  })

  it('reads the activity log: the act in words, what it was done to, and who — by name', async () => {
    audit.entries = [
      entry(),
      entry({ id: 'a-2', action: 'metrics.sync_requested', category: 'sync', subject_kind: 'account', subject_name: 'Razzah Store', actor_name: null }),
      entry({ id: 'a-3', action: 'integration.something.new', category: null, provider: null, provider_label: null, provider_label_ar: null, subject_kind: null }),
    ]
    audit.total = 3
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    const first = await screen.findByTestId('audit-row-a-1')
    expect(first).toHaveTextContent('إزالة المصدر')
    expect(first).toHaveTextContent('سناب شات · تفويض')
    expect(screen.getByTestId('audit-actor-a-1')).toHaveTextContent('Agency Owner')

    expect(screen.getByTestId('audit-row-a-2')).toHaveTextContent('طلب مزامنة يدوية')
    expect(screen.getByTestId('audit-row-a-2')).toHaveTextContent('سناب شات · Razzah Store')
    // No actor is «not recorded», never an invented name.
    expect(screen.getByTestId('audit-actor-a-2')).toHaveTextContent('غير مسجَّل')

    // An act this page has not met is shown by its own code rather than guessed at.
    expect(screen.getByTestId('audit-row-a-3')).toHaveTextContent('integration.something.new')
    expect(screen.getByTestId('audit-log-table').textContent).not.toMatch(/[٠-٩]/)
  })

  it('pages the log at 10, 25 and 50 and narrows it by type, asking the server each time', async () => {
    audit.entries = Array.from({ length: 27 }, (_, i) => entry({ id: `a-${i + 1}` }))
    audit.total = 27
    renderWithProviders(<IntegrationsPage />)

    await waitFor(() => expect(screen.getAllByTestId(/^audit-row-/)).toHaveLength(10))
    expect(within(screen.getByTestId('audit-page-size')).getAllByRole('option').map((o) => o.textContent)).toEqual(['10', '25', '50'])
    expect(screen.getByTestId('audit-total')).toHaveTextContent('27')
    expect(screen.getByTestId('audit-pager-position')).toHaveTextContent('1 / 3')

    fireEvent.change(screen.getByTestId('audit-page-size'), { target: { value: '25' } })
    await waitFor(() => expect(screen.getAllByTestId(/^audit-row-/)).toHaveLength(25))
    expect(calls.audit.at(-1)).toMatchObject({ perPage: 25, page: 1 })

    fireEvent.click(screen.getByTestId('audit-pager-next'))
    await waitFor(() => expect(calls.audit.at(-1)).toMatchObject({ perPage: 25, page: 2 }))

    fireEvent.change(screen.getByTestId('audit-category'), { target: { value: 'binding' } })
    await waitFor(() => expect(calls.audit.at(-1)).toMatchObject({ category: 'binding', page: 1 }))
  })

  it('says the log is empty rather than drawing an empty table', async () => {
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('audit-log-empty')).toHaveTextContent('No act has been recorded yet.')
    expect(screen.queryByTestId('audit-log-table')).toBeNull()
  })
})
