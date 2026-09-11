import { getEnvelope, patchData, postData } from '@/lib/api/client'

/** Mirrors backend App\Domains\Tasks (TaskController + TaskResource). Tenant + project scoped. */
export type TaskStatus =
  | 'backlog' | 'todo' | 'in_progress' | 'waiting_client' | 'blocked' | 'review' | 'completed' | 'cancelled'
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent'

export interface Task {
  id: string
  title: string
  description: string | null
  status: TaskStatus | string
  priority: TaskPriority | string
  project_id: string | null
  client_workspace_id: string | null
  assignee_id: number | null
  due_date: string | null
  is_overdue: boolean
  checklist: unknown[]
  created_at: string | null
}

export const TASK_STATUSES: TaskStatus[] = [
  'backlog', 'todo', 'in_progress', 'waiting_client', 'blocked', 'review', 'completed', 'cancelled',
]
export const TASK_PRIORITIES: TaskPriority[] = ['low', 'normal', 'high', 'urgent']

/** Statuses that count as "open" work (not terminal). Canonical set (legacy values normalized in the DB). */
export const OPEN_STATUSES: string[] = ['backlog', 'todo', 'in_progress', 'waiting_client', 'blocked', 'review']

export interface TaskFilters {
  status?: string
  priority?: string
  mine?: boolean
  /** Free-text over title and description, applied by the SERVER so it reaches past page one. */
  q?: string
}

export interface NewTask {
  title: string
  description?: string | null
  status?: string
  priority?: string
  due_date?: string | null
  project_id?: string | null
  /** Free-text over title and description, applied by the server so it reaches past page one. */
  q?: string
  mine?: boolean
}

export interface TaskCounts {
  open: number
  overdue: number
  done: number
}

export interface TaskPage {
  tasks: Task[]
  total: number
  page: number
  lastPage: number
  /** Computed over the whole filtered ledger, never over the rows that fitted on this page. */
  counts: TaskCounts
}

/**
 * TASKS-LEDGER-001 — every filter the page offers is the SERVER's.
 *
 * This sent `status` and `mine` and nothing else, because the endpoint handed over the whole table
 * and the browser sifted it. Against a page, a filter the server does not know about searches
 * twenty-five rows and silently misses every match behind them — a search box answering «no
 * results» about a task the workspace is holding.
 */
export async function listTasks(f?: TaskFilters & { page?: number; perPage?: number }): Promise<TaskPage> {
  const params = new URLSearchParams()
  if (f?.status) params.set('status', f.status)
  if (f?.priority) params.set('priority', f.priority)
  if (f?.q) params.set('q', f.q)
  if (f?.mine) params.set('mine', '1')
  if (f?.page) params.set('page', String(f.page))
  params.set('per_page', String(f?.perPage ?? 25))

  const res = await getEnvelope<Task[]>(`/tasks?${params.toString()}`)
  const tasks = res.data ?? []
  const meta = res.meta as
    | { total?: number; current_page?: number; last_page?: number; counts?: Partial<TaskCounts> }
    | undefined
  const c = meta?.counts

  return {
    tasks,
    total: Number(meta?.total ?? tasks.length),
    page: Number(meta?.current_page ?? 1),
    lastPage: Number(meta?.last_page ?? 1),
    /*
     * Falling back to counting the rows we have is the honest failure mode for an older server that
     * sends no counts — it is what the page did before, and it can only under-count a bounded
     * ledger, never invent tasks that do not exist.
     */
    counts: {
      open: Number(c?.open ?? tasks.filter((t) => OPEN_STATUSES.includes(t.status)).length),
      overdue: Number(c?.overdue ?? tasks.filter((t) => t.is_overdue).length),
      done: Number(c?.done ?? tasks.filter((t) => t.status === 'completed').length),
    },
  }
}

export const createTask = (body: NewTask) => postData<Task>('/tasks', body)

export const updateTask = (id: string, body: Partial<NewTask> & { status?: string }) =>
  patchData<Task>(`/tasks/${encodeURIComponent(id)}`, body)
