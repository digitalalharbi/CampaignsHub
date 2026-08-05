import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { StoresPanel } from './StoresPanel'
import type { StoreProvider } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listStoreProviders: vi.fn(),
  startStoreOAuth: vi.fn(),
  syncStore: vi.fn(),
}))
vi.mock('@/features/projects/api', () => ({ listClientWorkspaces: vi.fn() }))

import { listStoreProviders, startStoreOAuth, syncStore } from './api'
import { listClientWorkspaces } from '@/features/projects/api'

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
          id: 's1', external_id: 'z-1', name: 'متجر زد', domain: null, currency: 'SAR',
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
          id: 's1', external_id: 'sa-1', name: 'متجر', domain: 'demo.salla.sa', currency: 'SAR',
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
          id: 's1', external_id: 'sa-1', name: 'متجر', domain: null, currency: 'SAR',
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
})
