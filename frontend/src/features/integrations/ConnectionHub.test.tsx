import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import type { ConnectionHub as Hub, HubConnection } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §§17, 22, 26 — the Connection Hub.
 *
 * What these defend is not a layout. It is that a row is one AUTHORISATION, that it says how many
 * of the reachable accounts were actually chosen, and that the authorisation and the data are two
 * facts neither of which can hide the other — the collapse that left a Production card announcing
 * «المزامنة جارية الآن» above an action area with no Reconnect button.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const started = vi.hoisted(() => ({ calls: [] as Array<{ provider: string; projectId?: string | null; workspaceId?: string | null; appProfile?: string }> }))
const listCalls = vi.hoisted(() => ({ queries: [] as Array<Record<string, unknown>> }))
const estate = vi.hoisted(() => ({ projects: [] as Array<{ project_id: string; project_name: string; providers: string[]; accounts: number; last_synced_at: string | null; needs_attention: number }> }))
const metaApps = vi.hoisted(() => ({ saas: false, testWorkspace: false }))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchConnectionHub: () => Promise.resolve(hub.data),
    fetchEstate: () => Promise.resolve({ projects: estate.projects }),
    listConnectors: () => Promise.resolve([]),
    listAccounts: (query: Record<string, unknown>) => {
      listCalls.queries.push(query)
      return Promise.resolve({
        accounts: [], summary: { total: 0, linked: 0, unlinked: 0 },
        meta: { total: 0, per_page: 10, current_page: 1, last_page: 1 },
      })
    },
    getAccountLogs: () => Promise.resolve({ runs: [] }),
    fetchMetaApps: () => Promise.resolve(metaApps.testWorkspace
      ? { default: 'saas', test_workspace: true, apps: [{ key: 'live', available: false }, { key: 'saas', available: metaApps.saas, reason: metaApps.saas ? null : 'not_configured' }] }
      : { default: 'live', apps: [{ key: 'live', available: true }, { key: 'saas', available: metaApps.saas }] }),
    startPlatformOAuth: (provider: string, workspaceId?: string | null, projectId?: string | null, appProfile?: string | null) => {
      started.calls.push({ provider, projectId, workspaceId, ...(appProfile && appProfile !== 'live' ? { appProfile } : {}) })
      return Promise.resolve({ authorization_url: `https://platform.test/${provider}` })
    },
  }
})

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
  listProjects: () => Promise.resolve([{ id: 'p-1', name: 'Razah', client_workspace_id: 'ws-1' }]),
}))

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1',
    provider: 'meta',
    label: 'Meta Ads',
    label_ar: 'ميتا',
    connection_name: 'Meta',
    authorised_by: { name: 'Owner', email: 'owner@agency.test' },
    authorised_at: '2026-09-01T09:00:00+00:00',
    selected_accounts: 1,
    discovered_accounts: 17,
    connection_state: 'CONNECTED',
    sync_state: 'SUCCEEDED',
    needs_attention: false,
    last_success_at: '2026-09-30T09:00:00+00:00',
    next_sync_at: '2026-09-30T10:00:00+00:00',
    projects: [{ id: 'p-1', name: 'Razah' }],
    has_parent: true,
    discovery_blocked_reason: null,
    token_expires_at: null,
    ...over,
  }
}

describe('the Connection Hub', () => {
  beforeEach(() => {
    hub.data = { connections: [], connectable: [] }
    started.calls = []
    listCalls.queries = []
    estate.projects = []
  })

  it('says nothing is connected, and offers the one thing that can be done about it', async () => {
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('connection-hub-empty')).toHaveTextContent('Nothing is connected yet')
    expect(screen.getByTestId('hub-connect-empty')).toBeInTheDocument()
  })

  /** «1 of 17 accounts» — ACCOUNT-SCOPE-ISOLATION-001 in four words, on the row. */
  it('states the chosen accounts out of the reachable ones', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-accounts-c-1')).toHaveTextContent('1 of 17 accounts')
  })

  it('names who authorised it rather than the connection record', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-open-c-1')).toHaveTextContent('owner@agency.test')
  })

  /** The identity this product actually holds, or an honest absence — never an invented email. */
  it('says the authoriser was not recorded rather than inventing one', async () => {
    hub.data = { connections: [connection({ authorised_by: null })], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-open-c-1')).toHaveTextContent('Authoriser not recorded')
  })

  /**
   * §26, and the defect it exists for.
   *
   * A refused authorisation with a run open must show BOTH chips. On Production these shared one
   * field, the run won, and the card hid the only control that could fix it.
   */
  it('shows a refused authorisation and a running sync as two separate chips', async () => {
    hub.data = {
      connections: [connection({ connection_state: 'REAUTH_REQUIRED', sync_state: 'SYNCING' })],
      connectable: [],
    }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-auth-c-1')).toHaveTextContent('Needs reconnecting')
    expect(screen.getByTestId('hub-sync-c-1')).toHaveTextContent('Syncing')
  })

  /** Null next-sync is «it is not going to», not a blank. */
  it('says the next sync is paused when the server states none', async () => {
    hub.data = {
      connections: [connection({ connection_state: 'REAUTH_REQUIRED', next_sync_at: null })],
      connectable: [],
    }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-row-c-1')).toHaveTextContent('Paused')
  })

  it('opens the drawer on the accounts when the count is pressed', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-accounts-c-1'))

    expect(await screen.findByTestId('connection-drawer')).toBeInTheDocument()
    expect(screen.getByTestId('drawer-tab-accounts')).toHaveAttribute('aria-current', 'page')
    expect(screen.getByTestId('drawer-tab-accounts')).toHaveTextContent('Selected accounts')
    // No way to widen it: the discovered ones are the wizard's, not the drawer's.
    expect(screen.queryByTestId('drawer-only-selected')).toBeNull()
    expect(screen.queryByTestId('drawer-account-search')).toBeNull()
  })

  /**
   * INTEGRATIONS-CENTRE-001 — the drawer never reads the inventory, only the SELECTED accounts.
   *
   * Every `GET /accounts` the drawer makes carries `link=linked` and this connection's id.
   */
  it('lists only the selected accounts of the connection and never the whole inventory', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-accounts-c-1'))
    await screen.findByTestId('selected-accounts-empty')

    const drawerQueries = listCalls.queries.filter((q) => q.connection === 'c-1')
    expect(drawerQueries.length).toBeGreaterThan(0)
    for (const query of listCalls.queries) {
      expect(query).toMatchObject({ link: 'linked' })
      expect(query.connection ?? query.project).toBeDefined()
    }
  })

  /** Discovery and search are the wizard's: the drawer's own button opens it in manage mode. */
  it('opens the wizard in manage mode from «Edit the selection»', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-accounts-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-edit-selection'))

    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Edit the selection')
    expect(screen.queryByTestId('connection-drawer')).toBeNull()
  })

  /** The all-discovered panel and its toggle are gone from the page, in both languages. */
  it.each(['en', 'ar'] as const)('has no all-discovered-accounts section (%s)', async (locale) => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />, { locale })

    await screen.findByTestId('hub-row-c-1')
    expect(screen.queryByTestId('toggle-account-inventory')).toBeNull()
    expect(screen.queryByTestId('inventory-list')).toBeNull()
    expect(document.body.textContent).not.toMatch(/جميع الحسابات المكتشفة|Every discovered account|All discovered accounts/)
    // Nothing on the page itself asks the server for accounts at all until a drawer is opened.
    expect(listCalls.queries).toEqual([])
  })

  /**
   * The per-project «link» is not a second connect flow: it opens the centre's one flow with the
   * project already chosen, so the next press goes to the provider with that project on the state.
   */
  it('links a platform for a project through the same flow, project preselected', async () => {
    hub.data = {
      connections: [connection()],
      connectable: [{ key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true, auth: 'oauth' }],
    }
    estate.projects = [{ project_id: 'p-1', project_name: 'Razah', providers: ['meta'], accounts: 1, last_synced_at: null, needs_attention: 0 }]

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('estate-link-p-1'))
    fireEvent.click(await screen.findByTestId('estate-link-p-1-meta'))

    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Connect')
    await waitFor(() => expect(screen.getByTestId('flow-destination')).toHaveValue('p-1'))
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', projectId: 'p-1', workspaceId: 'ws-1' }]))
    // The picker of platforms is the hub's own entry; the estate skipped it by naming the platform.
    expect(screen.queryByTestId('provider-picker')).toBeNull()
  })

  /** One connect entry per surface: with rows it is in the head, without rows it is in the empty state. */
  it('has exactly one connect button, with and without connections', async () => {
    const { unmount } = renderWithProviders(<IntegrationsPage />)
    await screen.findByTestId('hub-connect-empty')
    expect(screen.queryByTestId('hub-connect')).toBeNull()
    expect(screen.getAllByRole('button', { name: /Connect a source/ })).toHaveLength(1)
    unmount()

    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)
    await screen.findByTestId('hub-connect')
    expect(screen.queryByTestId('hub-connect-empty')).toBeNull()
    expect(screen.getAllByRole('button', { name: /Connect a source/ })).toHaveLength(1)
  })

  it('carries both truths into the drawer header', async () => {
    hub.data = {
      connections: [connection({ connection_state: 'REAUTH_REQUIRED', sync_state: 'FAILED' })],
      connectable: [],
    }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))

    expect(await screen.findByTestId('drawer-auth')).toHaveTextContent('Needs reconnecting')
    expect(screen.getByTestId('drawer-sync')).toHaveTextContent('Sync failed')
  })

  /** Discovery is not a drawer act any more: no refresh-the-catalogue here, and re-authorising stays. */
  it('keeps catalogue refresh out of the drawer', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-tab-settings'))

    expect(screen.queryByTestId('drawer-refresh')).toBeNull()
    expect(screen.getByTestId('drawer-reauthorise')).toBeInTheDocument()
  })

  /**
   * The single OAuth path. Re-authorising goes through the connection flow, which starts OAuth with
   * the connection's client workspace and project — the state the callback needs to find THIS
   * connection again instead of opening a second one for the same accounts.
   */
  it('re-authorises through the flow, carrying the workspace and the project', async () => {
    hub.data = { connections: [connection({ connection_state: 'REAUTH_REQUIRED' })], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-tab-settings'))
    fireEvent.click(screen.getByTestId('drawer-reauthorise'))

    // Not straight to the provider: the flow opens first, on its sign-in stage.
    expect(await screen.findByTestId('connection-flow')).toHaveTextContent('Reconnect')
    expect(started.calls).toEqual([])
    expect(await screen.findByTestId('flow-step-login')).toHaveTextContent('selected accounts and their clients are untouched')

    await waitFor(() => expect(screen.getByTestId('flow-authorise')).toBeEnabled())
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', projectId: 'p-1', workspaceId: 'ws-1' }]))
  })

  /** Disconnecting states how many accounts stop syncing before it will go. */
  it('states the consequence of disconnecting before doing it', async () => {
    hub.data = { connections: [connection({ selected_accounts: 3 })], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-tab-settings'))
    fireEvent.click(screen.getByTestId('drawer-disconnect'))

    expect(screen.getByTestId('drawer-disconnect')).toHaveTextContent('3 accounts stop syncing')
  })

  /** A refused authorisation is not offered a «Sync now» whose only outcome is the same refusal. */
  it('offers no sync while the authorisation is refused', async () => {
    hub.data = { connections: [connection({ connection_state: 'REAUTH_REQUIRED' })], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-tab-settings'))

    expect(screen.getByTestId('drawer-reauth-note')).toBeInTheDocument()
    expect(screen.queryByTestId('drawer-sync-now')).toBeNull()
  })
})

/**
 * §§23–25 — three stages, a destination decided before the provider, and a receipt at the end.
 */
describe('the connection flow', () => {
  beforeEach(() => {
    hub.data = {
      connections: [],
      connectable: [{ key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true, auth: 'oauth' }],
    }
    started.calls = []
  })

  it('offers only the platforms this install can actually start', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))

    expect(await screen.findByTestId('provider-pick-meta')).toBeInTheDocument()
  })

  it('will not leave for the provider until a client is chosen', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))

    const authorise = await screen.findByTestId('flow-authorise')
    expect(authorise).toBeDisabled()

    // The client list is a request of its own; the control does not exist until it lands.
    fireEvent.change(await screen.findByTestId('flow-destination'), { target: { value: 'p-1' } })
    await waitFor(() => expect(screen.getByTestId('flow-authorise')).toBeEnabled())
  })

  /** The destination rides the OAuth state, which is why it is asked before the provider. */
  it('carries the chosen client into the authorisation request', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))
    fireEvent.change(await screen.findByTestId('flow-destination'), { target: { value: 'p-1' } })
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', projectId: 'p-1', workspaceId: 'ws-1' }]))
  })

  /** META-SAAS-APP-001 — the Meta app is chosen per connection, and only once the SaaS app is available. */
  it('offers the SaaS Meta app only when it is available, and sends the choice with the authorisation', async () => {
    metaApps.saas = true
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))
    fireEvent.change(await screen.findByTestId('flow-destination'), { target: { value: 'p-1' } })
    expect(await screen.findByTestId('flow-meta-app-live')).toBeChecked()
    fireEvent.click(screen.getByTestId('flow-meta-app-saas'))
    fireEvent.click(screen.getByTestId('flow-authorise'))

    await waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', projectId: 'p-1', workspaceId: 'ws-1', appProfile: 'saas' }]))
    metaApps.saas = false
  })

  it('shows no app choice while the SaaS app is not available', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))
    await screen.findByTestId('flow-authorise')
    expect(screen.queryByTestId('flow-meta-app')).not.toBeInTheDocument()
  })

  /** META-SAAS-APP-001 — the isolated test workspace connects through the SaaS app only, and says so when that app is not ready. */
  it('offers only the SaaS app in the test workspace, and explains when it is not ready', async () => {
    metaApps.testWorkspace = true
    renderWithProviders(<IntegrationsPage />)
    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))
    expect(await screen.findByTestId('flow-meta-saas-not-ready')).toBeInTheDocument()
    expect(screen.queryByTestId('flow-meta-app-live')).not.toBeInTheDocument()
    metaApps.testWorkspace = false
  })

  it('in a ready test workspace the only choice is the SaaS app', async () => {
    metaApps.testWorkspace = true
    metaApps.saas = true
    renderWithProviders(<IntegrationsPage />)
    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))
    expect(await screen.findByTestId('flow-meta-app-saas')).toBeChecked()
    expect(screen.queryByTestId('flow-meta-app-live')).not.toBeInTheDocument()
    expect(screen.queryByTestId('flow-meta-saas-not-ready')).not.toBeInTheDocument()
    metaApps.testWorkspace = false
    metaApps.saas = false
  })

  /** Three stages named before the reader leaves, so «how much more of this is there» has an answer. */
  it('names the three stages and marks the one the reader is on', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))

    const stepper = await screen.findByTestId('flow-stepper')
    expect(stepper).toHaveTextContent('Sign in')
    expect(stepper).toHaveTextContent('Organisation')
    expect(stepper).toHaveTextContent('Ad accounts')
    expect(screen.getByTestId('flow-stepper-login')).toHaveAttribute('aria-current', 'step')
  })

  /** A provider with no real hierarchy is not shown an organisation stage it will never be asked. */
  it('leaves the organisation stage out for a provider that has none', async () => {
    hub.data = {
      connections: [],
      connectable: [{ key: 'x', label: 'X Ads', label_ar: 'إكس', kind: 'advertising', has_parent: false, auth: 'oauth' }],
    }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-x'))

    await screen.findByTestId('flow-stepper')
    expect(screen.queryByTestId('flow-stepper-parent')).toBeNull()
  })

  /** What the product will read, said in our words before the consent screen says it in theirs. */
  it('states what will be read, and that nothing is written', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-connect-empty'))
    fireEvent.click(await screen.findByTestId('provider-pick-meta'))

    const login = await screen.findByTestId('flow-step-login')
    expect(login).toHaveTextContent('Read only')
    expect(login).toHaveTextContent('No account is ever synced unless you choose it')
  })
})
