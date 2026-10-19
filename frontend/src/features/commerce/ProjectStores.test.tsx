import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectStores } from './ProjectStores'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({ listStoreProviders: vi.fn(), assignStore: vi.fn() }))
import { assignStore, listStoreProviders } from './api'

const store = (id: string, name: string, project_id: string | null) => ({
  id, external_id: id, name, project_id, project_name: null, domain: null, currency: 'SAR',
  last_synced_at: null, counts: { products: 3, orders: 2, abandoned_carts: 0 }, last_run: null,
})

/** COMMERCE-ASSIGN-001 — the project page names its stores, and offers the unassigned ones. */
describe('ProjectStores', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view', 'integrations.connect'])
    vi.mocked(listStoreProviders).mockResolvedValue([
      { key: 'salla', label: 'Salla', state: 'connected', connection_error: null, token_expires_at: null, supports_abandoned_carts: true,
        stores: [store('mine', 'My store', 'p1'), store('theirs', 'Other project store', 'p2'), store('free', 'Unassigned store', null)] },
    ])
  })
  afterEach(() => signOut())

  it('lists only this project’s stores, and the unassigned ones apart', async () => {
    renderWithProviders(<ProjectStores projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('project-store-mine')).toHaveTextContent('My store')
    expect(screen.queryByText('Other project store')).not.toBeInTheDocument()
    expect(screen.getByTestId('project-stores-free')).toHaveTextContent('Unassigned store')
  })

  it('assigns an unassigned store to this project with one press', async () => {
    vi.mocked(assignStore).mockResolvedValue({} as never)
    renderWithProviders(<ProjectStores projectId="p1" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('project-store-use-free'))

    await waitFor(() => expect(assignStore).toHaveBeenCalledWith('free', 'p1'))
  })
})
