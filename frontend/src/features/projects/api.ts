import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

export interface Project {
  id: string
  client_workspace_id: string
  name: string
  status: string
  setup_completion: number
  account_manager_id: number | null
  created_at: string | null
}

export interface ClientWorkspace {
  id: string
  name: string
  mode: string
  status: string
  projects_count?: number
}

export interface ExternalAccount {
  id: string
  account_type: string
  external_id: string
  name: string
  currency: string | null
  timezone: string | null
  last_synced_at: string | null
  connection_status: string | null
}

export interface Binding {
  id: string
  purpose: string
  provider: string
  is_primary: boolean
  is_active: boolean
  account: ExternalAccount | null
  created_at: string | null
}

export interface ProjectTask {
  id: string
  title: string
  status: string
  priority: string
  is_overdue: boolean
}

export function listProjects(): Promise<Project[]> {
  return getData<Project[]>('/projects')
}

export async function createProject(input: { client_workspace_id: string; name: string }): Promise<Project> {
  await ensureCsrfCookie()
  return postData<Project>('/projects', input)
}

export async function archiveProject(projectId: string): Promise<Project> {
  await ensureCsrfCookie()
  return postData<Project>(`/projects/${projectId}/archive`)
}

/** Project-scoped tasks (change when the active project changes). */
export function listProjectTasks(projectId: string): Promise<ProjectTask[]> {
  return getData<ProjectTask[]>(`/projects/${projectId}/tasks`)
}

export function listClientWorkspaces(): Promise<ClientWorkspace[]> {
  return getData<ClientWorkspace[]>('/client-workspaces')
}

/** Bindings for a specific project (project-scoped on the server). */
export function listProjectBindings(projectId: string): Promise<Binding[]> {
  return getData<Binding[]>(`/projects/${projectId}/integrations`)
}

export interface ConnectResult {
  connection: { id: string; name: string; status: string }
  accounts: ExternalAccount[]
}

export async function connectSandbox(projectId: string): Promise<ConnectResult> {
  await ensureCsrfCookie()
  return postData<ConnectResult>(`/projects/${projectId}/integrations/connect`)
}

export async function bindAccount(
  projectId: string,
  input: { external_account_id: string; purpose: string; confirm?: boolean },
): Promise<Binding> {
  await ensureCsrfCookie()
  const res = await api.post<ApiEnvelope<Binding>>(`/projects/${projectId}/integrations/bindings`, input)
  return res.data.data
}

export async function syncBinding(projectId: string, bindingId: string): Promise<{ status: string; records: number }> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/integrations/bindings/${bindingId}/sync`)
}

export async function detachBinding(projectId: string, bindingId: string): Promise<null> {
  await ensureCsrfCookie()
  const res = await api.delete<ApiEnvelope<null>>(`/projects/${projectId}/integrations/bindings/${bindingId}`)
  return res.data.data
}
