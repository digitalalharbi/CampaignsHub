import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { TasksPage } from './TasksPage'
import type { Task, TaskPage } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listTasks: vi.fn(), createTask: vi.fn(), updateTask: vi.fn() }
})

import { listTasks } from './api'

/**
 * TASKS-LEDGER-001 — the page counts the ledger and says what it is showing.
 *
 * `TaskController::index()` handed over every task — its own comment cites 2,105 in one tenant —
 * and this page fetched all of them, sifted them in the browser, and computed «open», «overdue» and
 * «done» from whatever it held. Paginating alone would have left those three counts describing the
 * PAGE, which is exactly the defect the alerts queue was fixed for, one screen along.
 */
const task = (over: Partial<Task> = {}): Task => ({
  id: 't1', title: 'Write the brief', description: null, status: 'todo', priority: 'medium',
  due_date: null, is_overdue: false, assignee_id: null, project_id: 'p1', client_workspace_id: null,
  created_by: 'u1', created_at: null, updated_at: null,
  ...over,
} as Task)

const page = (over: Partial<TaskPage> = {}): TaskPage => ({
  tasks: [task()],
  total: 120,
  page: 1,
  lastPage: 5,
  counts: { open: 97, overdue: 12, done: 23 },
  ...over,
})

describe('the tasks page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listTasks).mockResolvedValue(page())
    signInWith(['tasks.view', 'tasks.create', 'tasks.update'])
  })
  afterEach(() => signOut())

  it('shows the counts of everything, not of the rows that fitted', async () => {
    renderWithProviders(<TasksPage />, { locale: 'en' })

    /* One row arrived; the ledger holds 120, 97 of them open. */
    expect(await screen.findByText('97')).toBeInTheDocument()
    expect(screen.getByText('120')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
  })

  /* No silent caps: twenty-five rows of a hundred with nothing saying so reads as a hundred-row workspace. */
  it('says which page of how many it is showing', async () => {
    renderWithProviders(<TasksPage />, { locale: 'en' })

    const pager = await screen.findByTestId('tasks-pager')
    expect(pager.textContent).toContain('of 120')
    expect(pager.textContent).toMatch(/page 1 of 5/i)
  })

  it('asks the server for the next page rather than slicing what it holds', async () => {
    renderWithProviders(<TasksPage />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Next/i }))

    await waitFor(() => {
      expect(vi.mocked(listTasks).mock.calls.some(([f]) => f?.page === 2)).toBe(true)
    })
  })

  /**
   * The search is the server's.
   *
   * Filtering a page in the browser answers «no results» about a task the workspace is holding —
   * it simply was not among the rows that arrived.
   */
  it('sends the search term to the server', async () => {
    renderWithProviders(<TasksPage />, { locale: 'en' })
    await screen.findByTestId('tasks-pager')

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Riyadh' } })

    await waitFor(() => {
      expect(vi.mocked(listTasks).mock.calls.some(([f]) => f?.q === 'Riyadh')).toBe(true)
    })
  })

  /* A single page needs no pager, and a control that does nothing teaches the reader to ignore it. */
  it('offers no pager when everything fits', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ total: 1, lastPage: 1, counts: { open: 1, overdue: 0, done: 0 } }))
    renderWithProviders(<TasksPage />, { locale: 'en' })

    expect(await screen.findByText('Write the brief')).toBeInTheDocument()
    expect(screen.queryByTestId('tasks-pager')).toBeNull()
  })
})
