import { deleteData, getData, patchData, postData, putData } from '@/lib/api/client'

/**
 * The platform owner's console (ADMIN-001).
 *
 * Note what these types do NOT carry: no campaign, client or report payloads. Owning the platform is
 * not a reason to read a customer's work, and a console that made it effortless would see it happen
 * without anyone deciding to. The owner's job is tenants, access, plans and the audit trail.
 */

export interface PlatformOverview {
  tenants: {
    total: number
    active: number
    suspended: number
    by_account_type: Record<string, number>
    by_plan: Record<string, number>
  }
  people: {
    users: number
    platform_admins: number
    memberships: number
    /** A growing number here means a grant path is dropping people — see BUG-INVITE-001. */
    without_membership: number
  }
  workload: { client_workspaces: number; open_requests: number; unpaid_invoices: number }
  /** Tenants opened per month, twelve months back. Empty months are PRESENT, as zeros. */
  growth: Array<{ month: string; opened: number; total: number }>
  subscriptions: {
    by_status: Record<string, number>
    /**
     * What active and trialing subscriptions are worth per month — what the platform has been
     * PROMISED, not what it has collected. `collection_status` says which.
     */
    committed_monthly: Array<{ currency: string; monthly: number; subscriptions: number }>
    collection_status: string
  }
  /** Counts worth acting on, each with the page that answers it. Zeros included on purpose. */
  attention: Array<{ key: string; count: number; to: string; tone: 'warning' | 'danger' | 'info' }>
}

export interface PlatformTenant {
  id: string
  name: string
  slug: string
  status: string
  account_type: string | null
  subscription_plan: string | null
  onboarding_completed: boolean
  people: number
  client_workspaces: number
  created_at: string | null
}

export interface TenantPerson {
  user_id: string
  name: string | null
  email: string | null
  portal: string
  role: string
  status: string
}

/** The four OPS-002 names, or null for everything else the platform records. */
export type AuditCategory = 'subscriptions' | 'payments' | 'approvals' | 'permissions'

export interface AuditEntry {
  id: string
  action: string
  category: AuditCategory | null
  tenant_id: string | null
  /** Null when the workspace has since been deleted — never «Unknown», which reads as a name. */
  tenant_name: string | null
  user_id: string | null
  user_name: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason: string | null
  created_at: string | null
}

export function fetchOverview(): Promise<PlatformOverview> {
  return getData<PlatformOverview>('/admin/overview')
}

export function fetchTenants(params: { q?: string; status?: string } = {}): Promise<{ tenants: PlatformTenant[]; meta: { total: number } }> {
  const qs = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
  ).toString()

  return getData(`/admin/tenants${qs ? `?${qs}` : ''}`)
}

export function fetchTenant(id: string): Promise<{ tenant: PlatformTenant & { is_default_portal: boolean }; people: TenantPerson[]; client_workspaces: number }> {
  return getData(`/admin/tenants/${id}`)
}

/**
 * Suspending locks every person whose only workspace is this one out of the product, so the reason
 * is required by the server rather than optional here — an audit entry with no reason explains
 * nothing to whoever reads it a year later.
 */
export function setTenantStatus(id: string, status: 'active' | 'suspended', reason?: string): Promise<{
  tenant: { id: string; status: string }
  public_intake_affected: boolean
}> {
  return patchData(`/admin/tenants/${id}/status`, { status, reason })
}

export function fetchAudit(category?: AuditCategory | ''): Promise<{
  entries: AuditEntry[]
  categories: AuditCategory[]
  meta: { total: number }
}> {
  return getData(`/admin/audit${category ? `?category=${encodeURIComponent(category)}` : ''}`)
}

/* ------------------------------------------------------------------ ADMIN-002: plans & billing */

export interface PlatformPlan {
  id: string
  code: string
  name: string
  name_ar: string | null
  currency: string
  price_monthly: string
  /** Null is a statement: this plan is not sold on an annual term. */
  price_annual: string | null
  /** The introductory month: what it costs and how long it runs. 0 days means no offer. */
  trial_fee: string
  trial_days: number
  /** How many months the introductory price is bought with. 0 is «cancel whenever you like». */
  minimum_commitment_months: number
  is_active: boolean
  is_public: boolean
  /** Sold by conversation: no published price, and nothing checks out on it. */
  contact_sales: boolean
  features: Record<string, unknown> | unknown[]
  limits: Record<string, unknown> | unknown[]
  /** Split by status: 40 cancelled subscribers is not 40 customers. */
  subscribers: { active: number; total: number }
}

export interface PlatformSubscription {
  id: string
  tenant_id: string
  tenant_name: string | null
  plan: string | null
  plan_code: string | null
  status: string
  seats: number | null
  current_period_end: string | null
}

export interface PlatformRevenue {
  /** Per currency, never blended. */
  committed_monthly: { currency: string; monthly: string; subscriptions: number }[]
  /**
   * `not_implemented` — CampaignsHub has no charging path for tenants yet, and the invoices/payments
   * ledger belongs to agencies invoicing THEIR clients. The figure above is a forward commitment,
   * never cash received, and the UI must say so.
   */
  collection_status: string
  note: string
}

export function fetchPlans(): Promise<{ plans: PlatformPlan[] }> {
  return getData('/admin/plans')
}

/**
 * The commercial terms of a plan.
 *
 * Editing a price changes what NEW customers are quoted and nothing else: a subscription captured
 * its own `unit_amount` when it was assigned, and every renewal reads that. `price_annual: null` is
 * how a plan is withdrawn from the annual term, which is why it is nullable rather than optional.
 */
export function updatePlan(
  id: string,
  body: {
    name?: string
    is_active?: boolean
    is_public?: boolean
    contact_sales?: boolean
    price_monthly?: string
    price_annual?: string | null
    /*
     * The offer and what stands behind it — SUB-COMMIT-001.
     *
     * Three numbers that are ONE commercial decision: the discount is what the commitment buys. They
     * are sent together for the same reason they are shown together, so an operator cannot lengthen
     * a commitment and forget the price it was supposed to justify.
     */
    trial_fee?: string
    trial_days?: number
    minimum_commitment_months?: number
    /** A null value is «unlimited» — an absent key would leave the old cap in place. */
    limits?: Record<string, number | null>
    features?: Record<string, unknown>
    /** Why the commercial terms changed — recorded on the audit entry, never on the plan. */
    reason?: string
  },
): Promise<{ plan: PlatformPlan }> {
  return patchData(`/admin/plans/${id}`, body)
}

/* ------------------------------------------------------------------- GRANT-001: account grants */

export type GrantKind = 'section' | 'module' | 'plan' | 'full_access'

export interface AccountGrant {
  id: string
  tenant_id: string
  kind: GrantKind
  value: string
  reason: string
  granted_by: number | null
  granted_at: string | null
  expires_at: string | null
  revoked_at: string | null
  revoked_by: number | null
  revoked_reason: string | null
  in_force: boolean
}

export interface GrantCatalogue {
  sections: string[]
  modules: string[]
  plans: string[]
}

export function fetchGrants(tenantId: string): Promise<{ grants: AccountGrant[]; catalogue: GrantCatalogue }> {
  return getData(`/admin/tenants/${tenantId}/grants`)
}

export function createGrant(
  tenantId: string,
  body: { kind: GrantKind; value?: string; reason: string; expires_at?: string | null },
): Promise<{ grant: AccountGrant }> {
  return postData(`/admin/tenants/${tenantId}/grants`, body)
}

/** A revocation, not a deletion: the row survives and records who took it back, and why. */
export function revokeGrant(tenantId: string, grantId: string, reason: string): Promise<{ grant: AccountGrant }> {
  return deleteData(`/admin/tenants/${tenantId}/grants/${grantId}`, { reason })
}

export function fetchSubscriptions(status?: string): Promise<{ subscriptions: PlatformSubscription[]; meta: { total: number } }> {
  return getData(`/admin/subscriptions${status ? `?status=${encodeURIComponent(status)}` : ''}`)
}

export function fetchRevenue(): Promise<PlatformRevenue> {
  return getData('/admin/revenue')
}

/**
 * PAY-005 — the four streams money moves through, and only one of them is the platform's.
 *
 * `belongs_to` and `subset_of` are the load-bearing fields. Without the first, an owner reads an
 * agency's client invoices as their own business result; without the second, they add request
 * payments to agency invoices and count the same invoice twice.
 */
export interface RevenueStream {
  key: 'platform_subscriptions' | 'agency_client_invoices' | 'request_service_payments' | 'creator_payouts'
  direction: string
  belongs_to: 'platform' | 'tenant'
  basis: string | null
  /** Per currency, never blended. Empty means «nothing measured», not «zero money». */
  amounts: Array<{ currency: string; monthly?: number; invoiced?: number; collected?: number; subscriptions?: number; invoices?: number }>
  /** Present only on a stream that is a filtered VIEW of another — adding the two double-counts. */
  subset_of?: string
  status: 'live' | 'awaiting_credentials' | 'not_implemented'
  note: string
}

export interface RevenueStreams {
  streams: RevenueStream[]
  /** Always null. The reason travels with it so the refusal is explicit, not an omission. */
  combined_total: null
  combined_total_reason: string
}

export function fetchRevenueStreams(): Promise<RevenueStreams> {
  return getData('/admin/revenue-streams')
}

/* ------------------------------------------------------- ADMIN-003: access, integrations, status */

export interface PermissionCatalogue {
  groups: {
    group: string
    permissions: { id: string; key: string; description: string | null; granted_by_roles: number }[]
  }[]
  total: number
  roles: number
  /** Always false. The catalogue is code — a key invented at runtime would grant nothing. */
  editable: boolean
  note: string
}

export interface PlatformIntegrations {
  /** States are counted verbatim, never collapsed into connected/not-connected. */
  providers: { provider: string; tenants: number; by_status: Record<string, number> }[]
  note: string
}

export interface PlatformStatus {
  backend: { state: string }
  database: { state: string; connection?: string }
  redis?: { state: string }
  queue_worker?: { state: string }
  scheduler?: { state: string }
  storage?: { state: string }
  last_migration?: string | null
  branch?: string | null
  commit?: string | null
  [key: string]: unknown
}

export function fetchPermissions(): Promise<PermissionCatalogue> {
  return getData('/admin/permissions')
}

export function fetchIntegrations(): Promise<PlatformIntegrations> {
  return getData('/admin/integrations')
}

export function fetchStatus(): Promise<PlatformStatus> {
  return getData('/admin/status')
}

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — what the schedulers did, and whether anybody can see them.
 *
 * `state` is the one field a reader must not skim past. `never_observed` means no run of this
 * command has ever been recorded — which is NOT «it is fine», and not «it failed» either. It is «we
 * cannot see», and it calls for a different action from both.
 */
export interface ScheduledWorkRow {
  command: string
  expression: string
  state: 'never_observed' | 'observed'
  last_outcome: 'completed' | 'failed' | 'skipped' | null
  last_started_at: string | null
  last_duration_ms: number | null
  failure_class: string | null
  failure_message: string | null
  /** Null when there is no history to judge against — rendered as its own thing, never as «fine». */
  overdue: boolean | null
  consecutive_failures: number
}

export interface ScheduledWork {
  scheduled: ScheduledWorkRow[]
  summary: {
    total: number
    failing: number
    overdue: number
    never_observed: number
    /** Counted apart from `failing`: failed once and failing every night are different problems. */
    failing_repeatedly: number
  }
}

export function fetchScheduledWork(): Promise<ScheduledWork> {
  return getData('/admin/scheduled-work')
}

/* --------------------------------------- PORTAL-AUTH-001: cutover readiness & conflicts */

export interface PortalConflict {
  id: string
  tenant_id: string
  tenant_name: string | null
  contact_email: string | null
  contact_phone: string | null
  reason: string
  /** What they WOULD be granted — shown so the resolver sees the consequence before choosing. */
  client_ids: string[]
  resolution: string | null
  note: string | null
  resolved_at: string | null
}

export interface CutoverReadiness {
  /** A MEASUREMENT of three conditions, never a judgement that it "looks done". */
  ready: boolean
  blockers: string[]
  open_conflicts: number
  legacy_sessions: number
  legacy_holders: {
    contact: string
    expires_at: string | null
    last_used_at: string | null
    /** With one, they upgrade on next sign-in. Without, a conflict must be resolved first. */
    has_membership: boolean
  }[]
  parity: {
    checked: number
    mismatched: number
    /** Named, with both sides — "3 disagreements" tells nobody whose portal would change. */
    mismatches: { contact: string; membership: string[]; token: string[] }[]
  }
  last_checked_at: string | null
}

export function fetchCutoverReadiness(): Promise<CutoverReadiness> {
  return getData('/admin/cutover-readiness')
}

export function fetchPortalConflicts(openOnly = true): Promise<{
  conflicts: PortalConflict[]
  open: number
  safe_to_retire_legacy_engine: boolean
}> {
  return getData(`/admin/portal-conflicts?open_only=${openOnly ? 1 : 0}`)
}

/** `link` needs a reason; `separate` grants nothing. There is deliberately no bulk resolve. */
export function resolvePortalConflict(
  id: string,
  resolution: 'link' | 'separate' | 'dismiss',
  note?: string,
): Promise<{ conflict: { id: string; resolution: string } }> {
  return patchData(`/admin/portal-conflicts/${id}`, { resolution, note })
}

/*
 * The registration review queue (SIGNUP-003).
 *
 * The gated path's other end. Note what none of these calls can do: activate an account. `approve`
 * clears the approval gate, and an application that also owes money stays at
 * `approved_awaiting_payment` — the server decides that, not this module.
 */

export interface AdminRegistration {
  id: string
  state: string
  /** Already translated by the server; the console does not own the vocabulary of account states. */
  label: string
  email: string
  name: string
  tenant_name: string
  account_type: string | null
  phone: string | null
  requested_portal: string | null
  plan_code: string | null
  email_verified: boolean
  mobile_verified: boolean
  next_step: string | null
  reason: string | null
  provisioned: boolean
  review_note: string | null
  info_requested: boolean
  reviewed_at: string | null
  reviewed_by: number | null
  concessions: Record<string, unknown> | null
  created_at: string | null
  tenant_id: string | null
}

export interface RegistrationGates {
  requires_mobile: boolean
  requires_approval: boolean
  requires_payment: boolean
}

/** One recorded decision. The audit trail, read back — never a second log kept for the screen. */
export interface RegistrationTransition {
  action: string
  at: string | null
  user_id: number | null
  reason: string | null
  detail: Record<string, unknown> | null
}

export function fetchRegistrations(params: { state?: string; q?: string } = {}): Promise<{
  registrations: AdminRegistration[]
  meta: { total: number; per_page: number; current_page: number }
  counts: Record<string, number>
}> {
  const query = new URLSearchParams()
  if (params.state) query.set('state', params.state)
  if (params.q) query.set('q', params.q)
  return getData(`/admin/registrations?${query.toString()}`)
}

export function fetchRegistration(id: string): Promise<{
  registration: AdminRegistration
  policy: RegistrationGates
  transitions: RegistrationTransition[]
}> {
  return getData(`/admin/registrations/${id}`)
}

export function approveRegistration(id: string, note?: string): Promise<{ registration: AdminRegistration }> {
  return postData(`/admin/registrations/${id}/approve`, { note })
}

/** A reason is required — the applicant is shown it, and "rejected" alone is a dead end. */
export function rejectRegistration(id: string, reason: string): Promise<{ registration: AdminRegistration }> {
  return postData(`/admin/registrations/${id}/reject`, { reason })
}

export function requestRegistrationInfo(id: string, note: string): Promise<{ registration: AdminRegistration }> {
  return postData(`/admin/registrations/${id}/request-info`, { note })
}

export interface RegistrationTerms {
  plan_code?: string | null
  requires_mobile?: boolean
  requires_approval?: boolean
  requires_payment?: boolean
  discount_percent?: number | null
  trial_days?: number | null
  reason: string
}

export function updateRegistrationTerms(id: string, terms: RegistrationTerms): Promise<{
  registration: AdminRegistration
  policy: RegistrationGates
}> {
  return patchData(`/admin/registrations/${id}`, terms)
}

/*
 * The payment gateways, from the console (PAYSET-001).
 *
 * Read and test only. There is deliberately no function here that writes a secret: a console able to
 * change a gateway key is a console whose compromise redirects every customer payment. Keys live in
 * the environment, and this surface reports what the environment supports.
 */

export interface PaymentProviderSetting {
  provider: 'moyasar' | 'stripe' | string
  label: { ar: string; en: string }
  /** `primary` or `alternative` — a product decision, not a consequence of which keys exist. */
  role: string
  is_default: boolean
  /** `live` or `awaiting_credentials`. */
  status: string
  available: boolean
  /** `sandbox`, `live` or `unset` — read from the KEY, never from a separate toggle. */
  environment: string
  requires: { key: string; present: boolean }[]
  webhook_url: string
}

export interface PaymentSettings {
  default: string
  currency: string
  providers: PaymentProviderSetting[]
  /** A payment system that cannot tell anybody a charge failed is only half configured. */
  mail: { state: string; driver: string }
  /**
   * Whether renewals take themselves — PAY-TOKEN-003.
   *
   * `ready` is about the GATEWAY; `saved_methods` is how many customers actually have a card. Kept
   * apart because «ready» beside a count of zero is the true state of a fresh install, and one
   * boolean would have hidden it either way round.
   */
  recurring: { ready: boolean; provider: string; reason: string; saved_methods: number }
}

export function fetchPaymentSettings(): Promise<PaymentSettings> {
  return getData('/admin/settings/integrations/payments')
}

export function fetchPaymentWebhook(provider: string): Promise<{
  provider: string
  url: string
  authentication: string
  events: string[]
}> {
  return getData(`/admin/settings/integrations/payments/${provider}/webhook`)
}

export function fetchSecretRotation(provider: string): Promise<{
  provider: string
  variables: string[]
  steps: string[]
  note: string
}> {
  return getData(`/admin/settings/integrations/payments/${provider}/rotation`)
}

/** A real round trip to the gateway. Nothing is charged — a session is an intent that expires unused. */
export function testPaymentProvider(provider: string): Promise<{
  provider: string
  reachable: boolean
  status: string
  error: string | null
}> {
  return postData(`/admin/settings/integrations/payments/${provider}/test`, {})
}

/*
 * PROVCFG-001 — the ad and commerce providers, configured by the platform operator.
 *
 * Unlike the payment gateways above, these ARE written from the console. Note what still cannot be
 * read: there is no field on any of these types that carries a stored value. `present`, `source` and
 * a four-character `hint` are the whole of what comes back, for the platform owner as much as for
 * anybody else — a console that could display a client secret is a console whose compromise hands
 * over every customer's ad accounts.
 */

export interface ProviderFieldSpec {
  key: string
  label: string
  label_ar: string
  /** Decides masking AND storage. A field marked non-secret is echoed back in full. */
  secret: boolean
  required: boolean
  /** Which screen of the PROVIDER's console holds this value. */
  where: string
  where_ar: string
}

/** Presence, provenance and a hint. Never a value. */
export interface ProviderFieldState {
  key: string
  present: boolean
  /** `stored` (entered here) or `environment` (a .env fallback nobody typed into this console). */
  source: 'stored' | 'environment' | null
  hint: string | null
}

export type ProviderSetupState =
  | 'not_configured'
  | 'awaiting_credentials'
  | 'ready_to_connect'
  | 'configuration_error'
  | 'production_ready'

export interface IntegrationProvider {
  key: string
  kind: 'advertising' | 'commerce'
  label: string
  label_ar: string
  fields: ProviderFieldSpec[]
  scopes: string[]
  effective_scopes: string[]
  uses_pkce: boolean
  supports_refresh: boolean
  token_note: string
  token_note_ar: string
  /** `supported` · `polling_only` · `requires_confirmation` — three different facts, never a boolean. */
  webhooks: 'supported' | 'polling_only' | 'requires_confirmation'
  webhook_signature_header: string | null
  webhook_url: string | null
  redirect_uri: string
  /** What the operator must obtain OUTSIDE this product before a correct key can work. */
  prerequisites: string[]
  prerequisites_ar: string[]
  docs_url: string
  rate_limit_note: string
  pagination_note: string
  state: ProviderSetupState
  enabled: boolean
  environment: 'sandbox' | 'production'
  missing: string[]
  /** `state` is about the configuration; this is whether a workspace may be offered the button. */
  connectable: boolean
  values: ProviderFieldState[]
  last_tested_at: string | null
  last_test_status: 'passed' | 'failed' | null
  last_test_message: string | null
  last_rotated_at: string | null
  configured_at: string | null
}

export function fetchIntegrationProviders(): Promise<{
  providers: IntegrationProvider[]
  summary: { total: number; connectable: number; needs_attention: number }
}> {
  return getData('/admin/settings/integrations/providers')
}

/**
 * Partial by design: an omitted or empty field is left alone, which is what lets an operator change
 * the environment without re-typing a secret they cannot read back.
 */
export function saveIntegrationProvider(
  provider: string,
  body: Record<string, string | string[] | undefined>,
): Promise<IntegrationProvider & { fields_changed: string[] }> {
  return putData(`/admin/settings/integrations/providers/${provider}`, body)
}

/** A real round trip. A pass proves the client id and secret — not scopes, and not account access. */
export function testIntegrationProvider(provider: string): Promise<
  IntegrationProvider & { passed: boolean; message: string }
> {
  return postData(`/admin/settings/integrations/providers/${provider}/test`, {})
}

export function rotateIntegrationCredential(provider: string, key: string, value: string): Promise<IntegrationProvider> {
  return postData(`/admin/settings/integrations/providers/${provider}/rotate`, { key, value })
}

/** Disabling stops new work and destroys nothing — no credential, connection or synced figure. */
export function setIntegrationProviderEnabled(
  provider: string,
  enabled: boolean,
  reason?: string,
): Promise<IntegrationProvider> {
  return patchData(`/admin/settings/integrations/providers/${provider}/status`, { enabled, reason })
}

export function forgetIntegrationCredential(provider: string, key: string): Promise<IntegrationProvider> {
  return deleteData(`/admin/settings/integrations/providers/${provider}/credentials/${key}`)
}

// ---- Email operations (MAIL-014) -----------------------------------------------------------------

export interface EmailDelivery {
  id: string
  /** `transactional` (mail_deliveries) or `digest` (digest_sends) — two ledgers, one question. */
  source: 'transactional' | 'digest'
  at: string
  kind: string
  template: string
  recipient: string | null
  tenant_name: string | null
  locale: string | null
  status: string
  transport: string | null
  attempts: number
  reason: string | null
}

export interface EmailLedger {
  deliveries: EmailDelivery[]
  total: number
  page: number
  per_page: number
  by_state: Record<string, number>
  transport: { state: 'awaiting_credentials' | 'sandbox' | 'live'; provider_configured: boolean; driver: string }
  available_states: string[]
}

export function fetchEmailLedger(params: {
  status?: string
  kind?: string
  recipient?: string
  source?: string
  days?: number
  page?: number
}): Promise<EmailLedger> {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== '' && v !== undefined) q.set(k, String(v))
  }
  return getData<EmailLedger>(`/admin/email/deliveries${q.size > 0 ? `?${q}` : ''}`)
}

export function fetchEmailPreviews(): Promise<{ keys: string[]; locales: string[] }> {
  return getData('/admin/email/previews')
}

export function fetchEmailPreview(key: string, locale: string): Promise<{ key: string; locale: string; html: string }> {
  return getData(`/admin/email/previews/${key}?locale=${locale}`)
}

/*
 * FX-FEED-001 — where exchange rates come from.
 *
 * The ENGINE is verified: money is converted into the project's reporting currency at ingest, at a
 * dated rate, from a named source, and a rate nobody can vouch for withholds the figure rather than
 * guessing one. The FEED is the other half, and on a fresh install it does not exist — no publisher
 * is chosen in this repository, because which one a deployment trusts is a commercial decision.
 *
 * `unmet_pairs` is what makes that decision concrete: every conversion already withheld, worst first.
 */
export interface CurrencyRateFeedState {
  /** `awaiting_configuration` · `driver_not_configured` · `ready` */
  state: string
  driver: string | null
  label: string | null
  stale_after_days: number
  last_rate_date: string | null
  rates: number
}

export interface UnmetRatePair {
  base: string
  quote: string
  withheld: number
  earliest: string | null
  latest: string | null
  sources: string[]
}

export interface StoredRate {
  base: string
  quote: string
  rate: number
  rate_date: string
  source: string
}

export function fetchCurrencyRates(): Promise<{
  feed: CurrencyRateFeedState
  unmet_pairs: UnmetRatePair[]
  rates: StoredRate[]
}> {
  return getData('/admin/fx/rates')
}

/** Hand entry is a first-class path: an operator is a real source, and the rate records who they are. */
export function recordCurrencyRate(body: {
  base: string
  quote: string
  rate: number
  rate_date: string
}): Promise<StoredRate> {
  return postData('/admin/fx/rates', body)
}
