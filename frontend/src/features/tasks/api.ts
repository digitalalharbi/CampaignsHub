import { getData, patchData, postData } from '@/lib/api/client'

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

export interface TaskFilters { status?: string; mine?: boolean }

export interface NewTask {
  title: string
  description?: string | null
  status?: string
  priority?: string
  due_date?: string | null
  project_id?: string | null
}

export async function listTasks(f?: TaskFilters): Promise<Task[]> {
  const params = new URLSearchParams()
  if (f?.status) params.set('status', f.status)
  if (f?.mine) params.set('mine', '1')
  const qs = params.toString()
  return getData<Task[]>(`/tasks${qs ? `?${qs}` : ''}`)
}

export const createTask = (body: NewTask) => postData<Task>('/tasks', body)

export const updateTask = (id: string, body: Partial<NewTask> & { status?: string }) =>
  patchData<Task>(`/tasks/${encodeURIComponent(id)}`, body)
