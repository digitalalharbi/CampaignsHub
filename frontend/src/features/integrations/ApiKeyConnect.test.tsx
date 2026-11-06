import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import type { ConnectionHub as Hub } from './api'
import { renderWithProviders } from '@/test/utils'
import { useProject } from '@/stores/project'

/**
 * INTEG-APIKEY-001 — the connect experience for a provider the advertiser keys themselves.
 *
 * The product had one connect journey and it assumed a consent screen. These hold the two halves
 * that assumption got wrong: a provider with no consent screen must not be sent to one, and the
 * organisation step that exists because Meta, TikTok, Snapchat and Google have one must not be drawn
 * for a provider that has none — an empty step is a dead end, not a question.
 */

const hub = vi.hoisted(() => ({ data: { connections: [], connectable: [] } as Hub }))
const started = vi.hoisted(() => ({ calls: [] as string[] }))
const asked = vi.hoisted(() => ({
  directory: [] as string[],
  confirmed: [] as Array<{ projectId: string; connectionId: string; externalId: string }>,
  estate: [] as string[],
}))
const keyed = vi.hoisted(() => ({
  calls: [] as Array<{ provider: string; apiKey: string; projectId?: string | null }>,
  fail: null as string | null,
}))

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
    startPlatformOAuth: (provider: string) => {
      started.calls.push(provider)
      return Promise.resolve({ authorization_url: `https://platform.test/${provider}` })
    },
    connectWithApiKey: (provider: string, apiKey: string, projectId?: string | null) => {
      keyed.calls.push({ provider, apiKey, projectId })
      return keyed.fail === null
        ? Promise.resolve({ provider, connection: 'conn-1', accounts: 1, key_hint: apiKey.slice(-4) })
        : Promise.reject(new Error(keyed.fail))
    },
    /*
     * INTEG-PROJECT-LINK-001 — what the PROJECT's account step asks once the key has opened the project's own
     * connection: the provider, on demand. One «no organisation» answer, one account, nothing stored yet.
     */
    fetchLinkOrganisations: (projectId: string, connectionId: string) => {
      asked.directory.push(`organisations:${projectId}:${connectionId}`)
      return Promise.resolve({ organisations: [{ id: null, name: null }] })
    },
    fetchLinkAccounts: (projectId: string, connectionId: string) => {
      asked.directory.push(`accounts:${projectId}:${connectionId}`)
      return Promise.resolve({ accounts: [{ external_id: 'acct_live_1', name: 'Acme Riyadh', currency: 'SAR', status: 'active', linked: null }], total: 1, page: 1, per_page: 25 })
    },
    confirmLinkAccount: (input: { projectId: string; connectionId: string; externalId: string }) => {
      asked.confirmed.push(input)
      return Promise.resolve({ account_id: 'acct-1', relinked: false })
    },
    fetchProjectLink: () => Promise.reject(new Error('not asked in these tests')),
    /*
     * What the account step reads once the key has opened a connection. No parent layer and no
     * parent label: this provider has neither, and a fixture that invented one would let the screen
     * draw a step the server would never describe.
     */
    fetchConnectionHierarchy: () => { asked.estate.push('hierarchy'); return Promise.resolve({
      connection: {
        id: 'conn-1', provider: 'openai_ads', label: 'ChatGPT Ads', label_ar: 'إعلانات ChatGPT',
        status: 'connected', client_workspace_id: null,
      },
      has_parent: false,
      parent_label: null,
      parents: [],
      discovered_count: 1,
      assigned_count: 0,
      wizard: {
        state: 'needs_selection', discovered: 1, assigned: 0, synced: 0,
        has_parent: false, resumable: true, next_step: 'accounts',
      },
    }) },
    fetchDiscoveredAccounts: () => Promise.resolve({
      accounts: [{
        id: 'acct-1', external_id: 'acct_live_1', name: 'Acme Riyadh', parent_external_id: null,
        parent_name: null, currency: 'SAR', timezone: 'Asia/Riyadh', status: 'active',
        assigned_project_id: null, assigned: false, last_synced_at: null, access_lost_at: null,
      }],
      meta: { total: 1, per_page: 25, current_page: 1, last_page: 1 },
    }),
    fetchPlanUsage: () => Promise.resolve({ ad_accounts: { limit: 5, used: 0, remaining: 5 } }),
  }
})

vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve([]),
  listProjects: () => Promise.resolve([{ id: 'p-1', name: 'Razah' }]),
}))

async function openTheFlow() {
  renderWithProviders(<IntegrationsPage />)
  fireEvent.click(await screen.findByTestId('hub-connect-empty'))
  // INTEG-PROJECT-SPACE-001 — the platform is chosen inside the project's own journey.
  fireEvent.click(await screen.findByTestId('link-platform-openai_ads'))
  fireEvent.click(screen.getByTestId('link-next'))
}

describe('connecting a provider that issues the advertiser a key', () => {
  beforeEach(() => {
    // INTEG-PROJECT-SPACE-001 — the centre is the SELECTED project's space; these read it standing in p-1.
    useProject.setState({ currentProjectId: 'p-1' })
    hub.data = {
      connections: [],
      connectable: [{
        key: 'openai_ads',
        /*
          The CATALOGUE's labels, which is what the hub actually sends — the interface's name, not
          the platform's. The row is expected to render «إعلانات ChatGPT» anyway, and with the
          platform name in the fixture this test passed by echoing it back.
        */
        label: 'OpenAI Advertiser API',
        label_ar: 'واجهة OpenAI الإعلانية',
        kind: 'advertising',
        // No consent screen, and no parent layer: OpenAI publishes no Business Centre, Portfolio or
        // Manager Account for advertising.
        has_parent: false,
        auth: 'api_key',
      }],
    }
    started.calls = []
    keyed.calls = []
    keyed.fail = null
    asked.directory = []
    asked.confirmed = []
    asked.estate = []
  })

  /**
   * The row says which act pressing it begins.
   *
   * A reader who expected a consent screen and met a key box goes looking for the key with the
   * dialog open; one told beforehand brings it.
   */
  it('says how the provider connects before it is pressed', async () => {
    // Arabic explicitly: it is the product's default, and the row is read there first.
    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('hub-connect-empty'))

    const row = await screen.findByTestId('link-platform-row-openai_ads')

    expect(row).toHaveTextContent('إعلانات ChatGPT')
    // Short labels (Owner 2026-11-06): two words say which act the row begins.
    expect(row).toHaveTextContent('مفتاح API')
    expect(row.textContent).not.toMatch(/تسجيل دخول/)
  })

  /** **The defect this prevents.** A provider with no consent screen offered a trip to one. */
  it('asks for the key instead of sending the reader to a consent screen', async () => {
    await openTheFlow()

    expect(await screen.findByTestId('flow-api-key')).toBeInTheDocument()
    expect(screen.queryByTestId('flow-authorise')).toBeNull()
  })

  /** The key is a secret in a shared office, and a remembered copy is one nobody asked for. */
  it('does not display the key back, and does not offer it to the browser to remember', async () => {
    await openTheFlow()

    const field = await screen.findByTestId('flow-api-key')

    expect(field).toHaveAttribute('type', 'password')
    expect(field).toHaveAttribute('autocomplete', 'off')
  })

  it('will not connect until both the client and the key are given', async () => {
    await openTheFlow()

    const connect = await screen.findByTestId('flow-connect-key')
    expect(connect).toBeDisabled()
    // INTEG-PROJECT-LINK-001 — the project was fixed in the picker; the flow states it and asks nothing.
    expect(await screen.findByTestId('flow-project-fixed')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('flow-connect-key')).toBeDisabled())

    fireEvent.change(screen.getByTestId('flow-api-key'), { target: { value: 'sk-ads-live-0000beef' } })
    await waitFor(() => expect(screen.getByTestId('flow-connect-key')).toBeEnabled())
  })

  /** Nothing leaves. The answer is the outcome, and the next question opens in the same dialog. */
  it('continues into the account step without a round trip to the provider', async () => {
    await openTheFlow()
    // INTEG-PROJECT-LINK-001 — the project was fixed in the picker; the flow states it and asks nothing.
    expect(await screen.findByTestId('flow-project-fixed')).toBeInTheDocument()
    fireEvent.change(screen.getByTestId('flow-api-key'), { target: { value: 'sk-ads-live-0000beef' } })
    fireEvent.click(await screen.findByTestId('flow-connect-key'))

    await waitFor(() => expect(keyed.calls).toHaveLength(1))
    expect(keyed.calls[0]).toEqual({ provider: 'openai_ads', apiKey: 'sk-ads-live-0000beef', projectId: 'p-1' })

    // The consent flow was never started for a provider that has none.
    expect(started.calls).toEqual([])
    // And the key box is gone, because the stage moved on.
    await waitFor(() => expect(screen.queryByTestId('flow-api-key')).toBeNull())
  })

  /**
   * INTEG-PROJECT-LINK-001 — a key pasted for a project is that project's own link: the next steps are the
   * project's (organisation → account → done), asked on demand, and only the confirmed account is stored.
   * The estate wizard — which catalogues everything the key can list — is never opened.
   */
  it('walks the project\'s own account step after the key, and confirms exactly one account', async () => {
    await openTheFlow()
    fireEvent.change(await screen.findByTestId('flow-api-key'), { target: { value: 'sk-ads-live-0000beef' } })
    fireEvent.click(await screen.findByTestId('flow-connect-key'))

    const picker = await screen.findByTestId('project-account-picker')
    expect(picker).toHaveTextContent('Razah')
    // No organisation layer for this provider: the one «no organisation» answer is passed, not asked.
    fireEvent.click(await screen.findByTestId('picker-account-acct_live_1'))
    expect(asked.directory).toEqual(['organisations:p-1:conn-1', 'accounts:p-1:conn-1'])
    expect(asked.confirmed).toEqual([])

    fireEvent.click(screen.getByTestId('link-next'))
    await waitFor(() => expect(asked.confirmed).toEqual([{ projectId: 'p-1', connectionId: 'conn-1', externalId: 'acct_live_1', organisation: null }]))
    // The workspace-wide wizard and its catalogue were never read.
    expect(asked.estate).toEqual([])
    expect(screen.queryByTestId('connection-flow')).toBeNull()
  })

  /** A refusal is reported where it happened, with the key still in the reader's hands. */
  it('reports a refused key on the step that asked for it', async () => {
    keyed.fail = 'The key was accepted, but it lists no ad account.'

    await openTheFlow()
    // INTEG-PROJECT-LINK-001 — the project was fixed in the picker; the flow states it and asks nothing.
    expect(await screen.findByTestId('flow-project-fixed')).toBeInTheDocument()
    fireEvent.change(screen.getByTestId('flow-api-key'), { target: { value: 'sk-ads-live-0000beef' } })
    fireEvent.click(await screen.findByTestId('flow-connect-key'))

    expect(await screen.findByTestId('flow-key-error')).toBeInTheDocument()
    expect(screen.getByTestId('flow-api-key')).toBeInTheDocument()
  })

  /**
   * And no organisation step is drawn for a provider that has no such layer.
   *
   * `ProviderHierarchy` refuses to invent one on the server; this is the same refusal on the screen,
   * where inventing it would read as «choose a business» above an empty list.
   */
  it('draws no organisation step', async () => {
    await openTheFlow()

    const stepper = await screen.findByTestId('connection-flow')

    expect(stepper.textContent).not.toMatch(/الأعمال|business|Business|المؤسسة/)
  })
})
