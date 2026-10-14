import { beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { ReactNode } from 'react'
import { OpsPanel } from './OpsPanel'
import { PortalFrame } from './PortalFrame'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import type { AuthUser } from '@/lib/api/types'
import type { HubConnection } from '@/features/integrations/api'

vi.mock('@/lib/api/client', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getData: vi.fn() }
})
vi.mock('@/features/integrations/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchConnectionHub: vi.fn() }
})
vi.mock('@/features/tasks/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listTasks: vi.fn() }
})
vi.mock('@/features/notifications/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listNotifications: vi.fn() }
})
vi.mock('@/features/projects/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listProjects: vi.fn(), listSyncRuns: vi.fn() }
})

import { getData } from '@/lib/api/client'
import { fetchConnectionHub } from '@/features/integrations/api'
import { listTasks } from '@/features/tasks/api'
import { listNotifications } from '@/features/notifications/api'
import { listProjects, listSyncRuns } from '@/features/projects/api'

function connection(over: Partial<HubConnection> = {}): HubConnection {
  return {
    id: 'c1', provider: 'meta', label: 'Meta', label_ar: 'ميتا', connection_name: 'Agency Meta',
    authorised_by: null, authorised_at: null, selected_accounts: 1, discovered_accounts: 3,
    connection_state: 'CONNECTED', sync_state: 'SUCCEEDED', needs_attention: false,
    last_success_at: '2026-10-14T08:00:00Z', next_sync_at: null, projects: [], has_parent: false,
    discovery_blocked_reason: null, token_expires_at: null, ...over,
  }
}

function signIn(permissions: string[]) {
  useAuth.setState({
    user: { id: 1, name: 'Owner', email: 'o@example.test', is_platform_admin: false, permissions, workspace_name: 'Acme' } as unknown as AuthUser,
    status: 'authenticated',
  })
}

function mount(locale: 'ar' | 'en' = 'en') {
  useUi.setState({ locale, opsPanelOpen: true })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  function Wrapper({ children }: { children: ReactNode }) {
    return <MemoryRouter initialEntries={['/app/dashboard']}><QueryClientProvider client={client}>{children}</QueryClientProvider></MemoryRouter>
  }
  render(<OpsPanel />, { wrapper: Wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  useProject.setState({ currentProjectId: 'p1' })
  vi.mocked(getData).mockImplementation(async (path: string) => {
    if (path === '/health') return { status: 'ok', checks: { database: 'up', redis: 'up' } }
    if (path.endsWith('/capabilities')) return { capabilities: ['campaigns.view', 'analytics.view'] }
    if (path.endsWith('/campaign-activity')) return [
      { id: 'e1', action: 'updated', label: 'Budget changed', actor: 'Sara', at: '2026-10-14T07:00:00Z', before: { budget: 100 }, after: { budget: 150 }, source: 'meta', campaign_id: 'k1', campaign_name: 'Spring', budget_currency: 'SAR' },
      { id: 'e2', action: 'created', label: 'Campaign created', actor: 'Sara', at: '2026-10-13T07:00:00Z', before: null, after: null, source: 'meta', campaign_id: 'k2', campaign_name: 'Autumn', budget_currency: 'SAR' },
    ]
    throw new Error(`unexpected ${path}`)
  })
  vi.mocked(fetchConnectionHub).mockResolvedValue({
    connections: [connection(), connection({ id: 'c2', label: 'TikTok', connection_state: 'REAUTH_REQUIRED' })],
    connectable: [],
  })
  vi.mocked(listTasks).mockResolvedValue({
    tasks: [{ id: 't1', title: 'Approve creatives', description: null, status: 'todo', priority: 'high', project_id: 'p1', client_workspace_id: null, assignee_id: 1, due_date: '2026-10-12', is_overdue: true, checklist: [], created_at: null }],
    total: 1, page: 1, lastPage: 1, counts: { open: 3, overdue: 1, done: 7 },
  })
  vi.mocked(listNotifications).mockResolvedValue({
    items: [{ id: 'n1', type: 'sync_failed', severity: 'critical', title: 'Sync failed', message: null, project_id: 'p1', client_workspace_id: null, action_url: null, status: 'unread', read_at: null, created_at: '2026-10-14T06:00:00Z' }],
    unread: 2, total: 4, withheld: 0,
  })
  vi.mocked(listProjects).mockResolvedValue([{ id: 'p1', client_workspace_id: 'w1', name: 'Store One', status: 'active', setup_completion: 80, account_manager_id: null, created_at: null }])
  vi.mocked(listSyncRuns).mockResolvedValue({
    runs: [{ id: 'r1', provider: 'tiktok', status: 'running', trigger: 'schedule', account: 'Main', account_external_id: '1', window_start: null, window_end: null, provider_rows: null, parsed_rows: null, mapped_rows: null, metrics_imported: 0, duration_seconds: null, attempts: 1, started_at: '2026-10-14T08:10:00Z', finished_at: null, error: null, is_demo: false, repeats: 1, repeats_since: null }],
    summary: { running: 1 }, runs_total: 1, runs_withheld: 0,
  })
})

/**
 * OPS-PANEL-001 — the operational side panel reads what the portal already serves, says status
 * first, and offers no section the reader may not open.
 */
describe('OpsPanel', () => {
  it('summarises every section from the existing endpoints', async () => {
    signIn(['integrations.view', 'tasks.view'])
    mount()

    expect(await screen.findByTestId('ops-integrations-status')).toHaveTextContent('1 need fixing')
    expect(await screen.findByTestId('ops-tasks-status')).toHaveTextContent('3 open · 1 overdue')
    expect(await screen.findByTestId('ops-notifications-status')).toHaveTextContent('2 unread')
    expect(await screen.findByTestId('ops-jobs-status')).toHaveTextContent('1 running')
    expect(await screen.findByTestId('ops-health-status')).toHaveTextContent('Healthy')
    expect(await screen.findByTestId('ops-workspace-project')).toHaveTextContent('Store One')
    await waitFor(() => expect(within(screen.getByTestId('ops-changes')).getByText('1')).toBeInTheDocument())
    expect(within(screen.getByTestId('ops-activity')).getByText('2')).toBeInTheDocument()
  })

  it('shows detail only once a section is expanded', async () => {
    signIn(['integrations.view', 'tasks.view'])
    mount()

    await screen.findByTestId('ops-integrations-status')
    const toggle = within(screen.getByTestId('ops-integrations')).getByRole('button')
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText('Agency Meta', { exact: false })).not.toBeInTheDocument()

    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getAllByText(/Agency Meta/)).toHaveLength(2)
    expect(screen.getByText('Re-authorisation needed')).toBeInTheDocument()
  })

  it('offers no section the reader lacks the permission for, and never asks for it', async () => {
    signIn([])
    mount()

    await screen.findByTestId('ops-notifications-status')
    expect(screen.queryByTestId('ops-integrations')).not.toBeInTheDocument()
    expect(screen.queryByTestId('ops-tasks')).not.toBeInTheDocument()
    expect(screen.queryByTestId('ops-jobs')).not.toBeInTheDocument()
    expect(fetchConnectionHub).not.toHaveBeenCalled()
    expect(listTasks).not.toHaveBeenCalled()
    expect(listSyncRuns).not.toHaveBeenCalled()
  })

  it('says a failed source is unavailable rather than zero', async () => {
    signIn(['integrations.view', 'tasks.view'])
    vi.mocked(listTasks).mockRejectedValue(new Error('500'))
    mount()

    const tasks = await screen.findByTestId('ops-tasks')
    await waitFor(() => expect(within(tasks).getByText('Unavailable')).toBeInTheDocument())
    expect(within(tasks).queryByText(/0 open/)).not.toBeInTheDocument()
  })

  it('never calls the platform down while the health answer is still on its way', async () => {
    signIn([])
    const pending = new Promise<never>(() => {})
    vi.mocked(getData).mockImplementation((path: string) => (path === '/health' ? pending : Promise.reject(new Error(path))))
    mount()

    fireEvent.click(within(screen.getByTestId('ops-health')).getByRole('button'))
    expect(screen.queryByText('Not responding')).not.toBeInTheDocument()
    expect(screen.queryByText('Degraded')).not.toBeInTheDocument()
  })

  it('speaks Arabic in the Arabic locale', async () => {
    signIn(['integrations.view', 'tasks.view'])
    mount('ar')

    expect(await screen.findByTestId('ops-tasks-status')).toHaveTextContent('3 مفتوحة · 1 متأخرة')
    expect(screen.getByText('لوحة التشغيل')).toBeInTheDocument()
  })
})

describe('PortalFrame end panel', () => {
  it('takes no width when closed: the aside is not in the tree', () => {
    const { rerender } = render(<MemoryRouter><PortalFrame>page</PortalFrame></MemoryRouter>)
    expect(screen.queryByTestId('portal-end-panel')).not.toBeInTheDocument()

    rerender(<MemoryRouter><PortalFrame endPanel={<span>ops</span>}>page</PortalFrame></MemoryRouter>)
    expect(screen.getByTestId('portal-end-panel')).toHaveClass('hidden', 'lg:flex')
  })
})
