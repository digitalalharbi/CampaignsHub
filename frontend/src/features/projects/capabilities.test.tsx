import { describe, expect, it, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useProjectCapabilities } from './capabilities'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * TEAM-PROJECT-RBAC-001 — the rail asks what this person may do, and fails OPEN when it does not know.
 *
 * The direction matters more than the answer. This decides what to DRAW; the server decides what is
 * allowed, and it fails closed. So the worst case of a wrong «yes» here is the 403 that happens today,
 * while a wrong «no» empties somebody's rail and looks like an outage — which is why every unknown
 * resolves to «show it».
 */
const wrap = ({ children }: { children: ReactNode }) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('the capabilities a rail draws itself from', () => {
  it('says no only to a capability it KNOWS is absent', async () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(getData).mockResolvedValue({ capabilities: ['dashboard.view', 'reports.view'] } as never)

    const { result } = renderHook(() => useProjectCapabilities(), { wrapper: wrap })

    await waitFor(() => expect(result.current.capabilities).toBeDefined())

    expect(result.current.can('reports.view')).toBe(true)
    expect(result.current.can('team.manage')).toBe(false)
  })

  /** A leaf that names no capability is not a leaf anybody has to hold anything for. */
  it('offers a leaf that asks for nothing', async () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(getData).mockResolvedValue({ capabilities: [] } as never)

    const { result } = renderHook(() => useProjectCapabilities(), { wrapper: wrap })

    await waitFor(() => expect(result.current.capabilities).toBeDefined())
    expect(result.current.can(undefined)).toBe(true)
  })

  /** Before the answer arrives, everything is offered — a rail that empties on a slow network is an outage. */
  it('offers everything while it does not yet know', () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(getData).mockImplementation(() => new Promise(() => {}) as never)

    const { result } = renderHook(() => useProjectCapabilities(), { wrapper: wrap })

    expect(result.current.capabilities).toBeUndefined()
    expect(result.current.can('team.manage')).toBe(true)
  })

  /** …and if the request fails outright, the same: the server is still the one refusing. */
  it('offers everything when the request fails', async () => {
    useProject.getState().setCurrentProjectId('p1')
    vi.mocked(getData).mockRejectedValue(new Error('offline'))

    const { result } = renderHook(() => useProjectCapabilities(), { wrapper: wrap })

    await waitFor(() => expect(result.current.capabilities).toBeUndefined())
    expect(result.current.can('team.manage')).toBe(true)
  })

  /** With no project chosen there is nothing to ask about, and nothing is hidden. */
  it('asks nothing when no project is chosen', () => {
    useProject.getState().setCurrentProjectId(null)
    vi.mocked(getData).mockClear()

    const { result } = renderHook(() => useProjectCapabilities(), { wrapper: wrap })

    expect(vi.mocked(getData)).not.toHaveBeenCalled()
    expect(result.current.can('team.manage')).toBe(true)
  })
})
