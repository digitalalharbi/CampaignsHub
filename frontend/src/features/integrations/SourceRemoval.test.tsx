import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import { ConnectionFlow } from './ConnectionFlow'
import type { ConnectionHub as Hub, HubConnection } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEG-SOURCE-REMOVAL-001 and INTEG-PROVIDER-IDENTITY-001 — the Owner's three reports on the Connection Hub.
 *
 * A removed source must leave the list and have a way out; the row must name the PROVIDER login and not the
 * workspace email; and cancelling the connect wizard must be an act that cleans up after itself.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub, removed: [] as Hub['connections'] }))
const removals = vi.hoisted(() => ({ calls: [] as Array<{ id: string; unconfirmedOnly: boolean }> }))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchConnectionHub: () => Promise.resolve(hub.data),
    fetchConnectionHubWithRemoved: () => Promise.resolve({ ...hub.data, connections: [...hub.data.connections, ...hub.removed] }),
    removeConnection: (id: string, options: { unconfirmedOnly?: boolean } = {}) => {
      removals.calls.push({ id, unconfirmedOnly: options.unconfirmedOnly === true })
      return Promise.resolve({ removed: true, disabled_bindings: 0 })
    },
    listConnectors: () => Promise.resolve([]),
    listAccounts: () => Promise.resolve({
      accounts: [], summary: { total: 0, linked: 0, unlinked: 0 },
      meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 },
    }),
    getAccountLogs: () => Promise.resolve({ runs: [] }),
  }
})

vi.mock('./ConnectionWizard', () => ({ ConnectionWizard: () => <div data-testid="wizard-stub" /> }))

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
  listProjects: () => Promise.resolve([]),
}))

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c-1',
    provider: 'snapchat',
    label: 'Snapchat Ads',
    label_ar: 'سناب شات',
    connection_name: 'Snapchat',
    authorised_by: { name: 'Agency', email: 'agency@campaignshub.io' },
    provider_account: { label: 'Snap Person', email: 'snap@person.test', fetched_at: '2026-11-05T09:00:00+00:00' },
    removed_at: null,
    abandoned: false,
    authorised_at: '2026-09-01T09:00:00+00:00',
    selected_accounts: 0,
    discovered_accounts: 321,
    connection_state: 'CONNECTED',
    sync_state: 'NEVER_SYNCED',
    needs_attention: false,
    last_success_at: null,
    next_sync_at: null,
    projects: [],
    has_parent: true,
    discovery_blocked_reason: null,
    token_expires_at: null,
    ...over,
  }
}

describe('the Connection Hub: provider identity', () => {
  beforeEach(() => {
    hub.data = { connections: [], connectable: [] }
    hub.removed = []
    removals.calls = []
  })

  it('names the provider login first and keeps the workspace user, by name, as the secondary line', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-platform-account-c-1')).toHaveTextContent('Platform account: snap@person.test')
    expect(screen.getByTestId('hub-added-by-c-1')).toHaveTextContent('Added by: Agency')
    // INTEG-UNIFIED-002 — the workspace email is nowhere on the centre.
    expect(screen.getByTestId('connection-hub')).not.toHaveTextContent('agency@campaignshub.io')
  })

  it('falls back to the provider display name when the provider gave no email', async () => {
    hub.data = { connections: [connection({ provider_account: { label: 'Sara Meta', email: null, fetched_at: null } })], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-platform-account-c-1')).toHaveTextContent('Platform account: Sara Meta')
  })

  it('says it was not recorded, and never shows the workspace email as the platform account', async () => {
    hub.data = { connections: [connection({ provider_account: null })], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    const line = await screen.findByTestId('hub-platform-account-c-1')
    expect(line).toHaveTextContent('Platform account not recorded')
    expect(line).not.toHaveTextContent('agency@campaignshub.io')
  })
})

describe('the Connection Hub: removing a source', () => {
  beforeEach(() => {
    hub.data = { connections: [], connectable: [] }
    hub.removed = []
    removals.calls = []
  })

  it('removes a source from the row menu after a confirmation that says the history stays', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-actions-desktop-c-1'))
    fireEvent.click(screen.getByTestId('action-remove'))

    expect(screen.getByTestId('remove-source-dialog')).toHaveTextContent('Stored figures and reports stay')
    expect(removals.calls).toHaveLength(0)

    fireEvent.click(screen.getByTestId('remove-source-confirm'))

    await waitFor(() => expect(removals.calls).toEqual([{ id: 'c-1', unconfirmedOnly: false }]))
    await waitFor(() => expect(screen.queryByTestId('remove-source-dialog')).not.toBeInTheDocument())
  })

  it('keeping it removes nothing', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('hub-actions-desktop-c-1'))
    fireEvent.click(screen.getByTestId('action-remove'))
    fireEvent.click(screen.getByTestId('remove-source-keep'))

    expect(removals.calls).toHaveLength(0)
  })

  it('offers cancel / remove on the row only for an abandoned connection', async () => {
    hub.data = { connections: [connection({ id: 'c-old', abandoned: true }), connection({ id: 'c-new', abandoned: false })], connectable: [] }
    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('hub-remove-c-old')).toHaveTextContent('Cancel / remove')
    expect(screen.queryByTestId('hub-remove-c-new')).not.toBeInTheDocument()
  })

  it('lists removed sources only under the toggle, marked, with nothing to operate', async () => {
    hub.data = { connections: [connection()], connectable: [] }
    hub.removed = [connection({ id: 'c-gone', removed_at: '2026-11-04T09:00:00+00:00', connection_state: 'REVOKED' })]
    renderWithProviders(<IntegrationsPage />)

    await screen.findByTestId('hub-row-c-1')
    expect(screen.queryByTestId('hub-row-c-gone')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('hub-show-removed'))

    expect(await screen.findByTestId('hub-removed-c-gone')).toHaveTextContent('Removed')
    expect(screen.queryByTestId('hub-actions-desktop-c-gone')).not.toBeInTheDocument()
    expect(screen.getByTestId('hub-actions-desktop-c-1')).toBeInTheDocument()
  })
})

describe('cancelling the connect flow', () => {
  beforeEach(() => {
    removals.calls = []
  })

  it('removes the connection this flow opened, asking the server to do it only if unconfirmed', async () => {
    const onClose = vi.fn()
    renderWithProviders(
      <ConnectionFlow mode="connect" provider={null} connection={connection()} projectId={null} onClose={onClose} onDone={vi.fn()} />,
    )

    fireEvent.click(await screen.findByTestId('flow-cancel'))

    await waitFor(() => expect(removals.calls).toEqual([{ id: 'c-1', unconfirmedOnly: true }]))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
  })

  it('plain close leaves the connection alone', async () => {
    const onClose = vi.fn()
    renderWithProviders(
      <ConnectionFlow mode="connect" provider={null} connection={connection()} projectId={null} onClose={onClose} onDone={vi.fn()} />,
    )

    fireEvent.click(await screen.findByTestId('flow-close'))

    expect(onClose).toHaveBeenCalled()
    expect(removals.calls).toHaveLength(0)
  })

  it('has no cancel while managing an existing source', async () => {
    renderWithProviders(
      <ConnectionFlow mode="manage" provider={null} connection={connection()} projectId="p-1" onClose={vi.fn()} onDone={vi.fn()} />,
    )

    await screen.findByTestId('flow-close')
    expect(screen.queryByTestId('flow-cancel')).not.toBeInTheDocument()
  })
})
