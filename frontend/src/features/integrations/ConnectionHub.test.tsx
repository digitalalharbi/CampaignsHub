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
const started = vi.hoisted(() => ({ calls: [] as Array<{ provider: string; projectId?: string | null }> }))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchConnectionHub: () => Promise.resolve(hub.data),
    listConnectors: () => Promise.resolve([]),
    listAccounts: () => Promise.resolve({
      accounts: [], summary: { total: 0, linked: 0, unlinked: 0 },
      meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 },
    }),
    getAccountLogs: () => Promise.resolve({ runs: [] }),
    startPlatformOAuth: (provider: string, _ws?: string | null, projectId?: string | null) => {
      started.calls.push({ provider, projectId })
      return Promise.resolve({ authorization_url: `https://platform.test/${provider}` })
    },
  }
})

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
  listProjects: () => Promise.resolve([{ id: 'p-1', name: 'Razah' }]),
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
    // Selected only, by default: seventeen rows is how the old page taught people everything syncs.
    expect(screen.getByTestId('drawer-only-selected')).toHaveAttribute('aria-pressed', 'true')
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

  /** Refreshing the catalogue and re-authorising are different acts, and the drawer says so. */
  it('separates refreshing the account list from re-authorising', async () => {
    hub.data = { connections: [connection()], connectable: [] }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-open-c-1'))
    fireEvent.click(await screen.findByTestId('drawer-tab-settings'))

    expect(screen.getByTestId('drawer-refresh')).toBeInTheDocument()
    expect(screen.getByTestId('drawer-reauthorise')).toBeInTheDocument()
    expect(screen.getByTestId('drawer-settings')).toHaveTextContent('no consent screen')
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
      connectable: [{ key: 'meta', label: 'Meta Ads', label_ar: 'ميتا', kind: 'advertising', has_parent: true }],
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

    await waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', projectId: 'p-1' }]))
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
      connectable: [{ key: 'x', label: 'X Ads', label_ar: 'إكس', kind: 'advertising', has_parent: false }],
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
