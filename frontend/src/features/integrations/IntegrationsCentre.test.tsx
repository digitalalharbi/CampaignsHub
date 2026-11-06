import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import { findingsOf } from './IntegrationAudit'
import { sortSources } from './ConnectionHub'
import { statusTone } from './StatusChips'
import type {
  ConnectionHub as Hub, EstateProject, HubConnection, IntegrationAuditEntry, IntegrationStatus, IntegrationStatuses,
} from './api'
import { renderWithProviders } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'

/**
 * INTEG-UNIFIED-002 (Owner contract 2026-11-05 §1.3, §1.12, §1.13) — the Integrations Centre.
 *
 * One Arabic RTL, Project-First page in three sections — «المشاريع», «المصادر المرتبطة», «التدقيق» — with
 * five independent statuses read from the server, the PLATFORM account as the identity of a source,
 * search, sort, re-authorise and remove on the row, and nothing discovered ever linked or synced by
 * merely being seen.
 */

const hub = vi.hoisted(() => ({
  data: { connections: [], connectable: [] } as Hub,
  /** What the server answers for ONE project; a project not listed here gets `data`. */
  byProject: {} as Record<string, Hub>,
  asked: [] as Array<string | null>,
}))
const estate = vi.hoisted(() => ({ projects: [] as EstateProject[] }))
const projects = vi.hoisted(() => ({ list: [] as Array<{ id: string; name: string; client_workspace_id: string | null; summary?: { accounts: number; providers: string[]; campaigns?: number; data_last_synced_at: null; team_members: number; attention: null } }> }))
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
    fetchConnectionHub: (projectId?: string | null) => {
      hub.asked.push(projectId ?? null)
      return Promise.resolve(hub.byProject[projectId ?? ''] ?? hub.data)
    },
    fetchConnectionHubWithRemoved: (projectId?: string | null) => Promise.resolve(hub.byProject[projectId ?? ''] ?? hub.data),
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
vi.mock('@/features/commerce/StoresPanel', () => ({ StoresPanel: ({ projectId }: { projectId?: string | null }) => <div data-testid="stores-stub" data-project={projectId ?? ''} /> }))
vi.mock('@/features/measurement/PropertiesPanel', () => ({ PropertiesPanel: ({ projectId }: { projectId?: string | null }) => <div data-testid="properties-stub" data-project={projectId ?? ''} /> }))

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

/** The platform step of the project's own journey: mark the platform, press «التالي». */
async function pickPlatform(key: string) {
  fireEvent.click(await screen.findByTestId(`link-platform-${key}`))
  fireEvent.click(screen.getByTestId('link-next'))
}

/** «التدقيق» is a tab of the project's space: it is drawn once it is opened. */
async function openAudit() {
  fireEvent.click(await screen.findByTestId('hub-section-link-audit'))
  await screen.findByTestId('hub-section-audit')
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
  // INTEG-PROJECT-SPACE-001 — the centre is the SELECTED project's space; these read it standing in p-1.
  useProject.setState({ currentProjectId: 'p-1' })
  hub.data = { connections: [], connectable: [] }
  hub.byProject = {}
  hub.asked = []
  estate.projects = []
  projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' }]
  calls.started = []
  calls.removed = []
  calls.audit = []
  calls.writes = []
  audit.entries = []
  audit.total = 0
})

describe('the Integrations Centre: the project space — three tabs, Arabic and RTL', () => {
  it('draws the tabs «المصادر», «المنصات» and «التدقيق», in that order, right to left, one at a time', async () => {
    hub.data = { connections: [connection()], connectable: [META] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    const centre = await screen.findByTestId('connection-hub')
    expect(centre).toHaveAttribute('dir', 'rtl')
    // The space is named after its project.
    expect(await within(screen.getByTestId('integrations-intro')).findByText('Razzah Avenue')).toBeInTheDocument()

    const tabs = within(screen.getByTestId('hub-sections')).getAllByRole('tab')
    expect(tabs.map((t) => t.textContent)).toEqual(['المصادر', 'المنصات', 'التدقيق'])
    expect(tabs.map((t) => t.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false'])

    // «المصادر» first, and alone: there is no «المشاريع» section — the space IS one project.
    const sources = await screen.findByTestId('hub-section-sources')
    expect(within(sources).getByRole('heading', { level: 2 })).toHaveTextContent('المصادر')
    expect(screen.queryByTestId('hub-section-projects')).toBeNull()
    expect(screen.queryByTestId('hub-section-audit')).toBeNull()

    fireEvent.click(screen.getByTestId('hub-section-link-platforms'))
    expect(await screen.findByTestId('hub-platform-meta')).toHaveTextContent('ميتا')
    expect(screen.queryByTestId('hub-section-sources')).toBeNull()
    // Stores and GA4 are on «المنصات» too, asked for THIS project alone — no separate estate blocks.
    expect(screen.getByTestId('stores-stub')).toHaveAttribute('data-project', 'p-1')
    expect(screen.getByTestId('properties-stub')).toHaveAttribute('data-project', 'p-1')

    fireEvent.click(screen.getByTestId('hub-section-link-audit'))
    expect(within(await screen.findByTestId('hub-section-audit')).getByRole('heading', { level: 2 })).toHaveTextContent('التدقيق')
    expect(screen.queryByTestId('hub-platforms')).toBeNull()
  })

  /** Owner 2026-11-06 — four number tiles on top of the project's space: its own figures, and only its own. */
  it('puts this project\'s four figures on top of its space', async () => {
    projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1', summary: { accounts: 2, providers: ['snapchat'], campaigns: 14, data_last_synced_at: null, team_members: 1, attention: null } }]
    hub.data = { connections: [connection(), connection({ id: 'c-2', provider: 'meta', selected_accounts: 1, statuses: healthy({ authorisation: status('reauth_required', 'يلزم إعادة المصادقة', 'Re-authorisation required', REAUTH) }) })], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    const summary = await screen.findByTestId('hub-summary')
    expect(summary.compareDocumentPosition(screen.getByTestId('hub-sections')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    expect(within(summary).getByTestId('hub-kpi-accounts')).toHaveTextContent('3')
    // The last 30 days of spend is the fourth figure; with nothing reported it is a dash, never a zero.
    expect(within(summary).getByTestId('hub-kpi-spend')).toHaveTextContent('—')
    await waitFor(() => expect(within(summary).getByTestId('hub-kpi-campaigns')).toHaveTextContent('14'))
    expect(within(summary).getByTestId('hub-kpi-attention')).toHaveTextContent('1')
    expect(within(summary).getAllByTestId(/^hub-kpi-(accounts|campaigns|spend|attention)$/)).toHaveLength(4)
  })

  it('writes every count with Latin digits in Arabic', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    await screen.findByTestId('hub-row-c-1')
    await screen.findByTestId('hub-summary')
    expect(screen.getByTestId('connection-hub').textContent).not.toMatch(/[٠-٩]/)
    // Linked accounts only: the 321 the sign-in could see are not counted anywhere on the centre.
    expect(screen.getByTestId('hub-accounts-c-1')).toHaveTextContent('حسابان مربوطان')
    expect(screen.getByTestId('connection-hub')).not.toHaveTextContent('321')
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
    // The five sit under the row; the row itself carries one chip, in the server's words.
    expect(screen.getByTestId('hub-state-c-1')).toHaveTextContent('مفوَّض')
    fireEvent.click(screen.getByTestId('hub-expand-c-1'))
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

    fireEvent.click(screen.getByTestId('hub-open-c-1'))
    expect(await screen.findByTestId('drawer-platform-account')).toHaveTextContent('Platform account: snap@person.test')
    expect(screen.getByTestId('drawer-added-by')).toHaveTextContent('Added by: Agency Owner')
    fireEvent.click(screen.getByTestId('drawer-close'))

    await openAudit()
    expect(screen.getByTestId('audit-finding-c-1-authorisation')).toHaveTextContent('Platform account: snap@person.test')

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

  it('re-authorises from the row through the centre\'s own flow, for the project whose space it is', async () => {
    // What the server answers for p-1: both sources feed it (a source of another project is not in the answer at all).
    hub.data = { connections: two().map((c) => ({ ...c, projects: [{ id: 'p-1', name: 'Razzah Avenue' }] })), connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    // The broken source gets the loud button; the working one the quiet one.
    expect(await screen.findByTestId('hub-reauth-c-2')).toHaveAttribute('data-due', 'true')
    expect(screen.getByTestId('hub-reauth-c-1')).toHaveAttribute('data-due', 'false')

    projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' }, { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' }]
    fireEvent.click(screen.getByTestId('hub-reauth-c-2'))

    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Reconnect — Meta')
    // The space is p-1's: the renewal is for p-1, never for another project the source may also feed.
    await waitFor(() => expect(screen.getByTestId('flow-destination')).toHaveValue('p-1'))
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-1' }]))
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

    fireEvent.click(screen.getByTestId('hub-actions-c-key'))
    expect(await screen.findByTestId('hub-action-menu')).toBeInTheDocument()
    expect(screen.queryByTestId('action-reauthorise')).toBeNull()
    fireEvent.click(screen.getByTestId('action-close'))

    fireEvent.click(screen.getByTestId('hub-actions-c-1'))
    fireEvent.click(await screen.findByTestId('action-reauthorise'))
    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Reconnect — Snapchat')
  })

  it('removes a source from the row only after the confirmation, through the existing endpoint', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-detach-c-1'))
    expect(await screen.findByTestId('remove-source-dialog')).toHaveTextContent('Its history stays, and it can be linked again.')
    expect(calls.removed).toEqual([])

    fireEvent.click(screen.getByTestId('remove-source-confirm'))
    await waitFor(() => expect(calls.removed).toEqual(['c-1']))
  })
})

describe('the Integrations Centre: no project selected — the chooser', () => {
  beforeEach(() => {
    useProject.setState({ currentProjectId: null })
    projects.list = [
      { id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1', summary: { accounts: 2, providers: ['snapchat'], campaigns: 14, data_last_synced_at: null, team_members: 1, attention: null } },
      { id: 'p-new', name: 'Brand New Client', client_workspace_id: 'ws-2' },
    ]
    hub.data = { connections: [connection()], connectable: [META] }
  })

  it('lists every project as a card with a few numbers, including one nothing feeds yet, and never an account list', async () => {
    renderWithProviders(<IntegrationsPage />)

    const chooser = await screen.findByTestId('hub-project-chooser')
    expect(await screen.findByTestId('hub-choose-p-1')).toHaveTextContent('Razzah Avenue')
    expect(screen.getByTestId('hub-card-accounts-p-1')).toHaveTextContent('2')
    expect(screen.getByTestId('hub-card-campaigns-p-1')).toHaveTextContent('14')
    expect(screen.getByTestId('hub-choose-p-new')).toHaveTextContent('Brand New Client')
    expect(screen.getByTestId('hub-card-accounts-p-new')).toHaveTextContent('0')
    expect(screen.getByTestId('hub-choose-p-new')).not.toHaveTextContent('Healthy')

    // Nothing but the chooser: no sources, no tabs, no connect button, and the hub was never asked.
    expect(screen.queryByTestId('connection-hub')).toBeNull()
    expect(screen.queryByTestId('hub-sections')).toBeNull()
    expect(screen.queryByTestId('hub-connect')).toBeNull()
    expect(screen.queryByTestId('hub-row-c-1')).toBeNull()
    expect(chooser).not.toHaveTextContent('snap@person.test')
    expect(hub.asked).toEqual([])
  })

  it('searches the projects by name', async () => {
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-choose-p-1')
    fireEvent.change(screen.getByTestId('hub-project-search'), { target: { value: 'brand' } })
    expect(screen.queryByTestId('hub-choose-p-1')).toBeNull()
    expect(screen.getByTestId('hub-choose-p-new')).toBeInTheDocument()

    fireEvent.change(screen.getByTestId('hub-project-search'), { target: { value: 'zzz' } })
    expect(screen.getByTestId('hub-project-none')).toBeInTheDocument()
  })

  /** In the agency portal the sidebar switcher is client → project: the chooser follows it, and tells it what was chosen. */
  it('in the agency portal offers the selected client\'s projects only, and hands the choice to the switcher', async () => {
    useAgencyClient.setState({ currentClientId: 'ws-2' })
    const { unmount } = renderWithProviders(<IntegrationsPage />, { route: '/agency/integrations' })

    expect(await screen.findByTestId('hub-choose-p-new')).toBeInTheDocument()
    expect(screen.queryByTestId('hub-choose-p-1')).toBeNull()
    unmount()

    // No client chosen yet: every project is offered, and choosing one selects its client too.
    useAgencyClient.setState({ currentClientId: null })
    renderWithProviders(<IntegrationsPage />, { route: '/agency/integrations' })
    fireEvent.click(await screen.findByTestId('hub-choose-p-1'))
    expect(useProject.getState().currentProjectId).toBe('p-1')
    expect(useAgencyClient.getState().currentClientId).toBe('ws-1')
    useAgencyClient.setState({ currentClientId: null })
  })

  /** The project comes first: there is no way to reach a platform before one is chosen, and after it is fixed. */
  it('chooses the project before the platform, and the journey is that project\'s from then on', async () => {
    hub.byProject = { 'p-new': { connections: [], connectable: [META] } }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-choose-p-new'))
    expect(useProject.getState().currentProjectId).toBe('p-new')

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    expect(await screen.findByTestId('link-project')).toHaveTextContent('Brand New Client')
    await pickPlatform('meta')

    // From here the project is fixed: stated in the flow, with no picker to change it.
    expect(await screen.findByTestId('flow-project-fixed')).toHaveTextContent('Brand New Client')
    expect(screen.queryByTestId('flow-destination')).toBeNull()
    fireEvent.click(screen.getByTestId('flow-authorise'))
    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-new' }]))
  })
})

describe('the Integrations Centre: one project at a time', () => {
  const A = () => connection({ id: 'c-a', provider: 'snapchat', provider_account: { label: 'Alpha Login', email: 'alpha@login.test', fetched_at: null }, projects: [{ id: 'p-1', name: 'Razzah Avenue' }], accounts: [{ id: 'acc-a', name: 'Alpha Ad Account', organisation: 'Alpha Org', external_id: 'a-1', currency: 'SAR' }] })
  const B = () => connection({ id: 'c-b', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا', provider_account: { label: 'Beta Login', email: 'beta@login.test', fetched_at: null }, projects: [{ id: 'p-2', name: 'Nasel' }], accounts: [{ id: 'acc-b', name: 'Beta Ad Account', organisation: 'Beta Org', external_id: 'b-1', currency: 'USD' }] })

  beforeEach(() => {
    projects.list = [{ id: 'p-1', name: 'Razzah Avenue', client_workspace_id: 'ws-1' }, { id: 'p-2', name: 'Nasel', client_workspace_id: 'ws-2' }]
    hub.byProject = { 'p-1': { connections: [A()], connectable: [META] }, 'p-2': { connections: [B()], connectable: [META] } }
  })

  /** Owner 2026-11-06 — «تغيير المشروع من القائمة الجانبية يخفي تكاملات المشروع الآخر». */
  it('refetches with the new project id when the project is switched, and nothing of the first project stays in the DOM', async () => {
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-row-c-a')).toHaveTextContent('Alpha Ad Account')
    expect(hub.asked).toContain('p-1')
    expect(hub.asked).not.toContain('p-2')
    // Never the workspace-wide board.
    expect(hub.asked).not.toContain(null)

    act(() => useProject.getState().setCurrentProjectId('p-2'))

    expect(await screen.findByTestId('hub-row-c-b')).toHaveTextContent('Beta Ad Account')
    expect(hub.asked).toContain('p-2')
    expect(hub.asked).not.toContain(null)

    expect(screen.queryByTestId('hub-row-c-a')).toBeNull()
    for (const gone of ['Alpha Ad Account', 'Alpha Org', 'alpha@login.test', 'Razzah Avenue']) {
      expect(document.body).not.toHaveTextContent(gone)
    }
    expect(within(screen.getByTestId('integrations-intro')).getByText('Nasel')).toBeInTheDocument()
  })

  it('asks the audit log, the platforms and the link journey for this project only', async () => {
    renderWithProviders(<IntegrationsPage />)

    await openAudit()
    await waitFor(() => expect(calls.audit.length).toBeGreaterThan(0))
    expect(calls.audit.every((q) => q.projectId === 'p-1')).toBe(true)

    act(() => useProject.getState().setCurrentProjectId('p-2'))
    // A new project opens on its sources: the tab of the previous project is not carried over.
    await screen.findByTestId('hub-row-c-b')
    calls.audit = []
    await openAudit()
    await waitFor(() => expect(calls.audit.length).toBeGreaterThan(0))
    expect(calls.audit.every((q) => q.projectId === 'p-2')).toBe(true)

    fireEvent.click(screen.getByTestId('hub-connect'))
    expect(await screen.findByTestId('link-project')).toHaveTextContent('Nasel')
    await screen.findByTestId('link-platform-meta')
    expect(hub.asked).not.toContain(null)
  })

  /** The server's answer for A carries nothing of B — and the page draws exactly what the server sent. */
  it('draws only what the server answered for the selected project', async () => {
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-row-c-a')
    expect(JSON.stringify(hub.byProject['p-1'])).not.toMatch(/Beta|Nasel|c-b|p-2/)
    for (const other of ['Beta Ad Account', 'Beta Org', 'beta@login.test', 'Nasel']) {
      expect(document.body).not.toHaveTextContent(other)
    }
    expect(screen.getAllByTestId(/^hub-row-/)).toHaveLength(1)
  })

  /** A source authorised workspace-wide, before connections belonged to a project, re-links through THIS project's journey. */
  it('offers «إعادة الربط» on a legacy workspace-wide row only, and walks the project journey with the platform known', async () => {
    hub.byProject = {
      'p-1': {
        connections: [
          connection({ id: 'c-legacy', provider: 'meta', label: 'Meta Ads', label_ar: 'ميتا', project_id: null }),
          connection({ id: 'c-own', provider: 'snapchat', project_id: 'p-1' }),
        ],
        connectable: [META],
      },
    }
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('hub-relink-c-legacy')).toHaveTextContent('إعادة الربط')
    // A connection that already belongs to this project has nothing to re-link.
    expect(screen.queryByTestId('hub-relink-c-own')).toBeNull()

    fireEvent.click(screen.getByTestId('hub-relink-c-legacy'))
    // Straight to the platform's own sign-in for this project: the platform step is not asked again.
    expect(await screen.findByTestId('flow-project-fixed')).toHaveTextContent('Razzah Avenue')
    expect(screen.queryByTestId('project-link-flow')).toBeNull()
    fireEvent.click(screen.getByTestId('flow-authorise'))
    await waitFor(() => expect(calls.started).toEqual([{ provider: 'meta', projectId: 'p-1' }]))
  })

  it('states the journey as four words — منصة، منظمة، حساب، تم — and no explanatory text', async () => {
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('hub-connect'))
    const steps = within(await screen.findByTestId('link-steps')).getAllByRole('listitem')
    expect(steps.map((li) => li.getAttribute('data-testid'))).toEqual(['link-step-platform', 'link-step-organisation', 'link-step-account', 'link-step-done'])
    expect(steps.map((li) => li.textContent)).toEqual(['1 منصة', '2 منظمة', '3 حساب', '4 تم'])
    expect(screen.getByTestId('link-step-platform')).toHaveAttribute('aria-current', 'step')
    // The explanatory eight-step strip and its rule sentence are gone from the centre.
    expect(screen.queryByTestId('hub-flow-steps')).toBeNull()
    expect(screen.queryByTestId('hub-discovery-rule')).toBeNull()
  })

  /** Discovery is not a link and a link is not a sync: seeing the page changes nothing. */
  it('links and syncs nothing by merely showing what was discovered', async () => {
    hub.byProject = {}
    hub.data = {
      connections: [connection({ selected_accounts: 0, discovered_accounts: 321, statuses: healthy({ binding: status('discovered_unbound', 'مكتشف وغير مرتبط', 'Discovered, not linked') }) })],
      connectable: [META],
    }
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-row-c-1')
    fireEvent.click(screen.getByTestId('hub-section-link-platforms'))
    await screen.findByTestId('hub-platform-meta')
    fireEvent.click(screen.getByTestId('hub-section-link-sources'))
    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
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
    await openAudit()

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
    await openAudit()

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
    await openAudit()

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
    await openAudit()

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
    await openAudit()

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
    await openAudit()

    expect(await screen.findByTestId('audit-log-empty')).toHaveTextContent('No act has been recorded yet.')
    expect(screen.queryByTestId('audit-log-table')).toBeNull()
  })
})
