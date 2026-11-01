import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { WorkspaceSwitcherPage } from './WorkspaceSwitcherPage'

const base = { role: 'owner', is_default: false, is_active: false, client_scope_ids: [], project_scope_ids: [] }
const memberships = [
  { ...base, id: 'm-1', portal: 'app', portal_path: '/dashboard', landing_path: '/dashboard', tenant: { id: 't-1', name: 'A', slug: 'a' } },
  { ...base, id: 'm-2', portal: 'agency', portal_path: '/agency', landing_path: '/agency', tenant: { id: 't-2', name: 'B', slug: 'b' } },
]

vi.mock('./memberships', async (orig) => ({
  ...(await orig<typeof import('./memberships')>()),
  fetchMemberships: vi.fn(async () => ({ memberships })),
  switchMembership: vi.fn(async () => ({ current: memberships[1], destination: '/agency' })),
}))

describe('WorkspaceSwitcherPage — switching workspace', () => {
  const assign = vi.fn()
  const original = window.location

  beforeEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
    window.localStorage.setItem('campaign-hub-project-storage', '{"state":{"currentProjectId":"p-old"}}')
    window.localStorage.setItem('campaign-hub-agency-client', '{"state":{"clientId":"c-old"}}')
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: original })
    window.localStorage.clear()
    assign.mockReset()
  })

  it('drops the previous workspace\'s cache and persisted selection before landing in the new one', async () => {
    const client = new QueryClient()
    client.setQueryData(['projects', 'list'], [{ id: 'p-old' }])

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkspaceSwitcherPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    fireEvent.click(await screen.findByTestId('switch-to-agency'))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/agency'))
    expect(client.getQueryData(['projects', 'list'])).toBeUndefined()
    expect(window.localStorage.getItem('campaign-hub-project-storage')).toBeNull()
    expect(window.localStorage.getItem('campaign-hub-agency-client')).toBeNull()
  })
})
