import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { StoresPanel } from './StoresPanel'
import type { StoreProvider, StoreRow } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({
  listStoreProviders: vi.fn(),
  startStoreOAuth: vi.fn(),
  syncStore: vi.fn(),
  assignStore: vi.fn(),
}))

import { assignStore, listStoreProviders, startStoreOAuth, syncStore } from './api'

function store(over: Partial<StoreRow> = {}): StoreRow {
  return {
    id: 's1', external_id: 'store-9', name: 'متجر تجريبي', project_id: 'p1', project_name: 'Project 1', domain: 'demo.salla.sa', currency: 'SAR',
    last_synced_at: null, counts: { products: 12, orders: 3401, abandoned_carts: 7 }, last_run: null,
    ...over,
  }
}

function provider(over: Partial<StoreProvider> = {}): StoreProvider {
  return {
    key: 'salla', label: 'Salla', state: 'disconnected', connection_error: null, token_expires_at: null, stores: [], supports_abandoned_carts: true,
    ...over,
  }
}

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 — the panel is the project's own stores and nothing else. The older
 * connect step — a «belongs to client» dropdown, a connect button, a project dropdown per store — is gone:
 * a store is linked from the project's journey.
 */
describe('StoresPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
  })
  afterEach(() => signOut())

  it('asks for the one project it is given and never for the whole workspace', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', stores: [store()] })])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })

    expect(await screen.findByTestId('store-s1')).toHaveTextContent('متجر تجريبي')
    expect(listStoreProviders).toHaveBeenCalledTimes(1)
    expect(listStoreProviders).toHaveBeenCalledWith('p1')
  })

  it('draws nothing at all when the project reads no store: the platform row carries «ربط»', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider(), provider({ key: 'zid', label: 'Zid', state: 'awaiting_credentials' })])

    const { container } = renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })

    await waitFor(() => expect(listStoreProviders).toHaveBeenCalled())
    await waitFor(() => expect(container.querySelector('.animate-pulse')).toBeNull())
    expect(screen.queryByTestId('stores-panel')).not.toBeInTheDocument()
    expect(container.innerHTML).not.toContain('client_secret')
  })

  it('has no connect step: no client dropdown, no project dropdown, no connect button', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', stores: [store()] })])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })
    await screen.findByTestId('store-s1')

    expect(screen.queryByTestId('store-client-workspace')).not.toBeInTheDocument()
    expect(screen.queryByTestId('store-project-s1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('connect-store-salla')).not.toBeInTheDocument()
    expect(document.querySelectorAll('select')).toHaveLength(0)
    expect(screen.queryByText('المتجر يخصّ العميل')).not.toBeInTheDocument()
    expect(startStoreOAuth).not.toHaveBeenCalled()
    expect(assignStore).not.toHaveBeenCalled()
  })

  it('shows the store’s figures in Latin digits and its own currency as a chip', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', stores: [store()] })])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })

    const card = await screen.findByTestId('store-s1')
    expect(card).toHaveTextContent('3,401')
    expect(card.textContent ?? '').not.toMatch(/[٠-٩]/)
    expect(screen.getByTestId('store-currency-s1')).toHaveTextContent('SAR')
  })

  it('states an unknown currency as unknown and never assumes one', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', stores: [store({ currency: null })] })])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })

    const chip = await screen.findByTestId('store-currency-s1')
    expect(chip).toHaveTextContent('?')
    expect(chip).not.toHaveTextContent(/SAR|ر\.س/)
    expect(chip).toHaveAttribute('title', 'العملة غير معروفة: المبالغ محجوبة')
  })

  it('says abandoned carts are not offered by Zid rather than showing a zero', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({ key: 'zid', label: 'Zid', state: 'connected', supports_abandoned_carts: false, stores: [store({ id: 'z1', counts: { products: 1, orders: 2, abandoned_carts: 0 } })] }),
    ])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })

    expect(await screen.findByTestId('carts-unsupported')).toBeInTheDocument()
  })

  it('queues a read of one store', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([provider({ state: 'connected', stores: [store()] })])
    vi.mocked(syncStore).mockResolvedValue({ queued: 1, window_start: '2026-10-01' })

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'ar' })
    fireEvent.click(await screen.findByTestId('store-sync-s1'))

    await waitFor(() => expect(vi.mocked(syncStore).mock.calls[0]?.[0]).toBe('s1'))
  })

  it('shows why the last read failed, in the store’s own card', async () => {
    vi.mocked(listStoreProviders).mockResolvedValue([
      provider({ state: 'connected', stores: [store({ last_run: { status: 'failed', records: 0, error: 'The store refused the request.', finished_at: null } })] }),
    ])

    renderWithProviders(<StoresPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('store-failed')).toHaveTextContent('The store refused the request.')
  })
})
