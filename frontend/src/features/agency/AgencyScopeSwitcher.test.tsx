import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AgencyScopeSwitcher } from './AgencyScopeSwitcher'
import { renderWithProviders } from '@/test/utils'

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

const clientSelect = () => screen.getByTestId('agency-scope-client') as HTMLSelectElement

describe('AgencyScopeSwitcher — long client lists', () => {
  beforeEach(() => vi.clearAllMocks())

  /** A short roster is scannable; a filter box there is clutter, not help. */
  it('shows no filter for a short list', async () => {
    mount(5)
    await waitFor(() => expect(clientSelect()).toBeInTheDocument())

    expect(screen.queryByTestId('agency-scope-client-filter')).not.toBeInTheDocument()
    // Five clients plus the placeholder.
    expect(clientSelect().options).toHaveLength(6)
  })

  it('filters a long list down to what was typed', async () => {
    mount(40)
    await waitFor(() => expect(clientSelect()).toBeInTheDocument())

    expect(clientSelect().options).toHaveLength(41)

    fireEvent.change(screen.getByTestId('agency-scope-client-filter'), { target: { value: 'acme' } })

    expect([...clientSelect().options].map((o) => o.textContent)).toEqual(['Select a client', 'Acme (Managed)'])
  })

  /**
   * A filter that hides everything must say so. Left silent, an empty select is indistinguishable
   * from «you have no clients» — which is a permissions message, and would send somebody to ask for
   * access they already hold.
   */
  it('says when the filter matches nothing', async () => {
    mount(40)
    await waitFor(() => expect(clientSelect()).toBeInTheDocument())

    fireEvent.change(screen.getByTestId('agency-scope-client-filter'), { target: { value: 'zzzz' } })

    expect(screen.getByTestId('agency-scope-client-filter-empty')).toBeInTheDocument()
    expect(clientSelect().options).toHaveLength(1)
  })

  /**
   * The chosen client survives a filter that excludes it. Dropping it would make the select fall
   * back to the placeholder — reading as "nothing selected" while every page below is still showing
   * that client's data.
   */
  it('keeps the selected client in the list even when the filter excludes it', async () => {
    mount(40)
    await waitFor(() => expect(clientSelect()).toBeInTheDocument())

    fireEvent.change(clientSelect(), { target: { value: 'c0' } })
    fireEvent.change(screen.getByTestId('agency-scope-client-filter'), { target: { value: 'Filler Client 7' } })

    const values = [...clientSelect().options].map((o) => o.value)
    expect(values).toContain('c0')
    expect(clientSelect().value).toBe('c0')
  })
})
