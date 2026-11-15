import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { projectRowActions } from '@/lib/projectLifecycle'
import { ProjectsTab } from './ProjectsTab'

/**
 * PROJECT-LIFECYCLE-001 §8, §10 UI (builder N2) — the workspace-settings projects table: 100% (not 10000%), Arabic
 * statuses as badges (never «active»), filters active / archived / trash, the buttons by state AND permission, and the
 * delete window (impact, client choice, typed name).
 */
vi.mock('@/features/projects/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listProjects: vi.fn(), listClientWorkspaces: vi.fn(), createProject: vi.fn() }
})
vi.mock('@/lib/api/client', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getData: vi.fn(), deleteData: vi.fn(), postData: vi.fn(), ensureCsrfCookie: vi.fn() }
})

import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { deleteData, getData } from '@/lib/api/client'

const OWNER = ['projects.view', 'projects.archive', 'projects.restore', 'projects.delete', 'projects.purge', 'projects.move']
const ACTIVE = { id: 'p1', name: 'Spring', status: 'active', lifecycle_status: 'active', client_workspace_id: 'w1', setup_completion: 100 }
const ARCHIVED = { id: 'p2', name: 'Old one', status: 'archived', lifecycle_status: 'archived', client_workspace_id: 'w1', setup_completion: 67 }
const DELETED = { id: 'p3', name: 'Binned', status: 'deleted', lifecycle_status: 'deleted', client_workspace_id: 'w2', setup_completion: 0, purge_after: '2026-12-15T00:00:00Z' }

function serve(extra: Record<string, unknown> = {}) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url === '/projects?status=archived') return [ARCHIVED]
    if (url === '/projects?status=deleted') return [DELETED]
    if (url in extra) return extra[url]
    throw new Error(`unexpected ${url}`)
  })
}

describe('projectRowActions — the buttons by state and permission', () => {
  const all = { archive: true, restore: true, delete: true, purge: true, move: true }
  it('active «فتح / أرشفة / حذف» (+ move), archived «فتح / استعادة / حذف», deleted «استعادة / حذف نهائي»', () => {
    expect(projectRowActions('active', all)).toEqual(['open', 'archive', 'delete', 'move'])
    expect(projectRowActions('paused', all)).toEqual(['open', 'archive', 'delete', 'move'])
    expect(projectRowActions('archived', all)).toEqual(['open', 'restore', 'delete'])
    expect(projectRowActions('deleted', all)).toEqual(['restore', 'purge'])
  })
  it('a member without the permissions only gets «فتح»', () => {
    const none = { archive: false, restore: false, delete: false, purge: false, move: false }
    expect(projectRowActions('active', none)).toEqual(['open'])
    expect(projectRowActions('archived', none)).toEqual(['open'])
    expect(projectRowActions('deleted', none)).toEqual([])
  })
})

describe('ProjectsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([ACTIVE as never])
    vi.mocked(listClientWorkspaces).mockResolvedValue([{ id: 'w1', name: 'Acme' }, { id: 'w2', name: 'Beta' }] as never)
    serve()
  })
  afterEach(() => signOut())

  it('shows 100% (not 10000%), the client, and the Arabic status badge — never «active»', async () => {
    signInWith(OWNER)
    renderWithProviders(<ProjectsTab />, { locale: 'ar' })
    expect(await screen.findByText('Spring')).toBeInTheDocument()
    expect(screen.getByTestId('settings-project-setup-p1')).toHaveTextContent('100%')
    expect(screen.queryByText('10000%')).not.toBeInTheDocument()
    expect(screen.getByTestId('settings-project-status-p1')).toHaveTextContent('نشط')
    expect(screen.queryByText('active')).not.toBeInTheDocument()
    expect(screen.getAllByText('Acme').length).toBeGreaterThan(0)
    const row = screen.getByTestId('settings-project-actions-p1')
    expect(within(row).getByText('فتح')).toBeInTheDocument()
    expect(within(row).getByText('أرشفة')).toBeInTheDocument()
    expect(within(row).getByText('حذف')).toBeInTheDocument()
    expect(within(row).getByText('نقل لعميل آخر')).toBeInTheDocument()
  })

  it('filters archived and trash, each with its own buttons', async () => {
    signInWith(OWNER)
    renderWithProviders(<ProjectsTab />, { locale: 'ar' })
    await screen.findByText('Spring')

    fireEvent.click(screen.getByTestId('settings-projects-filter-archived'))
    expect(await screen.findByText('Old one')).toBeInTheDocument()
    expect(screen.getByTestId('settings-project-status-p2')).toHaveTextContent('مؤرشف')
    const archivedRow = screen.getByTestId('settings-project-actions-p2')
    expect(within(archivedRow).getAllByRole('link', { name: /فتح/ })[0]).toHaveAttribute('href', '/app/projects/p2')
    expect(within(archivedRow).getByText('استعادة')).toBeInTheDocument()
    expect(within(archivedRow).getByText('حذف')).toBeInTheDocument()
    expect(within(archivedRow).queryByText('أرشفة')).not.toBeInTheDocument()

    fireEvent.click(screen.getByTestId('settings-projects-filter-trash'))
    expect(await screen.findByText('Binned')).toBeInTheDocument()
    expect(screen.getByTestId('settings-project-status-p3')).toHaveTextContent('محذوف')
    const trashRow = screen.getByTestId('settings-project-actions-p3')
    expect(within(trashRow).getByText('استعادة')).toBeInTheDocument()
    expect(within(trashRow).getByText('حذف نهائي')).toBeInTheDocument()
    expect(within(trashRow).queryByText('فتح')).not.toBeInTheDocument()
  })

  it('a member without lifecycle permissions sees no destructive button and no trash filter', async () => {
    signInWith(['projects.view'])
    renderWithProviders(<ProjectsTab />, { locale: 'ar' })
    await screen.findByText('Spring')
    const row = screen.getByTestId('settings-project-actions-p1')
    expect(within(row).queryByText('حذف')).not.toBeInTheDocument()
    expect(within(row).queryByText('أرشفة')).not.toBeInTheDocument()
    expect(screen.queryByTestId('settings-projects-filter-trash')).not.toBeInTheDocument()
  })

  it('the delete window states the impact, asks about the client and needs the typed name', async () => {
    signInWith(OWNER)
    serve({
      '/projects/p1/deletion-impact': {
        project: { id: 'p1', name: 'Spring', status: 'active', client: 'Acme' },
        counts: { integration_bindings: 2, campaigns: 5, budgets: 1, spend_limits: 1, alert_rules: 3, automation_rules: 1, recipients: 4, report_schedules: 2, active_shares: 0 },
        shared_accounts: [{ external_account_id: 'a1', provider: 'snapchat', name: 'Snap main', external_id: 'x', other_projects: [{ id: 'p9', name: 'Other', status: 'active' }] }],
        ad_platforms_untouched: true,
        client: { id: 'w1', name: 'Acme', other_projects: 0, can_delete_client: true },
        retention_days: 30,
      },
    })
    vi.mocked(deleteData).mockResolvedValue({ client_deleted: true, purge_after: null })
    renderWithProviders(<ProjectsTab />, { locale: 'ar' })
    await screen.findByText('Spring')
    fireEvent.click(screen.getByTestId('settings-project-delete-p1'))

    expect(await screen.findByTestId('lifecycle-delete-impact')).toHaveTextContent('5')
    expect(screen.getByTestId('shared-accounts-warning')).toHaveTextContent('Snap main')
    expect(screen.getByTestId('platforms-untouched')).toBeInTheDocument()
    expect(screen.getByTestId('lifecycle-delete-client-choice')).toBeInTheDocument()
    const confirm = screen.getByTestId('lifecycle-delete-confirm')
    expect(confirm).toBeDisabled()

    fireEvent.change(screen.getByTestId('lifecycle-delete-name'), { target: { value: 'Spring' } })
    expect(confirm).toBeEnabled()
    fireEvent.click(screen.getByTestId('lifecycle-delete-project-and-client'))
    expect(confirm).toBeDisabled()
    fireEvent.change(screen.getByTestId('lifecycle-delete-client-name'), { target: { value: 'Acme' } })
    expect(confirm).toBeEnabled()
    fireEvent.click(confirm)

    await waitFor(() => expect(deleteData).toHaveBeenCalledWith('/projects/p1', { confirm_name: 'Spring', client_action: 'project_and_client', confirm_client_name: 'Acme' }))
    expect(await screen.findByTestId('settings-project-notice')).toHaveTextContent('نُقل المشروع إلى المحذوفات')
  })

  it('when the client has other projects, only the project can be deleted (no client choice)', async () => {
    signInWith(OWNER)
    serve({
      '/projects/p1/deletion-impact': {
        project: { id: 'p1', name: 'Spring', status: 'active', client: 'Acme' }, counts: {}, shared_accounts: [], ad_platforms_untouched: true,
        client: { id: 'w1', name: 'Acme', other_projects: 2, can_delete_client: false }, retention_days: 30,
      },
    })
    renderWithProviders(<ProjectsTab />, { locale: 'ar' })
    await screen.findByText('Spring')
    fireEvent.click(screen.getByTestId('settings-project-delete-p1'))
    expect(await screen.findByTestId('lifecycle-delete-client-kept')).toBeInTheDocument()
    expect(screen.queryByTestId('lifecycle-delete-client-choice')).not.toBeInTheDocument()
  })
})
