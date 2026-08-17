import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

/**
 * The states one of the six ad platforms can honestly be in, as a TENANT sees them (INTEG-UI-001).
 *
 * `disconnected` is not a failure: the platform is configured and simply nobody has authorised it
 * yet. Collapsing it into `awaiting_credentials` — the shape this page had before — told an operator
 * to go and find keys that were already provisioned.
 *
 * `unavailable` (PROVCFG-001) is the provider the platform operator has taken out of service. It is
 * kept apart from `awaiting_credentials` in the DATA even though the page says the same sentence for
 * both, because they are different facts and a future surface may need to tell them apart — one is
 * a setup that has not happened, the other a decision that has.
 */
export type PlatformState =
  | 'connected' | 'syncing' | 'error' | 'awaiting_credentials' | 'unavailable' | 'disconnected'

export interface Connector {
  key: string
  label: string
  status: 'awaiting_credentials' | 'connected' | 'error' | 'disconnected'
  ad_account_id: string | null
  last_synced_at: string | null
  last_sync_error: string | null
  /** Present only for the six ad platforms; the sandbox and analytics connectors keep the old shape. */
  is_ad_platform?: boolean
  state?: PlatformState
  /*
   * There is deliberately no `missing` here any more. The list of absent SYSTEM credentials was being
   * served to tenants, and it is an instruction for `/admin` addressed to the wrong reader — see the
   * doc block on `IntegrationsPage`.
   */
  accounts?: number
  connection_error?: string | null
  token_expires_at?: string | null
  /** When DATA last arrived, which is the question a customer is actually asking. */
  data_last_synced_at?: string | null
}

export function listConnectors(): Promise<Connector[]> {
  return getData<Connector[]>('/integrations')
}

export async function connectConnector(key: string): Promise<{ key: string; status: string }> {
  await ensureCsrfCookie()
  return postData<{ key: string; status: string }>(`/integrations/${key}/connect`)
}

/**
 * Ask where to send the customer to authorise us.
 *
 * The URL is returned rather than followed by the server, because this call is a `fetch`: a 302 to
 * facebook.com would be followed by the fetch and swallowed, and the customer would sit on a page
 * where nothing happened. The caller navigates.
 */
/**
 * Begin the customer's own authorisation (CONNECT-001).
 *
 * `clientWorkspaceId` is the `→ Client` link in the chain the architecture requires:
 *
 *     system provider configuration → user OAuth consent → external account → client → project
 *
 * It travels inside the single-use `state`, never in the callback's query string, so the workspace an
 * account lands in is the one chosen HERE by an authenticated member — not one a returning browser
 * could name for itself.
 */
export async function startPlatformOAuth(
  provider: string,
  clientWorkspaceId?: string | null,
): Promise<{ authorization_url: string }> {
  await ensureCsrfCookie()
  return postData<{ authorization_url: string }>(
    `/integrations/${provider}/oauth/start`,
    clientWorkspaceId ? { client_workspace_id: clientWorkspaceId } : {},
  )
}

export async function syncConnector(key: string): Promise<{ success: boolean; count: number }> {
  await ensureCsrfCookie()
  return postData<{ success: boolean; count: number }>(`/integrations/${key}/sync`)
}

/*
 * ORCH-100 — the connection wizard's reads.
 *
 * These four are what turns «authorised» into «connected». An authorisation produces an INVENTORY —
 * the live Snapchat consent produced 309 ad accounts — and none of it feeds a project until somebody
 * chooses. Everything below is a read; the only write is the confirm, which goes through the
 * project's own transactional bind endpoint.
 */

export type WizardState =
  | 'authorized_no_accounts' | 'needs_selection' | 'first_sync_pending' | 'active' | 'access_revoked'

export interface ConnectionWizard {
  state: WizardState
  discovered: number
  assigned: number
  synced: number
  has_parent: boolean
  resumable: boolean
  next_step: 'parent' | 'accounts' | 'sync' | 'reconnect' | null
}

export interface HierarchyParent {
  external_id: string
  /**
   * The provider's own name for it, or NULL when the provider never gave us one.
   *
   * Null rather than «fall back to the id» — RUNTIME-100 §5. An id rendered as a name claims the
   * provider called it that; the live Snapchat connection's 309 accounts were catalogued before this
   * product recorded organisation names, and the id-as-label fallback is exactly why production shows
   * a column of UUIDs. Saying «الاسم غير متاح» is true, and it points at the refresh that fixes it.
   */
  name: string | null
  account_count: number
}

export interface DiscoveredAccount {
  id: string
  external_id: string
  name: string
  parent_external_id: string | null
  parent_name: string | null
  currency: string | null
  timezone: string | null
  status: string
  assigned_project_id: string | null
  assigned: boolean
  /** Null until data has really arrived — discovery is not a sync. */
  last_synced_at: string | null
  access_lost_at: string | null
}

export interface ConnectionHierarchy {
  connection: {
    id: string
    provider: string
    label: string
    label_ar: string
    status: string
    client_workspace_id: string | null
  }
  has_parent: boolean
  parent_label: { key: string; label: string; labelAr: string } | null
  parents: HierarchyParent[]
  discovered_count: number
  assigned_count: number
  wizard: ConnectionWizard
}

export interface PlanUsage {
  limit: number | null
  used: number
  remaining: number | null
}

export function fetchConnectionHierarchy(connectionId: string): Promise<ConnectionHierarchy> {
  return getData<ConnectionHierarchy>(`/connections/${connectionId}/hierarchy`)
}

/** One page of discovered accounts, narrowed by parent and search — never the whole inventory. */
export function fetchDiscoveredAccounts(
  connectionId: string,
  params: { parent?: string | null; q?: string | null; page?: number; perPage?: number } = {},
): Promise<{ accounts: DiscoveredAccount[]; meta: { total: number; per_page: number; current_page: number; last_page: number } }> {
  const query = new URLSearchParams()
  if (params.parent) query.set('parent', params.parent)
  if (params.q) query.set('q', params.q)
  if (params.page) query.set('page', String(params.page))
  query.set('per_page', String(params.perPage ?? 25))

  return getData(`/connections/${connectionId}/accounts?${query.toString()}`)
}

/** What the plan has left, readable BEFORE anything is bound, for the review step. */
export function fetchPlanUsage(): Promise<Record<string, PlanUsage>> {
  return getData<Record<string, PlanUsage>>('/plan-usage')
}

export interface ResumableConnection extends ConnectionWizard {
  connection: { id: string; provider: string; label: string; label_ar: string; client_workspace_id: string | null }
}

export function fetchResumableConnections(): Promise<{
  connections: ResumableConnection[]
  resumable: ResumableConnection[]
}> {
  return getData('/connections/resumable')
}

/**
 * Connect one discovered account to a project.
 *
 * The transactional path: the quota is counted under a lock and the workspace fence is applied
 * server-side, so a refusal here is authoritative rather than advisory.
 */
export async function bindAccountToProject(
  projectId: string,
  externalAccountId: string,
): Promise<unknown> {
  await ensureCsrfCookie()
  return postData(`/projects/${projectId}/integrations/bindings`, {
    external_account_id: externalAccountId,
    purpose: 'advertising',
  })
}

/**
 * Confirm a WHOLE selection — RUNTIME-100 §10.
 *
 * The wizard used to call `bindAccountToProject` once per ticked account, which is not one decision
 * but a sequence of them: a plan with room for eight left somebody who chose ten with eight accounts
 * connected, two refusals, and nothing to undo — the server had done exactly as asked each time.
 *
 * One call, one transaction, all or nothing. The first sync starts on the server once it commits, so
 * there is nothing further for the interface to trigger.
 */
export async function confirmAccountSelection(input: {
  projectId: string
  connectionId: string
  externalAccountIds: string[]
  primaryExternalAccountId?: string
}): Promise<{ connected: number }> {
  await ensureCsrfCookie()
  return postData(`/projects/${input.projectId}/integrations/bindings/batch`, {
    connection_id: input.connectionId,
    external_account_ids: input.externalAccountIds,
    purpose: 'advertising',
    primary_external_account_id: input.primaryExternalAccountId ?? null,
  })
}

/**
 * Re-read this connection's catalogue with the token already held — RUNTIME-100 §5.
 *
 * No second consent screen. The live Snapchat connection shows organisation UUIDs where names
 * belong because its 309 accounts were catalogued before the product recorded `parent_name`; the
 * authorisation never lapsed, so repairing our own omission must not cost the customer a re-auth.
 */
export async function refreshDiscoveredAccounts(connectionId: string): Promise<{
  discovered: number
  created: number
  named: number
  access_lost: number
}> {
  await ensureCsrfCookie()
  return postData(`/connections/${connectionId}/refresh`)
}
