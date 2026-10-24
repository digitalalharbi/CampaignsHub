import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { StoresPanel } from './StoresPanel'
import type { StoreProvider } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listStoreProviders: vi.fn(),
  startStoreOAuth: vi.fn(),
  syncStore: vi.fn(),
  assignStore: vi.fn(),
  disconnectStore: vi.fn(),
  storeRuns: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({ listClientWorkspaces: vi.fn(), listProjects: vi.fn() }))

import { assignStore, disconnectStore, listStoreProviders, startStoreOAuth, storeRuns, syncStore } from './api'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'

function provider(over: Partial<StoreProvider> = {}): StoreProvider {
  return {
    key: 'salla',
    label: 'Salla',
    state: 'disconnected',
    connection_error: null,
    token_expires_at: null,
    stores: [],
    supports_abandoned_carts: true,
    ...over,
  }
}

describe('StoresPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
    vi.mocked(listClientWorkspaces).mockResolvedValue([])
    vi.mocked(listProjects).mockResolvedValue([{ id: 'p1', name: 'Project 1' }, { id: 'p2', name: 'Project 2' }] as never)
  })
  afterEach(() => signOut())

  /**
   * The layer separation, on the surface a merchant actually sees: a provider the operator has not
   * finished setting up offers NO button and names NO key.
   */
  it('offers no connect button and names no system key when the platform is awaiting credentials', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({ state: 'awaiting_credentials' }),
      provider({ key: 'zid', label: 'Zid', state: 'awaiting_credentials', supports_abandoned_carts: false }),
    ])

    const { container } = renderWithProviders(<StoresPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('store-needs-operator-salla')).toBeInTheDocument()
    expect(screen.queryByTestId('connect-store-salla')).not.toBeInTheDocument()
    expect(container.innerHTML).not.toContain('client_secret')
    expect(container.innerHTML).not.toContain('webhook_secret')
  })

  it('sends the merchant to the platform’s own consent screen', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider()])
    vi.mocked(startStoreOAuth).mockResolvedValue({ authorization_url: 'https://accounts.salla.sa/oauth2/auth?x=1' })

    renderWithProviders(<StoresPanel />, { locale: 'ar' })

    fireEvent.click(await screen.findByTestId('connect-store-salla'))

    // React Query v5 hands the mutation fn a context object as a second argument; only the first
    // is ours, so the assertion names it rather than pinning the library's internals.
    await waitFor(() => expect(vi.mocked(startStoreOAuth).mock.calls[0]?.slice(0, 2)).toEqual(['salla', null]))
  })

  /**
   * Zid publishes no abandoned carts, and the card must say so rather than show a zero.
   *
   * «0 سلة متروكة» reads as a perfect checkout — a claim, where the truth is that the platform does
   * not report them at all.
   */
  it('says a platform does not offer abandoned carts instead of showing zero', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({
        key: 'zid',
        label: 'Zid',
        state: 'connected',
        supports_abandoned_carts: false,
        stores: [{
          id: 's1', external_id: 'z-1', name: 'متجر زد', project_id: 'p1', project_name: 'Project 1', domain: null, currency: 'SAR',
          last_synced_at: '2026-08-05T09:00:00+00:00',
          counts: { products: 12, orders: 4, abandoned_carts: 0 },
          last_run: null,
        }],
      }),
    ])

    renderWithProviders(<StoresPanel />, { locale: 'ar' })

    const marker = await screen.findByTestId('carts-unsupported')
    expect(marker.textContent).toMatch(/لا توفّرها المنصة/)
    expect(screen.getByText('12')).toBeInTheDocument()
  })

  it('queues a sync and says a request was sent, never that a sync completed', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({
        state: 'connected',
        stores: [{
          id: 's1', external_id: 'sa-1', name: 'متجر', project_id: 'p1', project_name: 'Project 1', domain: 'demo.salla.sa', currency: 'SAR',
          last_synced_at: null,
          counts: { products: 0, orders: 0, abandoned_carts: 0 },
          last_run: null,
        }],
      }),
    ])
    vi.mocked(syncStore).mockResolvedValue({ queued: 1, window_start: '2026-07-22' })

    renderWithProviders(<StoresPanel />, { locale: 'ar' })

    fireEvent.click(await screen.findByRole('button', { name: /زامن الآن/ }))

    await waitFor(() => expect(vi.mocked(syncStore).mock.calls[0]?.[0]).toBe('s1'))

    const notice = await screen.findByTestId('store-sync-queued')
    expect(notice.textContent).toMatch(/أُرسل طلب المزامنة/)
    expect(notice.textContent).not.toMatch(/تمت المزامنة/)
  })

  /** A discovered-but-never-synced store says so, rather than showing a date it was never asked. */
  it('distinguishes a store that has been discovered from one that has been synced', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({
        state: 'connected',
        stores: [{
          id: 's1', external_id: 'sa-1', name: 'متجر', project_id: 'p1', project_name: 'Project 1', domain: null, currency: 'SAR',
          last_synced_at: null,
          counts: { products: 0, orders: 0, abandoned_carts: 0 },
          last_run: null,
        }],
      }),
    ])

    renderWithProviders(<StoresPanel />, { locale: 'ar' })

    expect(await screen.findByText(/لم تصل بيانات بعد/)).toBeInTheDocument()
  })

  /** The `→ Client` link of the chain is offered only when the workspace actually has clients. */
  it('asks which client a store belongs to only when the workspace has clients', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider()])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme' } as never])

    renderWithProviders(<StoresPanel />, { locale: 'ar' })

    expect(await screen.findByTestId('store-client-workspace')).toBeInTheDocument()
  })

  /**
   * COMMERCE-ASSIGN-001 — a store with no project asks for one in words and cannot be synced into
   * nothing; choosing a project calls the assignment with that project.
   */
  it('asks an unassigned store for a project, and assigns the one chosen', async () => {
    vi.mocked(assignStore).mockResolvedValue({} as never)
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({
        state: 'connected',
        stores: [{
          id: 's1', external_id: '793298857', name: 'dev-store', project_id: null, project_name: null, domain: null, currency: 'SAR',
          last_synced_at: null, counts: { products: 0, orders: 0, abandoned_carts: 0 }, last_run: null,
        }],
      }),
    ])

    renderWithProviders(<StoresPanel />, { locale: 'en' })

    expect(await screen.findByTestId('store-needs-project-s1')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Sync now/ })).toBeDisabled()

    const select = screen.getByTestId('store-project-s1') as HTMLSelectElement
    expect(select.value).toBe('')
    expect(Array.from(select.options).map((o) => o.textContent)).toEqual(['Choose a project', 'Project 1', 'Project 2'])

    fireEvent.change(select, { target: { value: 'p1' } })
    await waitFor(() => expect(assignStore).toHaveBeenCalledWith('s1', 'p1'))
  })

  it('shows progress while the first read is queued, and lets the project be detached', async () => {
    vi.mocked(assignStore).mockResolvedValue({} as never)
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({
        state: 'connected',
        stores: [{
          id: 's1', external_id: '1', name: 'متجر', project_id: 'p1', project_name: 'Project 1', domain: null, currency: 'SAR',
          last_synced_at: null, counts: { products: 0, orders: 0, abandoned_carts: 0 },
          last_run: { status: 'queued', records: 0, error: null, finished_at: null },
        }],
      }),
    ])

    renderWithProviders(<StoresPanel />, { locale: 'en' })

    expect(await screen.findByTestId('store-reading-s1')).toBeInTheDocument()
    expect((screen.getByTestId('store-project-s1') as HTMLSelectElement).value).toBe('p1')

    fireEvent.change(screen.getByTestId('store-project-s1'), { target: { value: '' } })
    await waitFor(() => expect(assignStore).toHaveBeenCalledWith('s1', null))
  })

  /** STORE-LIFECYCLE-001 — a connected store can be reauthorised, changed, disconnected (after a confirmation) and read back as history. */
  const row = (over = {}) => ({
    id: 's1', external_id: '1', name: 'متجر', project_id: 'p1', project_name: 'Project 1', domain: null, currency: 'SAR',
    last_synced_at: null, counts: { products: 3, orders: 9, abandoned_carts: 1 }, last_run: null, ...over,
  })

  it('offers reauthorise and disconnect on a connected store, and disconnects only after an explicit yes', async () => {
    vi.mocked(disconnectStore).mockResolvedValue({ disabled_bindings: 1 })
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', connection_id: 'c1', connection_status: 'connected', stores: [row()] })])
    renderWithProviders(<StoresPanel />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('disconnect-store-salla'))
    expect(screen.getByTestId('disconnect-confirm-salla').textContent).toMatch(/kept/)
    expect(disconnectStore).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('disconnect-yes-salla'))
    await waitFor(() => expect(disconnectStore).toHaveBeenCalledWith('c1'))
  })

  it('cancelling the confirmation changes nothing', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', connection_id: 'c1', connection_status: 'connected', stores: [row()] })])
    renderWithProviders(<StoresPanel />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('disconnect-store-salla'))
    fireEvent.click(screen.getByText('Cancel'))
    expect(screen.queryByTestId('disconnect-confirm-salla')).toBeNull()
    expect(disconnectStore).not.toHaveBeenCalled()
  })

  it('starts a fresh consent from «reauthorise / change store»', async () => {
    vi.mocked(startStoreOAuth).mockResolvedValue({ authorization_url: 'about:blank#salla' })
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', connection_id: 'c1', connection_status: 'connected', stores: [row()] })])
    renderWithProviders(<StoresPanel />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('reconnect-store-salla'))
    await waitFor(() => expect(startStoreOAuth).toHaveBeenCalledWith('salla', null))
  })

  it('keeps a disconnected store and its counts visible, says the data is kept, and offers reconnect — not disconnect', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'disconnected', connection_id: 'c1', connection_status: 'revoked', stores: [row({ disconnected: true })] })])
    renderWithProviders(<StoresPanel />, { locale: 'en' })

    expect(await screen.findByTestId('store-disconnected-s1')).toBeInTheDocument()
    expect(screen.getByTestId('store-s1').textContent).toContain('9')
    expect(screen.getByTestId('reconnect-store-salla').textContent).toMatch(/Reconnect/)
    expect(screen.queryByTestId('disconnect-store-salla')).toBeNull()
  })

  it('shows the store’s sync history on request', async () => {
    vi.mocked(storeRuns).mockResolvedValue([{ id: 'r1', status: 'failed', records: 0, error: 'token expired', started_at: '2026-10-24T08:00:00Z', finished_at: '2026-10-24T08:01:00Z' }])
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', connection_id: 'c1', connection_status: 'connected', stores: [row()] })])
    renderWithProviders(<StoresPanel />, { locale: 'en' })

    expect(storeRuns).not.toHaveBeenCalled()
    fireEvent.click(await screen.findByTestId('store-history-toggle-s1'))
    await waitFor(() => expect(screen.getByTestId('store-history-s1').textContent).toContain('token expired'))
    expect(storeRuns).toHaveBeenCalledWith('s1')
  })
})
