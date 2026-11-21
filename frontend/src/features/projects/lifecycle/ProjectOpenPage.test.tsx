import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ClientProjectsTab } from '@/features/clients/ClientProjectsTab'
import { ProjectOpenPage } from './ProjectOpenPage'

/**
 * PROJECT-LIFECYCLE-001 §8, §10 (builder N2) — «فتح» on an ARCHIVED project opens its own page in view-only mode with
 * the state banner and «استعادة» / «حذف» by permission — not a redirect to the client page. A live project keeps its
 * destination (the client page in the agency portal).
 */
vi.mock('@/lib/api/client', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getData: vi.fn() }
})

import { getData } from '@/lib/api/client'

const ARCHIVED = { id: 'p2', name: 'Old one', status: 'archived', lifecycle_status: 'archived', client_workspace_id: 'w1', setup_completion: 100, archived_at: '2026-11-10T10:00:00Z' }

function serve(project: Record<string, unknown>) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url === `/projects/${project.id}`) return project
    if (url === `/projects/${project.id}/lifecycle`) return { status: 'archived', archived_at: null, previous_status: 'active', purge_after: null, transitions: [{ id: 't1', from: 'active', to: 'archived', actor_id: 1, actor_name: 'Owner', name_snapshot: 'Old one', reason: null, context: null, at: '2026-11-10T10:00:00Z' }] }
    if (url === `/projects/${project.id}/integrations`) return []
    if (url === '/client-workspaces') return [{ id: 'w1', name: 'Acme' }]
    throw new Error(`unexpected ${url}`)
  })
}

describe('ProjectOpenPage', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => signOut())

  it('an archived project opens view-only with the banner and the permitted buttons', async () => {
    serve(ARCHIVED)
    signInWith(['projects.view', 'projects.restore', 'projects.delete'])
    renderWithProviders(<ProjectOpenPage />, { locale: 'ar', route: '/agency/projects/p2', path: '/agency/projects/:projectId' })

    const page = await screen.findByTestId('archived-project-page')
    expect(page).toHaveAttribute('data-view-only', 'true')
    expect(screen.getByTestId('archived-project-banner')).toHaveTextContent('للعرض فقط')
    expect(screen.getByTestId('archived-project-intro')).toHaveTextContent('Old one')
    expect(screen.getByTestId('archived-project-restore')).toHaveTextContent('استعادة')
    expect(screen.getByTestId('archived-project-delete')).toHaveTextContent('حذف')
    expect(await screen.findByTestId('archived-project-history')).toHaveTextContent('مؤرشف')
  })

  it('a member without the permissions sees the banner and no action', async () => {
    serve(ARCHIVED)
    signInWith(['projects.view'])
    renderWithProviders(<ProjectOpenPage />, { locale: 'ar', route: '/agency/projects/p2', path: '/agency/projects/:projectId' })
    await screen.findByTestId('archived-project-banner')
    expect(screen.queryByTestId('archived-project-restore')).not.toBeInTheDocument()
    expect(screen.queryByTestId('archived-project-delete')).not.toBeInTheDocument()
  })

  it('a live project still lands on its client page in the agency portal', async () => {
    serve({ ...ARCHIVED, status: 'active', lifecycle_status: 'active' })
    signInWith(['projects.view'])
    renderWithProviders(
      <Routes>
        <Route path="/agency/projects/:projectId" element={<ProjectOpenPage />} />
        <Route path="/agency/clients/:clientId" element={<p data-testid="client-page">client</p>} />
      </Routes>,
      { route: '/agency/projects/p2' },
    )
    expect(await screen.findByTestId('client-page')).toBeInTheDocument()
  })
})

describe('ClientProjectsTab', () => {
  it('separates active from archived, archived opening the view-only page', () => {
    renderWithProviders(
      <ClientProjectsTab ar projects={[{ id: 'a', name: 'Live one', status: 'active' }, { id: 'b', name: 'Old one', status: 'archived' }]} portalTo={(s) => `/agency${s}`} />,
      { locale: 'ar' },
    )
    expect(screen.getByTestId('client-projects-active')).toHaveTextContent('Live one')
    expect(screen.getByTestId('client-projects-archived')).toHaveTextContent('Old one')
    expect(screen.getByRole('link', { name: 'Old one' })).toHaveAttribute('href', '/agency/projects/b')
    expect(screen.queryByText('archived')).not.toBeInTheDocument()
  })
})
