import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { NewClientDialog } from './NewClientDialog'
import { AgencyScopeSwitcher } from '@/features/agency/AgencyScopeSwitcher'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/features/projects/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listClientWorkspaces: vi.fn(), listProjects: vi.fn(), listUsers: vi.fn() }
})
vi.mock('@/lib/api/client', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, ensureCsrfCookie: vi.fn(async () => undefined), postData: vi.fn() }
})

import { listClientWorkspaces, listProjects, listUsers } from '@/features/projects/api'
import { postData } from '@/lib/api/client'

/** CLIENT-UNIT-001 — the client is the visible unit; its project is hidden until it has a second one. */
describe('«عميل جديد» is one form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listUsers).mockResolvedValue([{ id: 7, name: 'سارة', email: 's@x.test' }] as never)
    vi.mocked(postData).mockResolvedValue({ id: 'c1', slug: 'etifaq', project_id: 'p1', portal_path: '/portal/etifaq' } as never)
  })

  it('sends the name, the currency and the manager in ONE request that also makes the internal project', async () => {
    const onClose = vi.fn()
    renderWithProviders(<NewClientDialog open onClose={onClose} />, { route: '/agency/clients', locale: 'ar' })

    fireEvent.change(screen.getByTestId('new-client-name'), { target: { value: 'نادي الاتفاق' } })
    fireEvent.change(screen.getByTestId('new-client-currency'), { target: { value: 'USD' } })
    await screen.findByRole('option', { name: 'سارة' })
    fireEvent.change(screen.getByTestId('new-client-manager'), { target: { value: '7' } })
    fireEvent.click(screen.getByTestId('new-client-submit'))

    await waitFor(() => expect(postData).toHaveBeenCalledWith('/client-workspaces', {
      name: 'نادي الاتفاق', mode: 'managed', default_currency: 'USD', account_manager_id: 7, create_project: true,
    }))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
  })

  it('refuses an empty name and says why a refusal happened', async () => {
    vi.mocked(postData).mockRejectedValue(new (await import('@/lib/api/errors')).Refusal('الاسم مستخدم من قبل.'))
    renderWithProviders(<NewClientDialog open onClose={() => undefined} />, { route: '/agency/clients', locale: 'ar' })

    expect(screen.getByTestId('new-client-submit')).toBeDisabled()
    fireEvent.change(screen.getByTestId('new-client-name'), { target: { value: 'س' } })
    fireEvent.click(screen.getByTestId('new-client-submit'))
    expect(await screen.findByTestId('new-client-error')).toHaveTextContent('الاسم مستخدم من قبل.')
  })
})

describe('the scope control shows the project only for a client with two', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'c1', name: 'Solo' }, { id: 'c2', name: 'Pair' }] as never)
    vi.mocked(listProjects).mockResolvedValue([
      { id: 'p1', client_workspace_id: 'c1', name: 'Solo' },
      { id: 'p2', client_workspace_id: 'c2', name: 'Pair A' },
      { id: 'p3', client_workspace_id: 'c2', name: 'Pair B' },
    ] as never)
    useProject.setState({ currentProjectId: null })
    useAgencyClient.setState({ currentClientId: null })
  })

  it('hides the project and works inside it for a one-project client', async () => {
    useAgencyClient.setState({ currentClientId: 'c1' })
    renderWithProviders(<AgencyScopeSwitcher />, { route: '/agency/dashboard', locale: 'ar' })

    await waitFor(() => expect(useProject.getState().currentProjectId).toBe('p1'))
    expect(screen.queryByTestId('agency-scope-project')).not.toBeInTheDocument()
  })

  it('brings the «المشاريع» switch back for a client with a second project', async () => {
    useAgencyClient.setState({ currentClientId: 'c2' })
    renderWithProviders(<AgencyScopeSwitcher />, { route: '/agency/dashboard', locale: 'ar' })

    expect(await screen.findByTestId('agency-scope-project')).toBeInTheDocument()
    expect(useProject.getState().currentProjectId).toBeNull()
  })
})
