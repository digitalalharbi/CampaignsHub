import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { TasksPage } from './TasksPage'
import { AlertsPage } from '@/features/alerts/AlertsPage'
import type { Task } from './api'
import type { AlertEvent } from '@/features/alerts/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listTasks: vi.fn(), createTask: vi.fn(), updateTask: vi.fn() }
})
vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('@/features/alerts/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listAlertRules: vi.fn(), createAlertRule: vi.fn(), listAlertEvents: vi.fn() }
})
vi.mock('@/features/notifications/api', () => ({ listDeliveries: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listClientWorkspaces: vi.fn().mockResolvedValue([]) }))

import { listTasks } from './api'
import { listAlertEvents, listAlertRules } from '@/features/alerts/api'
import { listDeliveries } from '@/features/notifications/api'
import { listProjects } from '@/features/projects/api'

/**
 * OPS-LINK-001 — a task says where it came from and who holds it, and the alert link lands on the alert.
 */
const task = (over: Partial<Task> = {}): Task => ({
  id: 't1', title: 'Check the budget', description: null, status: 'todo', priority: 'high',
  due_date: null, is_overdue: false, assignee_id: 'u2', project_id: 'p1', client_workspace_id: null,
  created_by: 'u1', created_at: null, updated_at: null,
  assignee_name: 'Sara', created_by_name: 'Omar',
  source: { kind: 'alert', id: 'ev1', title: 'Budget almost spent', severity: 'warning', status: 'open' },
  ...over,
} as Task)

const pageOf = (tasks: Task[]) => ({ tasks, total: tasks.length, page: 1, lastPage: 1, counts: { open: tasks.length, overdue: 0, done: 0 } })

const event = (id: string, status: AlertEvent['status']): AlertEvent => ({
  id, project_id: null, rule_id: 'r1', type: 'budget_risk', entity_type: null, entity_id: null,
  status, severity: 'warning', context: {}, notification_id: null, task_id: 't1',
  last_triggered_at: '2026-10-20T09:00:00Z', snoozed_until: null, resolved_at: null, created_at: null,
} as AlertEvent)

describe('task origin', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['tasks.view', 'tasks.create', 'tasks.update', 'alerts.view', 'alerts.manage'])
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], total: 0 } as never)
    vi.mocked(listDeliveries).mockResolvedValue([] as never)
    vi.mocked(listProjects).mockResolvedValue([] as never)
  })
  afterEach(() => signOut())

  it('links an alert-raised task back to its alert and names the people', async () => {
    vi.mocked(listTasks).mockResolvedValue(pageOf([task()]))
    renderWithProviders(<TasksPage />, { locale: 'en' })

    const link = await screen.findByTestId('task-source-alert-t1')
    expect(link.getAttribute('href')).toContain('/alerts?event=ev1')
    expect(link.textContent).toContain('Budget almost spent')
    const row = link.closest('li') ?? document.body
    expect(within(row as HTMLElement).getByText(/Sara/)).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText(/Omar/)).toBeInTheDocument()
  })

  /* DELIVERY-TASK-001 — the weekly review task opens the client's delivery tab, naming the week. */
  it('links a weekly-delivery task to that client’s delivery tab', async () => {
    vi.mocked(listTasks).mockResolvedValue(pageOf([task({
      source: { kind: 'delivery', id: 'pk1', client_workspace_id: 'cw1', status: 'draft', period: '2026-10-12 → 2026-10-18' } as Task['source'],
    })]))
    renderWithProviders(<TasksPage />, { locale: 'ar', route: '/agency/tasks' })

    const link = await screen.findByTestId('task-source-delivery-t1')
    expect(link.getAttribute('href')).toBe('/agency/clients/cw1?tab=delivery')
    expect(link.textContent).toContain('من التسليم')
    expect(link.textContent).toContain('2026-10-12 → 2026-10-18')
  })

  it('says a hand-made task is manual, with no alert link', async () => {
    vi.mocked(listTasks).mockResolvedValue(pageOf([task({ source: { kind: 'manual' } as Task['source'] })]))
    renderWithProviders(<TasksPage />, { locale: 'ar' })

    expect(await screen.findByText('Check the budget')).toBeInTheDocument()
    expect(screen.queryByTestId('task-source-alert-t1')).toBeNull()
  })

  it('shows the linked alert first even when the active filter would hide it', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('ev0', 'open'), event('ev1', 'resolved')], total: 2,
      counts: { open: 1, snoozed: 0, resolved: 1, open_critical: 0 },
    } as never)
    renderWithProviders(<AlertsPage />, { route: '/alerts?event=ev1' })

    const focused = await screen.findByTestId('alert-event-focused')
    expect(focused.getAttribute('data-focused')).toBe('true')
    expect(screen.queryByTestId('alert-event-focus-missing')).toBeNull()
  })

  it('says so when the linked alert is not in the ledger', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue({
      events: [event('ev0', 'open')], total: 1,
      counts: { open: 1, snoozed: 0, resolved: 0, open_critical: 0 },
    } as never)
    renderWithProviders(<AlertsPage />, { route: '/alerts?event=gone' })

    expect(await screen.findByTestId('alert-event-focus-missing')).toBeInTheDocument()
    expect(screen.queryByTestId('alert-event-focused')).toBeNull()
  })
})
