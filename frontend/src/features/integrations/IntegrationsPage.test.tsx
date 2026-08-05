import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
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
