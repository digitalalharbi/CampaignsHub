import { ensureCsrfCookie, getData, postData, putData } from '@/lib/api/client'

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
  /**
   * Which KIND of thing this is — INTEG-STORES-001.
   *
   * A store carries no `ad_account_id` and none of the five ad-platform states, so it must not be
   * rendered as an ad platform: an ad-platform card with those fields empty reads as a platform that
   * failed to connect, which is a worse lie than the store being absent was.
   */
  kind?: 'advertising' | 'commerce'
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

/** RUNTIME-100 §31 — what one connection's accounts add up to, as counts rather than one badge. */
export interface ConnectionHealthSummary {
  connected: number
  healthy: number
  pending_first_sync: number
  needs_attention: number
  states: Record<string, number>
}

export interface ConnectionWizard {
  state: WizardState
  discovered: number
  assigned: number
  synced: number
  has_parent: boolean
  resumable: boolean
  next_step: 'parent' | 'accounts' | 'sync' | 'reconnect' | null
  /**
   * INTEGRATION-DATASOURCE-WIZARD-001 §14 — what a READER is told, decided by the server.
   *
   * `state` is a fact about the record; this is the word for it, and it is mapped in one place so
   * three surfaces cannot invent three vocabularies for one connection. Optional so a payload
   * written before it existed still renders.
   */
  user_state?: 'NOT_CONNECTED' | 'AUTH_REQUIRED' | 'ACCOUNT_SELECTION_REQUIRED' | 'SYNCING' | 'HEALTHY' | 'ATTENTION_REQUIRED' | 'REAUTH_REQUIRED'
  /** What this connection's accounts add up to, so the card can stop claiming one state for all of them. */
  health?: ConnectionHealthSummary
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
  /**
   * How this ACCOUNT is doing — RUNTIME-100 §31.
   *
   * Per account rather than per provider, because ten accounts behind one authorisation with one
   * whose access was withdrawn used to render as a single green «متصل», and that one account is the
   * only fact on the card anybody needed.
   */
  health?: 'not_connected' | 'revoked' | 'access_lost' | 'failed' | 'pending_first_sync' | 'delayed' | 'healthy'
  /** We TRIED. Distinct from `last_synced_at`, which is only written when data really arrives. */
  last_sync_attempt_at?: string | null
  /** Why it did not work, as a category — the thing that decides who has to act. */
  last_sync_error_category?: string | null
  /** When we will ask again, so «it is old» can be answered with «and it refreshes at 03:30». */
  next_sync_at?: string | null
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

export interface ProjectBinding {
  id: string
  provider: string
  is_active: boolean
  account: { id: string; external_id: string; name: string } | null
}

/**
 * What this project holds right now — the starting point «Manage accounts» opens on.
 *
 * The picker needs it before the first page of the catalogue loads: a bound account on page four
 * must open ticked, and a selection seeded from whatever page happens to be on screen would silently
 * unbind everything the reader has not scrolled to.
 */
export function listProjectBindings(projectId: string): Promise<ProjectBinding[]> {
  return getData<ProjectBinding[]>(`/projects/${projectId}/integrations`)
}

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» sends the DESIRED SET.
 *
 * Not «add these and remove those»: that describes a state the browser read some seconds ago, and
 * two operators managing the same project would each undo the other's change. The server compares
 * the desired set with what is bound now and returns the diff it applied, so sending the same set
 * twice is the same decision and changes nothing the second time.
 *
 * An EMPTY list is a legitimate answer here — «this project keeps none of them» — and is refused by
 * `confirmAccountSelection`, which answers a different question: which accounts shall this project
 * START with.
 *
 * It asks for no new authorisation: the token that discovered these accounts is the token that
 * binds them.
 */
export async function applyAccountSelection(input: {
  projectId: string
  connectionId: string
  externalAccountIds: string[]
}): Promise<{ added: string[]; unchanged: string[]; removed: string[] }> {
  await ensureCsrfCookie()
  return putData(`/projects/${input.projectId}/integrations/selection`, {
    connection_id: input.connectionId,
    external_account_ids: input.externalAccountIds,
    purpose: 'advertising',
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

/**
 * COMMAND-CENTER §26 — end the authorisation, and say what that costs before it happens.
 *
 * Not a local flag. The server marks the connection revoked AND disables every project binding that
 * used any of its accounts, across every project — because leaving them active would leave projects
 * pointing at a source nothing can read, reporting a stale number as a current one.
 *
 * That is why the caller must confirm with the count in front of them: «قطع الاتصال» sounds like
 * undoing a setting and is actually the end of the data flow for however many accounts.
 */
export async function revokeConnection(connectionId: string): Promise<{ status: string }> {
  await ensureCsrfCookie()
  return postData(`/connections/${connectionId}/revoke`)
}

// ── The tenant's discovered accounts ─────────────────────────────────────────────────────────────

/**
 * INTEG-RUNTIME §3 §5 — one account, and the one thing that is true of it.
 *
 * `is_linked` is read on the server from `ProjectIntegrationBinding` where `is_active`. There is no
 * curation state here and there must not be: an earlier cut carried discovered / enabled / excluded
 * as well, and «enabled» named a decision that changed nothing — it did not sync, attach or cost a
 * quota slot. Only the binding ever did.
 */
export interface AccountRow {
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
  is_linked: boolean
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

export type LinkFilter = 'linked' | 'unlinked'

export interface AccountsSummary {
  linked: number
  unlinked: number
  total: number
}

export interface AccountsPage {
  accounts: AccountRow[]
  /** Counts the WHOLE inventory, deliberately unaffected by the filters that cut the list. */
  summary: AccountsSummary
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

export interface AccountsQuery {
  provider?: string
  connection?: string
  account_type?: 'ad_account' | 'store'
  link?: LinkFilter
  q?: string
  page?: number
  per_page?: number
}

export function listAccounts(query: AccountsQuery = {}): Promise<AccountsPage> {
  const params = new URLSearchParams()
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  })

  return getData<AccountsPage>(`/accounts?${params.toString()}`)
}

/** One run as `MetricSyncRun::logRow()` states it — see `CampaignSyncRun`, which is the same shape. */
export interface AccountSyncRun {
  id: string
  provider: string
  status: string
  trigger: 'automatic' | 'manual' | 'backfill'
  window_start: string | null
  window_end: string | null
  provider_rows: number | null
  parsed_rows: number | null
  mapped_rows: number | null
  metrics_imported: number
  duration_seconds: number | null
  attempts: number
  started_at: string | null
  finished_at: string | null
  error: string | null
  /** Consecutive identical runs this row stands for — see `CampaignSyncRun`. */
  repeats: number
  repeats_since: string | null
}

export function getAccountLogs(id: string): Promise<{ account: AccountRow; runs: AccountSyncRun[] }> {
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
