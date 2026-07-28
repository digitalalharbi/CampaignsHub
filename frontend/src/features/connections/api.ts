import { api, ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * Connection Center API layer (project-scoped). Every value here mirrors the backend's HONEST
 * ConnectionState enum + ConnectionCenterService::describe(). The UI never invents a "connected" state —
 * it only ever renders the `state` the backend derived.
 */

/** The honest lifecycle states (mirror of App\...\Enums\ConnectionState). */
export const CONNECTION_STATES = [
  'available', 'awaiting_credentials', 'sandbox_verified', 'production_verified',
  'permission_missing', 'token_expired', 'sync_failed',
] as const
export type ConnectionState = (typeof CONNECTION_STATES)[number]

export interface ConnectorConnection {
  id: string
  status: string
  token_expires_at: string | null
  last_successful_sync_at: string | null
  last_error: string | null
}

/** A connector row with its capabilities + honest state (mirror of describe()). */
export interface Connector {
  provider: string
  label: string
  capabilities: string[]
  is_sandbox: boolean
  has_credentials: boolean
  awaiting_external_dependency: boolean
  state: ConnectionState
  state_label: string
  is_healthy: boolean
  connection: ConnectorConnection | null
}

export interface SyncResult {
  provider: string
  state: ConnectionState
  state_label: string
  sync_run_id: string
  status: string
  records: number
  metrics_upserted: number
  message: string | null
}

export interface SyncRun {
  id: string
  status: string
  window_start: string | null
  window_end: string | null
  metrics_upserted: number | null
  attempts: number | null
  started_at: string | null
  finished_at: string | null
  error: string | null
}

export interface DataFreshness {
  last_run_at: string | null
  last_status: string | null
  metrics_upserted: number | null
}

export interface ConnectionHistory {
  provider: string
  runs: SyncRun[]
  errors: SyncRun[]
  data_freshness: DataFreshness
}

const base = (projectId: string) => `/projects/${projectId}/connections`

export async function listConnectors(projectId: string): Promise<Connector[]> {
  const res = await api.get<ApiEnvelope<Connector[]>>(base(projectId))
  return res.data.data ?? []
}

export async function syncConnector(projectId: string, provider: string, days = 7): Promise<SyncResult> {
  await ensureCsrfCookie()
  return postData<SyncResult>(`${base(projectId)}/${encodeURIComponent(provider)}/sync`, { days })
}

export function getConnectionHistory(projectId: string, provider: string): Promise<ConnectionHistory> {
  return getData<ConnectionHistory>(`${base(projectId)}/${encodeURIComponent(provider)}/history`)
}
