import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import type { ConnectedEstate, EstateProject, EstateProvider } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §§16–17 — the estate is per CLIENT, and it says both truths.
 *
 * What these defend is not a layout. It is that a client's row is built from what somebody CHOSE,
 * and that «the authorisation is refused» and «a run is open» are shown at the same time — the pair
 * whose collapse into one word hid the Reconnect button on Production.
 */

const estate = vi.hoisted(() => ({ data: { projects: [], unselected_accounts: 0 } as ConnectedEstate }))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listConnectors: () => Promise.resolve([]),
    fetchResumableConnections: () => Promise.resolve({ connections: [], resumable: [] }),
    fetchConnectedEstate: () => Promise.resolve(estate.data),
    listAccounts: () => Promise.resolve({
      accounts: [], summary: { total: 0, linked: 0, unlinked: 0 },
      meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 },
    }),
    startPlatformOAuth: (provider: string) =>
      Promise.resolve({ authorization_url: `https://platform.test/${provider}` }),
  }
})

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
}))

function provider(over: Partial<EstateProvider> = {}): EstateProvider {
  return {
    key: 'meta',
    label: 'Meta Ads',
    label_ar: 'ميتا',
    connection_id: 'conn-1',
    connection_state: 'CONNECTED',
    sync_state: 'SUCCEEDED',
    health: 'complete',
    accounts: 1,
    currencies: ['SAR'],
    campaigns: 4,
    active_campaigns: 2,
    last_success_at: '2026-09-30T09:00:00+00:00',
    next_sync_at: '2026-09-30T10:00:00+00:00',
    ...over,
  }
}

function project(over: Partial<EstateProject> = {}): EstateProject {
  const providers = over.providers ?? [provider()]
  return {
    id: 'p-1',
    name: 'Razah',
    status: 'active',
    client: { id: 'w-1', name: 'Razah Avenue' },
    accounts: providers.reduce((n, p) => n + p.accounts, 0),
    campaigns: providers.reduce((n, p) => n + p.campaigns, 0),
    active_campaigns: providers.reduce((n, p) => n + p.active_campaigns, 0),
    last_success_at: '2026-09-30T09:00:00+00:00',
    health: 'complete',
    ...over,
    providers,
  }
}

describe('the connected estate', () => {
  beforeEach(() => {
    estate.data = { projects: [], unselected_accounts: 0 }
  })

  it('opens on the platform grid while nothing is connected, and offers no lens to switch', async () => {
    renderWithProviders(<IntegrationsPage />)

    await waitFor(() => expect(screen.getByTestId('ad-platforms-panel')).toBeInTheDocument())
    expect(screen.queryByTestId('estate-view-clients')).toBeNull()
    expect(screen.queryByTestId('connected-estate')).toBeNull()
  })

  it('opens on the clients once anything is connected', async () => {
    estate.data = { projects: [project()], unselected_accounts: 0 }

    renderWithProviders(<IntegrationsPage />)

    await waitFor(() => expect(screen.getByTestId('connected-estate')).toBeInTheDocument())
    expect(screen.getByTestId('estate-project-p-1')).toHaveTextContent('Razah')
    expect(screen.queryByTestId('ad-platforms-panel')).toBeNull()
  })

  /**
   * The pair that broke Production, on the surface that replaced it.
   *
   * A refused grant with a run open must say BOTH, and must offer the reconnect — never a «Sync now»
   * whose only possible outcome is the same refusal.
   */
  it('shows a refused authorisation and an open run at the same time, and offers only the reconnect', async () => {
    estate.data = {
      projects: [project({
        health: 'reauth',
        providers: [provider({ connection_state: 'REAUTH_REQUIRED', sync_state: 'SYNCING', health: 'reauth' })],
      })],
      unselected_accounts: 0,
    }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Razah/ }))

    expect(screen.getByTestId('estate-auth-meta')).toHaveTextContent('Authorisation needs renewing')
    expect(screen.getByTestId('estate-sync-meta')).toHaveTextContent('Syncing')
    expect(screen.getByTestId('estate-reauth-meta')).toBeInTheDocument()
    expect(screen.queryByTestId('estate-run-sync-meta')).toBeNull()
  })

  /** DISCOVERED != SELECTED: the unchosen are a number, never rows under a client's name. */
  it('states how many discovered accounts nobody has chosen', async () => {
    estate.data = { projects: [project()], unselected_accounts: 16 }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('estate-unselected')).toHaveTextContent('16 ad accounts discovered and chosen by nobody')
  })

  /** A client's own numbers, and its currency listed rather than summed into something meaningless. */
  it('reports the clients real counts and its currencies without adding them up', async () => {
    estate.data = {
      projects: [project({
        providers: [provider({ accounts: 2, active_campaigns: 3, currencies: ['SAR', 'USD'] })],
      })],
      unselected_accounts: 0,
    }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Razah/ }))

    const block = screen.getByTestId('estate-provider-meta')
    expect(block).toHaveTextContent('SAR · USD')
    expect(block).toHaveTextContent('Selected accounts')
  })

  /** «لا توجد بيانات» is not a failure and is never dressed as one. */
  it('reads an empty but working connection as no data rather than as broken', async () => {
    estate.data = {
      projects: [project({
        health: 'no_data',
        providers: [provider({ sync_state: 'SUCCEEDED', health: 'no_data', last_success_at: null, campaigns: 0, active_campaigns: 0 })],
      })],
      unselected_accounts: 0,
    }

    renderWithProviders(<IntegrationsPage />)

    expect(await screen.findByTestId('estate-health-p-1')).toHaveTextContent('No data')
  })

  it('offers the platform grid when a customer wants to connect another one', async () => {
    estate.data = { projects: [project()], unselected_accounts: 0 }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByTestId('estate-connect'))

    await waitFor(() => expect(screen.getByTestId('ad-platforms-panel')).toBeInTheDocument())
  })
})

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §6 — the acts a connected estate admits, told apart.
 *
 * «Refresh the account list» and «Reconnect» are the pair support transcripts are full of: somebody
 * makes an account on the platform, does not find it here, and re-authorises — a round trip through
 * a consent screen to do what one tick in the picker would have done, and one the non-authoriser
 * cannot complete at all. Disconnect is the third and is nothing like either.
 */
describe('what the estate lets a customer do next', () => {
  beforeEach(() => {
    estate.data = { projects: [project()], unselected_accounts: 0 }
  })

  it('offers refreshing the catalogue as its own act, separate from re-authorising', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Razah/ }))

    expect(screen.getByTestId('estate-refresh-meta')).toBeInTheDocument()
    expect(screen.queryByTestId('estate-reauth-meta')).toBeNull()
  })

  /** Disconnecting names the number of accounts that stop syncing before it will go. */
  it('states the consequence of disconnecting before it will do it', async () => {
    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Razah/ }))
    fireEvent.click(screen.getByTestId('estate-disconnect-meta'))

    expect(screen.getByTestId('estate-disconnect-meta')).toHaveTextContent('stop syncing')
  })

  /** Null is «it is not going to», and the row says that rather than leaving a blank. */
  it('says a refused connection will not sync again until it is fixed', async () => {
    estate.data = {
      projects: [project({
        health: 'reauth',
        providers: [provider({ connection_state: 'REAUTH_REQUIRED', health: 'reauth', next_sync_at: null })],
      })],
      unselected_accounts: 0,
    }

    renderWithProviders(<IntegrationsPage />)

    fireEvent.click(await screen.findByRole('button', { name: /Razah/ }))

    expect(screen.getByTestId('estate-provider-meta')).toHaveTextContent('Not until this is fixed')
  })
})
