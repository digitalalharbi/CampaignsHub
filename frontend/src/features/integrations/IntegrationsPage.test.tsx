import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { IntegrationsPage } from './IntegrationsPage'
import type { Connector } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEG-UI-001 — the page states which of four things is true, and offers only what that state admits.
 *
 * The claim worth testing is not that a badge renders. It is that «لا يوجد تطبيق مسجّل لدى المنصة»
 * and «لم يربط أحد حسابه بعد» are told apart — because they need two different people to act, and a
 * page that shows one button for both always instructs one of them wrongly.
 */

const rows = vi.hoisted(() => ({ data: [] as Connector[] }))
const started = vi.hoisted(() => ({ calls: [] as Array<{ provider: string; clientWorkspaceId?: string | null }> }))
const workspaces = vi.hoisted(() => ({ data: [] as Array<{ id: string; name: string }> }))
const wizardStates = vi.hoisted(() => ({ connections: [] as unknown[], resumable: [] as unknown[] }))
const revoked = vi.hoisted(() => ({ calls: [] as string[] }))

// The client picker reads the tenant's own client workspaces — the `→ Client` link in the chain.
vi.mock('@/features/projects/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  listClientWorkspaces: () => Promise.resolve(workspaces.data),
}))

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listConnectors: () => Promise.resolve(rows.data),
    startPlatformOAuth: (provider: string, clientWorkspaceId?: string | null) => {
      started.calls.push({ provider, clientWorkspaceId })
      return Promise.resolve({ authorization_url: `https://platform.test/authorize?provider=${provider}` })
    },
    syncConnector: () => Promise.resolve({ success: true, count: 1 }),
    connectConnector: () => Promise.resolve({ key: 'sandbox', status: 'connected' }),
    fetchResumableConnections: () => Promise.resolve(wizardStates),
    // The wizard's own reads. Snapchat's shape: organisations, then their accounts.
    fetchConnectionHierarchy: () => Promise.resolve({
      connection: { id: 'conn-1', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', status: 'connected', client_workspace_id: null },
      has_parent: true,
      parent_label: { key: 'organization', label: 'Organization', labelAr: 'المؤسسة' },
      parents: [{ external_id: 'org-1', name: 'Acme Media', account_count: 309 }],
      discovered_count: 309,
      assigned_count: 0,
      wizard: { state: 'needs_selection', discovered: 309, assigned: 0, synced: 0, has_parent: true, resumable: true, next_step: 'parent' },
    }),
    fetchDiscoveredAccounts: () => Promise.resolve({
      accounts: [], meta: { total: 309, per_page: 25, current_page: 1, last_page: 13 },
    }),
    fetchPlanUsage: () => Promise.resolve({ ad_accounts: { limit: 5, used: 0, remaining: 5 } }),
    revokeConnection: (connectionId: string) => {
      revoked.calls.push(connectionId)
      return Promise.resolve({ status: 'revoked' })
    },
  }
})

function connector(over: Partial<Connector> & Pick<Connector, 'key'>): Connector {
  return {
    label: over.key, status: 'disconnected', ad_account_id: null,
    last_synced_at: null, last_sync_error: null, is_ad_platform: true,
    state: 'disconnected', accounts: 0,
    connection_error: null, token_expires_at: null, data_last_synced_at: null,
    ...over,
  }
}

describe('IntegrationsPage — the four states', () => {
  afterEach(() => {
    rows.data = []
    started.calls = []
    workspaces.data = []
  })

  /**
   * Awaiting credentials offers nothing to press, and says nothing about the system's own keys.
   *
   * It used to print «ينقص: developer_token». That is an instruction for `/admin` addressed to the
   * wrong reader — a customer cannot obtain a developer token for our OAuth app — so the named list
   * moved to the console and this page says the true, sufficient thing instead (PROVCFG-001).
   */
  it('an unconfigured platform offers nothing to press and names no system credential', async () => {
    rows.data = [connector({ key: 'google', label: 'Google Ads API', state: 'awaiting_credentials' })]

    const { container } = renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-google')).toHaveTextContent('Awaiting credentials')
    expect(screen.getByTestId('connector-needs-operator-google'))
      .toHaveTextContent('not open for connecting yet')
    expect(container.innerHTML).not.toContain('developer_token')
    // The distinction that matters: nobody using this page can fix it, so nothing invites them to try.
    expect(screen.queryByTestId('connector-connect-google')).not.toBeInTheDocument()
    expect(screen.getByText(/Needs setup by the platform operator/i)).toBeInTheDocument()
  })

  /**
   * A provider the operator SUSPENDED is a different fact from one nobody has configured, and the
   * data keeps them apart even though the sentence a customer reads is the same. What must not
   * happen is a connect button the OAuth start is going to refuse anyway.
   */
  it('a provider the operator took out of service reads as unavailable and offers no connect button', async () => {
    rows.data = [connector({ key: 'meta', label: 'Meta Marketing API', state: 'unavailable' })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-meta')).toHaveTextContent('Currently unavailable')
    expect(screen.queryByTestId('connector-connect-meta')).not.toBeInTheDocument()
    expect(screen.getByTestId('connector-needs-operator-meta')).toBeInTheDocument()
  })

  /** …and a CONFIGURED platform nobody has authorised is the opposite: one clear action. */
  it('a configured but unauthorised platform offers connect, and it starts the real flow', async () => {
    rows.data = [connector({ key: 'meta', label: 'Meta Marketing API', state: 'disconnected' })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-meta')).toHaveTextContent('Not connected')
    fireEvent.click(screen.getByTestId('connector-connect-meta'))

    await vi.waitFor(() => expect(started.calls).toEqual([{ provider: 'meta', clientWorkspaceId: null }]))
  })

  it('a connected platform shows its accounts and when data last arrived', async () => {
    rows.data = [connector({
      key: 'snapchat', label: 'Snapchat Marketing API', state: 'connected',
      accounts: 3, data_last_synced_at: '2026-08-05T06:30:00Z',
    })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-snapchat')).toHaveTextContent('Connected')
    const line = screen.getByTestId('connector-synced-snapchat')
    expect(line).toHaveTextContent('3 ad account(s)')
    expect(line).toHaveTextContent(/Last sync/)
    expect(screen.getByTestId('connector-sync-snapchat')).toBeInTheDocument()
  })

  /** A sync in flight is visible, so nobody presses a button that would do nothing twice. */
  it('a syncing platform says so and offers no second sync', async () => {
    rows.data = [connector({ key: 'tiktok', label: 'TikTok Marketing API', state: 'syncing', accounts: 1 })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-tiktok')).toHaveTextContent('Syncing')
    expect(screen.queryByTestId('connector-sync-tiktok')).not.toBeInTheDocument()
    expect(screen.getByText(/A sync is running now/i)).toBeInTheDocument()
  })

  /** An error shows the platform's own reason — the customer fixes this by connecting again. */
  it('an errored platform shows the reason and offers reconnect', async () => {
    rows.data = [connector({
      key: 'linkedin', label: 'LinkedIn Marketing API', state: 'error',
      connection_error: 'Session has been revoked',
    })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-linkedin')).toHaveTextContent('Error')
    expect(screen.getByTestId('connector-error-linkedin')).toHaveTextContent('Session has been revoked')
    expect(screen.getByTestId('connector-connect-linkedin')).toHaveTextContent(/Reconnect/i)
  })

  /** The products order holds here as everywhere else (PLATFORM-ORDER-001). */
  it('lists the six platforms in the products order', async () => {
    rows.data = ['linkedin', 'meta', 'x', 'snapchat', 'google', 'tiktok']
      .map((key) => connector({ key, label: key }))

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    await screen.findByTestId('connector-state-snapchat')
    const order = screen.getAllByTestId(/^connector-state-/).map((n) => n.getAttribute('data-testid'))
    expect(order).toEqual([
      'connector-state-snapchat', 'connector-state-tiktok', 'connector-state-meta',
      'connector-state-google', 'connector-state-x', 'connector-state-linkedin',
    ])
  })

  /** The callback comes back through the URL, and a human needs it in words. */
  it('reports the outcome the OAuth callback redirected back with', async () => {
    rows.data = [connector({ key: 'meta', state: 'connected', accounts: 2 })]

    renderWithProviders(<IntegrationsPage />, {
      route: '/app/integrations?provider=meta&outcome=connected&accounts=2',
      locale: 'en',
    })

    expect(await screen.findByTestId('integration-outcome')).toHaveTextContent('2 ad account(s) discovered')
  })

  it('explains an expired authorisation link rather than showing a code', async () => {
    rows.data = [connector({ key: 'meta' })]

    renderWithProviders(<IntegrationsPage />, {
      route: '/app/integrations?provider=meta&outcome=invalid_state',
      locale: 'en',
    })

    expect(await screen.findByTestId('integration-outcome')).toHaveTextContent(/expired or was already used/i)
  })

  /** Arabic is the first language of this product, and its numbers stay Latin. */
  it('reads in Arabic with Latin digits', async () => {
    rows.data = [connector({
      key: 'snapchat', label: 'Snapchat', state: 'connected',
      accounts: 3, data_last_synced_at: '2026-08-05T06:30:00Z',
    })]

    renderWithProviders(<IntegrationsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('connector-state-snapchat')).toHaveTextContent('متصل')
    const line = screen.getByTestId('connector-synced-snapchat')
    expect(line).toHaveTextContent('3 حساب إعلاني')
    expect(line.textContent ?? '').not.toMatch(/[٠-٩]/)
  })
})

/**
 * CONNECT-001 — the `→ Client` link in the chain.
 *
 *     system provider configuration → user OAuth consent → external account → client → project
 *
 * The choice is made HERE, by an authenticated member, and travels inside the single-use state. It is
 * asked only of a workspace that HAS clients: an advertiser connecting its own accounts has exactly
 * one answer and being asked for it is friction, while an agency has five and connecting "to nothing
 * in particular" is how an ad account ends up attributed to whoever was on screen at the time.
 */
describe('IntegrationsPage — which client the accounts belong to', () => {
  afterEach(() => {
    rows.data = []
    started.calls = []
    workspaces.data = []
  })

  it('does not ask an advertiser, who has only one possible answer', async () => {
    rows.data = [connector({ key: 'meta', state: 'disconnected' })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    await screen.findByTestId('connector-state-meta')
    expect(screen.queryByTestId('connect-client-workspace')).not.toBeInTheDocument()
  })

  it('asks an agency, and carries the answer into the authorisation', async () => {
    rows.data = [connector({ key: 'meta', state: 'disconnected' })]
    workspaces.data = [{ id: 'ws-acme', name: 'Acme' }, { id: 'ws-beta', name: 'Beta' }]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    const picker = await screen.findByTestId('connect-client-workspace')
    // The default is the workspace itself — a house account is a real answer, not a missing one.
    expect(picker.querySelector('select')).toHaveValue('')

    fireEvent.change(picker.querySelector('select')!, { target: { value: 'ws-beta' } })
    fireEvent.click(screen.getByTestId('connector-connect-meta'))

    await vi.waitFor(() => expect(started.calls)
      .toEqual([{ provider: 'meta', clientWorkspaceId: 'ws-beta' }]))
  })
})

/**
 * ORCH-100 §41 — «متصل» was the end of the story, and it should not have been.
 *
 * The live Snapchat authorisation discovered 309 ad accounts and connected none of them to a
 * project. The card said «متصل · آخر مزامنة الآن» and offered a sync button that would have synced
 * nothing, because a sync runs on assignments and there were none. These hold the sentence that
 * replaces it.
 */
describe('an authorisation with nothing selected yet', () => {
  afterEach(() => {
    wizardStates.connections = []
    wizardStates.resumable = []
    rows.data = []
  })

  function needsSelection(provider = 'snapchat', discovered = 309) {
    const entry = {
      connection: { id: 'conn-1', provider, label: 'Snapchat', label_ar: 'سناب شات', client_workspace_id: null },
      state: 'needs_selection' as const,
      discovered,
      assigned: 0,
      synced: 0,
      has_parent: true,
      resumable: true,
      next_step: 'parent' as const,
    }
    wizardStates.connections = [entry]
    wizardStates.resumable = [entry]
  }

  it('says how many accounts are available and that none is connected', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 309 })]
    needsSelection()

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const line = await screen.findByTestId('connector-needs-selection-snapchat')
    expect(line.textContent).toContain('309')
    // The number that was missing: nothing has been connected to a project.
    expect(line.textContent).toMatch(/لم يُربط|none connected/)
  })

  it('offers selecting accounts rather than a sync that would sync nothing', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 309 })]
    needsSelection()

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    expect(await screen.findByTestId('connector-select-snapchat')).toBeTruthy()
    expect(screen.queryByTestId('connector-sync-snapchat')).toBeNull()
  })

  it('offers to resume the unfinished connection instead of authorising again', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 309 })]
    needsSelection()

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const banner = await screen.findByTestId('unfinished-connection')
    expect(banner.textContent).toContain('309')
    expect(screen.getByTestId('resume-connection')).toBeTruthy()
  })

  it('opens the wizard from the card', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 309 })]
    needsSelection()

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    fireEvent.click(await screen.findByTestId('connector-select-snapchat'))

    expect(await screen.findByTestId('connection-wizard')).toBeTruthy()
  })

  it('a connection with assignments but no sync says the first sync is pending', async () => {
    rows.data = [connector({ key: 'meta', state: 'connected', accounts: 2 })]
    wizardStates.connections = [{
      connection: { id: 'conn-2', provider: 'meta', label: 'Meta', label_ar: 'ميتا', client_workspace_id: null },
      state: 'first_sync_pending', discovered: 2, assigned: 1, synced: 0,
      has_parent: false, resumable: false, next_step: 'sync',
    }]

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const line = await screen.findByTestId('connector-first-sync-meta')
    expect(line.textContent).toMatch(/بانتظار أول مزامنة|first sync pending/)
    // Not resumable: this is not an unfinished authorisation, it is an unstarted sync.
    expect(screen.queryByTestId('unfinished-connection')).toBeNull()
  })

  /**
   * RUNTIME-100 §31 — the card summarises its accounts instead of claiming one state for all of them.
   *
   * Nine of ten syncing and one whose access was withdrawn used to render as a single green «متصل»,
   * and that one account is the only fact on the card anybody needed.
   */
  it('summarises the connection rather than showing one badge for ten accounts', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 10 })]
    wizardStates.connections = [{
      connection: { id: 'conn-3', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', client_workspace_id: null },
      state: 'active', discovered: 309, assigned: 10, synced: 9,
      has_parent: true, resumable: false, next_step: null,
      health: { connected: 10, healthy: 9, pending_first_sync: 0, needs_attention: 1, states: { healthy: 9, access_lost: 1 } },
    }]

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const line = await screen.findByTestId('connector-health-snapchat')
    expect(line.textContent).toMatch(/10/)
    expect(line.textContent).toMatch(/9/)
    expect(line.textContent).toMatch(/يحتاج انتباه|need attention/)
  })

  /** And «everything is fine» stays a short sentence — no attention clause when there is none. */
  it('does not mention attention when nothing needs it', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 3 })]
    wizardStates.connections = [{
      connection: { id: 'conn-4', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', client_workspace_id: null },
      state: 'active', discovered: 309, assigned: 3, synced: 3,
      has_parent: true, resumable: false, next_step: null,
      health: { connected: 3, healthy: 3, pending_first_sync: 0, needs_attention: 0, states: { healthy: 3 } },
    }]

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const line = await screen.findByTestId('connector-health-snapchat')
    expect(line.textContent).not.toMatch(/يحتاج انتباه|need attention/)
  })
})

/**
 * COMMAND-CENTER §26 — disconnecting says what it costs before it happens.
 *
 * The endpoint existed on the server and nothing in the interface reached it, so «قطع الاتصال» was
 * a documented capability the customer could not perform. It is here now, and it is deliberately not
 * one press: revoking disables every project binding this connection's accounts feed, in every
 * project, and a customer who has just been told «متصل · 9 تعمل» has no way to know that.
 */
describe('disconnecting a platform', () => {
  beforeEach(() => {
    revoked.calls = []
    wizardStates.connections = []
    wizardStates.resumable = []
  })

  it('asks once, naming the number of accounts that stop syncing, and only then revokes', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 9 })]
    const entry = {
      connection: { id: 'conn-1', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', client_workspace_id: null },
      state: 'active' as const,
      discovered: 309,
      assigned: 9,
      synced: 9,
      has_parent: true,
      resumable: false,
      next_step: null,
      health: { connected: 9, healthy: 9, needs_attention: 0 },
    }
    wizardStates.connections = [entry]
    wizardStates.resumable = [entry]

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    const button = await screen.findByTestId('connector-disconnect-snapchat')

    // First press arms and states the consequence — it does NOT revoke.
    fireEvent.click(button)
    expect(button.textContent).toMatch(/9/)
    expect(button.textContent).toMatch(/تأكيد|Confirm/)
    expect(revoked.calls).toEqual([])

    // Second press is the confirmation.
    fireEvent.click(button)
    await waitFor(() => expect(revoked.calls).toEqual(['conn-1']))
  })

  /** A row with no connection behind it offers no button, rather than one that would fail. */
  it('offers nothing to disconnect when there is no connection to revoke', async () => {
    rows.data = [connector({ key: 'snapchat', state: 'connected', accounts: 0 })]

    renderWithProviders(<IntegrationsPage />, { route: '/app/integrations' })

    await screen.findByTestId('connector-sync-snapchat')
    expect(screen.queryByTestId('connector-disconnect-snapchat')).not.toBeInTheDocument()
  })

  /**
   * INTEGRATION-DATASOURCE-WIZARD-001 §2 — the consent screen is the middle of a journey.
   *
   * Coming back from OAuth produced a green banner, a nudge and a «Resume» button: three pieces of
   * interface telling somebody who had just authorised a provider that there was one more thing to
   * do, without doing it. The wizard opens itself on the connection the callback names.
   */
  it('resumes the wizard on the provider the callback names', async () => {
    rows.data = [connector({ key: 'snapchat', label: 'Snapchat Ads', state: 'connected' })]
    wizardStates.resumable = [{
      state: 'needs_selection', discovered: 309, assigned: 0, synced: 0, has_parent: true, resumable: true, next_step: 'parent',
      connection: { id: 'conn-1', provider: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', client_workspace_id: null },
    }]

    renderWithProviders(<IntegrationsPage />, {
      locale: 'en',
      route: '/app/integrations?provider=snapchat&outcome=connected&accounts=309',
    })

    expect(await screen.findByTestId('connection-wizard')).toBeInTheDocument()
  })

  /** A return that authorised nothing resumable leaves the page alone. */
  it('does not open the wizard when the callback names a provider with nothing to finish', async () => {
    rows.data = [connector({ key: 'meta', label: 'Meta Marketing API', state: 'connected' })]
    wizardStates.resumable = []

    renderWithProviders(<IntegrationsPage />, {
      locale: 'en',
      route: '/app/integrations?provider=meta&outcome=connected&accounts=2',
    })

    expect(await screen.findByTestId('integration-outcome')).toBeInTheDocument()
    expect(screen.queryByTestId('connection-wizard')).not.toBeInTheDocument()
  })

  /**
   * INTEGRATION-DATASOURCE-WIZARD-001 §1 §14 — the card says what the CONNECTION is doing.
   *
   * «متصل» describes the platform: an app is registered and somebody authorised it. Whether the
   * accounts behind that authorisation are syncing, one has lost access, or none has been chosen is
   * a different question — and it is the one somebody opens this page to ask. A green chip over a
   * connection with an account in trouble is why nobody went and looked.
   */
  /**
   * INTEGRATION-DATASOURCE-WIZARD-001 §9 — reconnecting is not how you add an account.
   *
   * Three verbs on a connected card all sound like «bring this up to date», and exactly one of them
   * sends the reader to a provider consent screen — which the reader may not even be able to
   * complete, if they are not the person who authorised it. So the verb states the distinction
   * before it acts on it.
   */
  it('says what reconnecting does before it does it', async () => {
    rows.data = [connector({ key: 'snapchat', label: 'Snapchat', state: 'connected', accounts: 3 })]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    const reconnect = await screen.findByTestId('connector-reconnect-snapchat')
    expect(reconnect).toHaveTextContent(/^Reconnect$/)

    fireEvent.click(reconnect)

    expect(reconnect).toHaveTextContent(/renews the authorisation only/i)
    expect(reconnect).toHaveTextContent(/selected accounts stay/i)
  })

  /**
   * §9 — when the authorisation has actually lapsed, it is the only action, and it goes at once.
   *
   * «Manage accounts» reads the catalogue with the stored token and «Sync now» calls the platform
   * with it. Against a token the platform has stopped accepting, both fail; offering all three gives
   * the reader two buttons that cannot work and nothing saying which is which.
   */
  it('offers reconnecting alone while the authorisation is lapsed', async () => {
    rows.data = [connector({ key: 'linkedin', label: 'LinkedIn', state: 'connected', accounts: 2 })]
    wizardStates.connections = [{
      state: 'active', user_state: 'REAUTH_REQUIRED', discovered: 2, assigned: 2, synced: 2,
      has_parent: false, resumable: false, next_step: 'reconnect',
      health: { connected: 2, healthy: 0, needs_attention: 2, pending_first_sync: 0, states: { access_lost: 2 } },
      connection: { id: 'conn-li', provider: 'linkedin', label: 'LinkedIn', label_ar: 'لينكدإن', client_workspace_id: null },
    }]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-linkedin')).toHaveTextContent('Needs reconnecting')
    expect(screen.queryByTestId('connector-manage-linkedin')).not.toBeInTheDocument()
    expect(screen.queryByTestId('connector-sync-linkedin')).not.toBeInTheDocument()

    // No arming step: there is nothing to disambiguate when only one action can succeed.
    expect(screen.getByTestId('connector-reconnect-linkedin')).toHaveTextContent(/^Reconnect$/)
  })

  it('shows the connection state over the platform state', async () => {
    rows.data = [connector({ key: 'linkedin', label: 'LinkedIn Ads', state: 'connected' })]
    wizardStates.connections = []
    wizardStates.resumable = []
    wizardStates.connections = [{
      state: 'active', user_state: 'ATTENTION_REQUIRED', discovered: 6, assigned: 6, synced: 5,
      has_parent: false, resumable: false, next_step: null,
      health: { connected: 6, healthy: 5, needs_attention: 1, pending_first_sync: 0, states: {} },
      connection: { id: 'conn-li', provider: 'linkedin', label: 'LinkedIn', label_ar: 'لينكدإن', client_workspace_id: null },
    }]

    renderWithProviders(<IntegrationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('connector-state-linkedin')).toHaveTextContent('Needs attention')
  })
})
