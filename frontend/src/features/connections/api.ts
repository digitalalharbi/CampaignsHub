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

/* ── COMMAND-CENTER §§7–20: the account inventory ──────────────────────────────────────────────── */

/**
 * The four lifecycle states, mirroring `App\Domains\Integrations\Services\AccountLifecycle`.
 *
 * They are four and not one because the product spent a long time saying «متصل» for all of them, and
 * that single word is what made 309 discovered Snapchat accounts unreadable and let an account nobody
 * chose be counted as though somebody had.
 */
export const ACCOUNT_LIFECYCLES = ['discovered', 'enabled', 'excluded', 'assigned'] as const
export type AccountLifecycle = (typeof ACCOUNT_LIFECYCLES)[number]

/** The states a person may set. `assigned` is absent on purpose — it is the binding's answer. */
export const SETTABLE_LIFECYCLES = ['discovered', 'enabled', 'excluded'] as const
export type SettableLifecycle = (typeof SETTABLE_LIFECYCLES)[number]

export interface InventoryAccount {
  id: string
  provider: string
  provider_label: string
  account_type: 'ad_account' | 'store'
  account_type_label: string
  /** What to READ. Never an identifier — the server substitutes words when the provider gave none. */
  name: string
  /** What to MATCH against the provider's own console. Always the raw external id. */
  reference: string
  named_by_provider: boolean
  parent_name: string | null
  parent_external_id: string | null
  currency: string | null
  timezone: string | null
  connection_id: string
  connection_name: string | null
  lifecycle: AccountLifecycle
  lifecycle_label: string
  lifecycle_hint: string
  assigned_project_id: string | null
  /** The project's NAME. An id is not an answer to «where does this go». */
  assigned_project_name: string | null
  /** Null where nothing has ever tried to sync — an absent badge, never a green one. */
  health: string | null
  last_synced_at: string | null
  last_sync_attempt_at: string | null
  last_sync_error_category: string | null
  next_sync_at: string | null
  access_lost_at: string | null
  counts_toward_ad_account_quota: boolean
}

export interface InventorySummary {
  discovered: number
  enabled: number
  excluded: number
  assigned: number
  total: number
}

export interface InventoryPage {
  accounts: InventoryAccount[]
  /** Counts the WHOLE inventory, deliberately unaffected by the filters that cut the list. */
  summary: InventorySummary
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

export interface InventoryQuery {
  provider?: string
  connection?: string
  account_type?: 'ad_account' | 'store'
  state?: AccountLifecycle
  q?: string
  page?: number
  per_page?: number
}

export function listInventory(query: InventoryQuery = {}): Promise<InventoryPage> {
  const params = new URLSearchParams()
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  })

  return getData<InventoryPage>(`/accounts?${params.toString()}`)
}

export async function setAccountState(id: string, state: SettableLifecycle): Promise<InventoryAccount> {
  await ensureCsrfCookie()
  return postData<InventoryAccount>(`/accounts/${id}/state`, { state })
}

/** Present because the real number is 309 — one decision for many accounts, applied atomically. */
export async function setAccountStateBulk(
  ids: string[],
  state: SettableLifecycle,
): Promise<{ updated: number; state: SettableLifecycle }> {
  await ensureCsrfCookie()
  return postData(`/accounts/state`, { account_ids: ids, state })
}

export interface AccountSyncRun {
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

export function getAccountLogs(id: string): Promise<{ account: InventoryAccount; runs: AccountSyncRun[] }> {
  return getData(`/accounts/${id}/logs`)
}

/** A window the scheduled sweep will never cover — refused for an account no project owns. */
export async function backfillAccount(
  id: string,
  from: string,
  to: string,
): Promise<{ account_id: string; from: string; to: string; queued: boolean }> {
  await ensureCsrfCookie()
  return postData(`/accounts/${id}/backfill`, { from, to })
}
