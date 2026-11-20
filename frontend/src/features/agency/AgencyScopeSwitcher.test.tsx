import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AgencyScopeSwitcher } from './AgencyScopeSwitcher'
import { renderWithProviders } from '@/test/utils'
import { useAgencyClient } from '@/stores/agencyClient'

vi.mock('@/features/projects/api', () => ({
  listClientWorkspaces: vi.fn(),
  listProjects: vi.fn(),
}))

import { listClientWorkspaces, listProjects } from '@/features/projects/api'

/**
 * The scope control is the entry point to every project-scoped page in the agency portal — nothing
 * downstream renders until a client is chosen. Opening the live portal showed it rendering 269
 * `<option>` elements in a single scroll with no way to narrow them, which makes the whole portal
 * unreachable for any agency with a real roster.
 */
const clients = (n: number) =>
  Array.from({ length: n }, (_, i) => ({
    id: `c${i}`,
    name: i === 0 ? 'Acme (Managed)' : `Filler Client ${i}`,
  }))

function mount(count: number) {
  vi.mocked(listClientWorkspaces).mockResolvedValue(clients(count) as never)
  vi.mocked(listProjects).mockResolvedValue([] as never)
  return renderWithProviders(<AgencyScopeSwitcher />, { route: '/agency/dashboard', locale: 'en' })
}

const trigger = () => screen.getByTestId('agency-scope-client')
const options = () => screen.queryAllByTestId(/^agency-scope-client-client-/)

describe('AgencyScopeSwitcher — the client picker (CLIENT-SWITCHER-X-001)', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); useAgencyClient.setState({ currentClientId: null }) })

  it('opens a searchable list with one name per client and its initial', async () => {
    mount(5)
    await waitFor(() => expect(trigger()).toBeInTheDocument())
    fireEvent.click(trigger())

    expect(screen.getByTestId('agency-scope-client-search')).toBeInTheDocument()
    expect(options()).toHaveLength(5)
    expect(options()[0]).toHaveTextContent('AAcme (Managed)')
  })

  it('filters down to what was typed and says when nothing matches', async () => {
    mount(40)
    await waitFor(() => expect(trigger()).toBeInTheDocument())
    fireEvent.click(trigger())
    fireEvent.change(screen.getByTestId('agency-scope-client-search'), { target: { value: 'acme' } })
    expect(options().map((o) => o.textContent)).toEqual(['AAcme (Managed)'])

    fireEvent.change(screen.getByTestId('agency-scope-client-search'), { target: { value: 'zzzz' } })
    expect(screen.getByTestId('agency-scope-client-empty')).toBeInTheDocument()
  })

  it('picks a client, shows it on the field and lists it under «Recent» next time', async () => {
    mount(5)
    await waitFor(() => expect(trigger()).toBeInTheDocument())
    fireEvent.click(trigger())
    fireEvent.click(screen.getByTestId('agency-scope-client-client-c0'))

    expect(useAgencyClient.getState().currentClientId).toBe('c0')
    expect(screen.getByTestId('agency-scope-client-current')).toHaveTextContent('Acme (Managed)')
    fireEvent.click(trigger())
    expect(screen.getByTestId('agency-scope-client-recent-c0')).toBeInTheDocument()
  })

  it('draws the client logo when the client has one', async () => {
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'c0', name: 'Acme', logo_url: '/api/v1/app/clients/c0/logo/file' }, { id: 'c1', name: 'Beta', logo_url: null }] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
    renderWithProviders(<AgencyScopeSwitcher />, { route: '/agency/dashboard', locale: 'en' })
    await waitFor(() => expect(trigger()).toBeInTheDocument())
    fireEvent.click(trigger())
    expect(screen.getByTestId('agency-scope-client-client-c0').querySelector('img')?.getAttribute('src')).toBe('/api/v1/app/clients/c0/logo/file')
    expect(screen.getByTestId('agency-scope-client-client-c1').querySelector('img')).toBeNull()
  })
})
