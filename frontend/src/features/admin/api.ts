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
  /** SUPERADMIN-CONSOLE-HOME-001 — what needs the operator across every workspace. Names only; never a provider's error text. */
  operations: {
    connections: {
      total: number
      items: Array<{
        id: string
        tenant_id: string
        tenant_name: string | null
        provider: string
        connection_name: string | null
        /** refused: the provider invalidated the session; expired: the token passed its expiry. */
        reason: 'refused' | 'expired'
        since: string | null
      }>
    }
    alerts: { open: number; by_severity: { critical: number; warning: number; info: number }; tenants: number }
    recent: Array<{ id: string; action: string; tenant_name: string | null; user_name: string | null; created_at: string | null }>
  }
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
  /** SUPERADMIN-ACCESS-001 — the key the access actions take. */
  user_uuid: string | null
  mfa_enabled: boolean
  name: string | null
  email: string | null
  portal: string
  role: string
  /** SUPERADMIN-MEMBERS-001 — the roles this person holds in THIS workspace. */
  roles?: string[]
  is_platform_admin?: boolean
  status: string
  /** ADMIN-FULL-CONTROL-001 — the account itself, separate from this membership's status. */
  disabled?: boolean
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

/** SUPERADMIN-LIMITS-001 — one workspace's plan limits: the plan's, its own override, what is used and what applies. */
export interface TenantLimit {
  metric: string
  plan_limit: number | null
  override: { limit: number | null; expires_at: string | null; reason: string | null } | null
  lapsed: boolean
  effective_limit: number | null
  usage: number
}

export function fetchTenantLimits(tenantId: string): Promise<{ limits: TenantLimit[] }> {
  return getData(`/admin/tenants/${tenantId}/limits`)
}

export function setTenantLimit(tenantId: string, metric: string, body: { mode: 'cap' | 'unlimited' | 'inherit'; limit?: number; expires_at?: string | null; reason: string }): Promise<{ limits: TenantLimit[] }> {
  return putData(`/admin/tenants/${tenantId}/limits/${metric}`, body)
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

export function fetchTenant(id: string): Promise<{
  tenant: PlatformTenant & { is_default_portal: boolean }
  people: TenantPerson[]
  client_workspaces: number
  /** SUPERADMIN-MEMBERS-001 — the workspace's own roles. */
  available_roles?: Array<{ slug: string; name: string }>
  /** ADMIN-FULL-CONTROL-001 — asked and not answered yet. Never the token. */
  pending_invitations?: PendingInvitation[]
}> {
  return getData(`/admin/tenants/${id}`)
}

/**
 * Either direction decides who can get in, so the reason is required by the server rather than optional here — an audit
 * entry with no reason explains nothing to whoever reads it a year later.
 */
export function setTenantStatus(id: string, status: 'active' | 'suspended', reason: string): Promise<{
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
  /** When the scheduler will run it next, computed from its own expression. Null if unparseable. */
  next_run_at: string | null
  /** Rows the last run touched. Null when the command does not count what it does — not zero. */
  last_rows_affected: number | null
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
  /** `not_required_by_policy` means nobody asked, never that it was proved. Absent on an older payload. */
  verification?: { email: 'verified' | 'required' | 'not_required_by_policy'; mobile: 'verified' | 'required' | 'not_required_by_policy' }
  decisions?: Array<{
    id: string
    outcome: string
    status: string
    actor_id: number | null
    reason: string
    terms: (RegistrationDecisionTerms & { evidence?: Record<string, string> }) | null
    access: Record<string, unknown> | null
    verified_by: number | null
    created_at: string | null
  }>
  effective_policy?: { switches?: { email?: boolean; mobile?: boolean }; settings_version?: number; taken_at?: string } | null
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
  /** PLATFORM-INBOX-ATTENTION-001 — the verification step it is held at, or null when it is not held at one. */
  verification_stage?: {
    channel: 'email' | 'mobile'
    since: string | null
    sends: number
    last_sent_at: string | null
    last_outcome: string | null
  } | null
}

export interface RegistrationGates {
  requires_email?: boolean
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

export function approveRegistration(id: string, note?: string): Promise<{ registration: AdminRegistration; no_change?: boolean }> {
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
 * SIGNUP-DECISION-001 — the one decision. The server computes every figure; what is sent is the choice, and the amount the
 * reviewer was shown so the server can refuse if it has moved.
 */
export type RegistrationDecisionOutcome =
  | 'approve_payment_required' | 'approve_discount' | 'approve_waiver' | 'approve_trial'
  | 'approve_external_settlement' | 'approve_join_existing' | 'approve_complimentary'

export interface RegistrationDecisionInput {
  outcome: RegistrationDecisionOutcome | 'request_info' | 'defer' | 'reject'
  reason: string
  idempotency_key?: string
  expected_decision_id?: string | null
  discount_percent?: number
  trial_ends_on?: string
  confirm_waiver?: boolean
  confirm_amount?: string | null
  confirm_complimentary?: boolean
  evidence?: { reference: string; amount: string; currency: string; paid_on: string; note?: string }
  access?: {
    mode: 'new_workspace' | 'join_existing'
    portal?: string
    modules?: string[]
    tenant_id?: string
    role?: string
    project_ids?: string[]
    client_ids?: string[]
  }
}

export interface RegistrationDecisionTerms {
  list_amount?: string
  amount_due?: string
  fee?: string
  currency?: string
  renewal_amount?: string
  renewal_on?: string
  discount_percent?: number
  vat?: { treatment: string; rate: number; tax: string }
  covered_by?: string
  /** ADMIN-FULL-CONTROL-001 — open-ended direct entry: no period, no renewal and nothing due. */
  complimentary?: boolean
  scope?: string
}

export interface RegistrationDecisionPreview {
  outcome: string
  terms: RegistrationDecisionTerms | null
  access: {
    mode: string
    portal?: string | null
    role: string
    modules?: string[] | null
    permissions: 'all_in_own_workspace' | string[]
    projects?: string[]
    clients?: string[]
    creates: { tenant: number; workspace: number; membership: number; project: number }
    who_has_access: string[]
  }
  payment_provider_ready: boolean
}

export function previewRegistrationDecision(id: string, input: RegistrationDecisionInput): Promise<RegistrationDecisionPreview> {
  return postData(`/admin/registrations/${id}/decision/preview`, input)
}

export function decideRegistration(id: string, input: RegistrationDecisionInput): Promise<{ registration: AdminRegistration; no_change?: boolean }> {
  return postData(`/admin/registrations/${id}/decision`, input)
}

export function verifyRegistrationSettlement(id: string, decisionId: string, reason: string): Promise<{ registration: AdminRegistration }> {
  return postData(`/admin/registrations/${id}/decisions/${decisionId}/verify-settlement`, { reason })
}

export function fetchTenantDecisionTargets(tenantId: string): Promise<{
  roles: Array<{ slug: string; name: string }>
  projects: Array<{ id: string; name: string }>
  clients: Array<{ id: string; name: string }>
}> {
  return getData(`/admin/tenants/${tenantId}/decision-targets`)
}

/** SIGNUP-VERIFY-POLICY-001 — move one application to the current verification policy, with a reason. */
export function reevaluateRegistrationVerification(id: string, reason: string): Promise<{
  registration: AdminRegistration
  policy: RegistrationGates
}> {
  return postData(`/admin/registrations/${id}/reevaluate-verification`, { reason })
}

/**
 * PLATFORM-INBOX-ATTENTION-001 — a fresh challenge on the step the application is held at. The server sends it and
 * returns only the outcome: the code or link is the applicant's, and nothing here verifies anything.
 */
export function resendRegistrationVerification(id: string): Promise<{
  registration: AdminRegistration
  verification: { channel: 'email' | 'mobile'; delivery_status: string; expires_at: string | null }
}> {
  return postData(`/admin/registrations/${id}/resend-verification`, {})
}

export interface RegistrationVerificationPolicy {
  email_enabled: boolean
  mobile_enabled: boolean
  email_daily_cap: number | null
  sms_daily_cap: number | null
  per_application_daily_cap: number | null
  email_unit_cost: string | null
  sms_unit_cost: string | null
  cost_currency: string | null
  budget_alert_percent: number
  unverified_signups_daily_cap: number
  version: number
}

export interface RegistrationVerificationChannel {
  gate: 'enabled' | 'disabled'
  /** A separate question from the gate: a disabled gate is a decision, a missing provider is a fault. */
  provider: 'configured' | 'not_configured'
  last_24h: { attempted: number; delivered: number; blocked: number }
  delivered_30d: number
  cap: number | null
  alert: 'ok' | 'near_cap' | 'at_cap' | 'no_cap'
  unit_cost: string | null
  currency: string | null
  /** Null means unknown, because no rate was entered. Never read it as zero. */
  estimated_cost_30d: string | null
  cost_known: boolean
}

export interface RegistrationVerificationOverview {
  policy: RegistrationVerificationPolicy
  channels: { email: RegistrationVerificationChannel; mobile: RegistrationVerificationChannel }
  open_applications: { at_email_gate: number; at_mobile_gate: number }
  unverified_signups_last_24h: number
  counted_since: string | null
  changes: Array<{
    at: string | null
    user_id: number | null
    reason: string | null
    before: Partial<RegistrationVerificationPolicy> | null
    after: Partial<RegistrationVerificationPolicy> | null
  }>
}

export function fetchRegistrationVerificationPolicy(): Promise<RegistrationVerificationOverview> {
  return getData('/admin/registration-verification-policy')
}

export function saveRegistrationVerificationPolicy(
  policy: Omit<RegistrationVerificationPolicy, 'version'> & { reason: string; expected_version: number; confirm_risk: boolean },
): Promise<RegistrationVerificationOverview> {
  return putData('/admin/registration-verification-policy', policy)
}

/* AUTH-CODE-SWITCHES-001 — one-time codes at sign-in and recovery. Password, Google and the reset link are not among them. */

export type AuthCodeSwitchKey = 'sign_in_sms_code' | 'portal_email_code' | 'portal_sms_code' | 'recovery_sms_code'

export interface AuthCodeChannel {
  enabled: boolean
  provider: 'configured' | 'not_configured' | 'failing'
  last_24h: { issued: number; sent: number }
  sent_30d: number
  unit_cost: string | null
  currency: string | null
  estimated_cost_30d: string | null
  cost_known: boolean
}

export interface AuthCodeSwitchesOverview {
  switches: Record<AuthCodeSwitchKey, boolean> & { version: number }
  channels: Record<AuthCodeSwitchKey, AuthCodeChannel>
  /** `PORTAL_CODE_ON_LOGIN`, read-only: whether a contact can sign in by code at all. */
  portal_code_on_login: boolean
  recovery_link: { available: boolean; switchable: boolean; open_question: string }
  limits: { code_ttl_minutes: number; max_attempts: number; resend_cooldown_seconds: number }
  lockout: { stranded_contacts: number; accounts_only_sms_back: number }
  counted_since: string | null
  changes: Array<{
    at: string | null
    user_id: number | null
    reason: string | null
    before: Partial<Record<AuthCodeSwitchKey, boolean>> | null
    after: Partial<Record<AuthCodeSwitchKey, boolean>> | null
  }>
}

export function fetchAuthCodeSwitches(): Promise<AuthCodeSwitchesOverview> {
  return getData('/admin/auth-code-switches')
}

export function saveAuthCodeSwitches(
  body: Record<AuthCodeSwitchKey, boolean> & { reason: string; expected_version: number; confirm_risk: boolean },
): Promise<AuthCodeSwitchesOverview> {
  return putData('/admin/auth-code-switches', body)
}

/*
 * The payment gateways, from the console (PAYSET-001, PAY-GATEWAY-CONSOLE-001).
 *
 * Keys are entered here and stored encrypted by the server; nothing ever returns a secret. Saving keys, testing, enabling (live
 * confirmed separately) and choosing the primary are separate calls, each audited.
 */

export interface PaymentGatewayField {
  key: string
  label: { ar: string; en: string }
  secret: boolean
  required: boolean
  present: boolean
  /** Only for a non-secret field (a publishable key). A secret is never returned. */
  value: string | null
}

export interface PaymentProviderSetting {
  provider: string
  label: { ar: string; en: string }
  /** `primary` or `alternative` — a product decision, not a consequence of which keys exist. */
  role: string
  is_default: boolean
  is_primary: boolean
  implemented: boolean
  /** `not_implemented`, `awaiting_credentials`, `needs_test`, `ready` (tested, disabled) or `enabled`. */
  status: string
  available: boolean
  enabled: boolean
  mode: 'test' | 'live' | null
  /** `sandbox`, `live` or `unset`. */
  environment: string
  /** Where the effective keys come from: `console`, `environment` or `none`. */
  source: string
  fields: PaymentGatewayField[]
  requires: { key: string; present: boolean }[]
  test: { status: string | null; at: string | null; message: string | null; current: boolean }
  last_rotated_at: string | null
  webhook_url: string
  webhook: { scheme: string; header: string | null; events: string[] }
  capabilities: { hosted_checkout: boolean; status_lookup: boolean; refund: boolean; recurring: boolean; webhook: boolean }
  methods: string[]
  docs: string
  /** Why a gateway is not implemented, when it is not. */
  note: { ar: string; en: string } | null
}

export interface PaymentSettings {
  default: string
  primary: string
  primary_source: 'console' | 'environment'
  currency: string
  providers: PaymentProviderSetting[]
  /** A payment system that cannot tell anybody a charge failed is only half configured. */
  mail: { state: string; driver: string }
  /** Whether renewals take themselves — PAY-TOKEN-003. */
  recurring: { ready: boolean; provider: string; reason: string; saved_methods: number }
}

const PAYMENTS = '/admin/settings/integrations/payments'

export function fetchPaymentSettings(): Promise<PaymentSettings> {
  return getData(PAYMENTS)
}

export function fetchPaymentWebhook(provider: string): Promise<{
  provider: string
  url: string
  authentication: string
  header: string | null
  events: string[]
}> {
  return getData(`${PAYMENTS}/${provider}/webhook`)
}

export function fetchSecretRotation(provider: string): Promise<{
  provider: string
  fields: string[]
  steps: string[]
  note: string
}> {
  return getData(`${PAYMENTS}/${provider}/rotation`)
}

/** Write-only: blank fields keep what is stored; the answer never contains a secret. */
export function savePaymentGateway(provider: string, body: Record<string, string | undefined>): Promise<{ changed: string[]; provider: PaymentProviderSetting }> {
  return putData(`${PAYMENTS}/${provider}`, body)
}

export function setPaymentGatewayEnabled(provider: string, enabled: boolean, confirmLive = false): Promise<{ provider: PaymentProviderSetting }> {
  return postData(`${PAYMENTS}/${provider}/activation`, { enabled, confirm_live: confirmLive })
}

/** New payments go to `gateway`; every existing payment keeps the gateway it was made with. */
export function setPrimaryPaymentGateway(gateway: string): Promise<PaymentSettings> {
  return putData(`${PAYMENTS}/primary`, { gateway, confirm: true })
}

/** A real round trip to the gateway. Nothing is charged — a session is an intent that expires unused. */
export function testPaymentProvider(provider: string): Promise<{
  provider: string
  reachable: boolean
  status: string
  error: string | null
}> {
  return postData(`${PAYMENTS}/${provider}/test`, {})
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
  kind: 'advertising' | 'commerce' | 'measurement'
  /** `api_key` — connected with each advertiser's own key; no platform credentials to test. */
  auth?: 'oauth' | 'api_key'
  /** PROVIDER-AUTOFILL-001 — e.g. `client_id_looks_like_email`; never the value itself. */
  warnings?: string[]
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
  last_test_status: 'passed' | 'failed' | 'inconclusive' | 'not_required' | null
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
  IntegrationProvider & { passed: boolean; outcome?: 'passed' | 'failed' | 'inconclusive' | 'not_required'; message: string }
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

// ---- Candidate Meta app (META-CANDIDATE-001) ----------------------------------------------------

export type MetaCandidateStepKey = 'oauth_start' | 'consent' | 'token_exchange' | 'account_discovery' | 'ads_read'

/** Meta's own identifiers for a refusal — never a token or a secret. */
export interface MetaCandidateStepError {
  http_status?: number | null
  code?: number | string | null
  subcode?: number | string | null
  type?: string | null
  reason?: string | null
  fbtrace_id?: string | null
  message?: string | null
}

export interface MetaCandidateStep {
  key: MetaCandidateStepKey
  status: 'ok' | 'failed' | 'pending'
  at?: string
  detail?: unknown
  error?: MetaCandidateStepError
}

export interface MetaCandidateRun {
  id: string
  profile: 'candidate' | 'saas'
  status: 'started' | 'succeeded' | 'failed'
  app_id_hint: string | null
  started_at: string | null
  finished_at: string | null
  token_expires_at: string | null
  granted_scopes: string[]
  discovered_accounts: { id: string; name: string | null; account_status: number | null; currency: string | null }[]
  steps: MetaCandidateStep[]
}

export interface MetaCandidateState {
  credentials: {
    profile: 'candidate' | 'saas'
    configured: boolean
    missing: string[]
    effective_scopes: string[]
    redirect_uri: string
    values: { key: 'client_id' | 'client_secret' | 'config_id'; secret: boolean; present: boolean; source: 'stored' | 'environment' | null; hint: string | null }[]
    configured_at: string | null
  }
  latest_run: MetaCandidateRun | null
  /** Present once promotion exists server-side. `reason` names why it is refused. */
  promotion?: {
    eligible: boolean; reason: string | null; dropped_scopes?: string[]; rollback_available: boolean
    /** META-APP-SWITCH-GUARD-001 — whether switching Live to that app would strand connections the current app issued. */
    promote_guard?: MetaAppSwitchGuard
    rollback_guard?: MetaAppSwitchGuard | null
  }
  /** META-SAAS-APP-001 — the SaaS app only: which app NEW connections use, and whether the SaaS app may be chosen yet. */
  routing?: { default_for_new_connections: 'live' | 'saas'; eligible: boolean; reason: string | null }
  connections_by_app?: { live: number; saas: number }
  /** META-SAAS-APP-001 — the SaaS app only: each readiness item and the status the server read from its own evidence. */
  readiness?: MetaSaasReadinessItem[]
  /** META-SAAS-APP-001 — the isolated test workspace and its journey, each step read from real rows. */
  test_workspace?: MetaSaasTestWorkspaceState
  /** META-SAAS-APP-001 — Live / SaaS Test / SaaS Primary, Primary's preconditions and the preview of what it changes. */
  modes?: MetaSaasModesState
}

export interface MetaSaasModesState {
  current: 'live' | 'saas_test' | 'saas_primary'
  primary: {
    enabled: boolean; eligible: boolean
    preconditions: Array<{ key: string; met: boolean }>
    preview: {
      existing_live_connections: number; existing_live_tenants: number; existing_saas_connections: number; existing_connections_moved: number
      existing_live_connected?: number; existing_live_ad_accounts?: number; live_ad_accounts_synced_24h?: number; live_oldest_last_successful_sync_at?: string | null
    }
  }
}

export type MetaSaasJourneyStep = 'oauth' | 'discovery' | 'binding' | 'sync' | 'metrics' | 'reports' | 'disconnect'
export interface MetaSaasTestWorkspaceState {
  exists: boolean; active: boolean; tenant_id: string | null; login_email: string | null; steps: Partial<Record<MetaSaasJourneyStep, boolean>>
}

export type MetaSaasReadinessStatus = 'ready' | 'not_verified' | 'needs_credentials' | 'owner_action'
export type MetaSaasReadinessKey =
  | 'legal_pages' | 'redirect_uri' | 'deletion_callback' | 'credentials' | 'test_workspace' | 'meta_dashboard' | 'oauth_round_trip'
  | 'account_discovery' | 'sync_and_reports' | 'business_verification' | 'app_review' | 'new_connections'
export interface MetaSaasReadinessItem { key: MetaSaasReadinessKey; status: MetaSaasReadinessStatus; detail: Record<string, unknown> }

/* META-SAAS-APP-001 — the third, independent Meta app: same shape, its own endpoints, no promote/rollback. */
const META_SAAS = '/admin/settings/integrations/meta-saas'
export interface MetaAppSwitchGuard {
  safe: boolean; reason: string | null; affected: number; unverified: number; current_app_hint: string | null; target_app_hint: string | null
}

export const fetchMetaSaas = (): Promise<MetaCandidateState> => getData(META_SAAS)
export const saveMetaSaas = (body: { client_id?: string; client_secret?: string; config_id?: string; scopes?: string[] }): Promise<MetaCandidateState & { fields_changed: string[] }> => putData(META_SAAS, body)
export const forgetMetaSaasCredential = (key: string): Promise<MetaCandidateState> => deleteData(`${META_SAAS}/credentials/${key}`)
export const startMetaSaasTest = (): Promise<{ run: MetaCandidateRun; authorization_url: string; expires_in_minutes: number }> => postData(`${META_SAAS}/test`, {})
/** Which Meta app NEW customer connections use. Existing connections never change app. */
/** Primary is sent only after the Owner ticked both named statements on the page; going back to Live needs neither. */
export const setMetaSaasRouting = (target: 'live' | 'saas'): Promise<MetaCandidateState> => putData(`${META_SAAS}/routing`, target === 'saas'
  ? { default: target, confirm: true, approve_primary: true, acknowledge_existing_unchanged: true }
  : { default: target, confirm: true })
export const confirmMetaSaasReview = (): Promise<MetaCandidateState> => putData(`${META_SAAS}/review`, { business_verified: true, advanced_access_granted: true })
/** The set-password link is in this response only, to be shown once to the admin who created the workspace. */
export const createMetaSaasTestWorkspace = (email: string): Promise<MetaCandidateState & { set_password_link?: string | null; mail_delivery?: string | null }> => postData(`${META_SAAS}/test-workspace`, { email })
export const closeMetaSaasTestWorkspace = (): Promise<MetaCandidateState> => deleteData(`${META_SAAS}/test-workspace`)
export const withdrawMetaSaasReview = (): Promise<MetaCandidateState> => deleteData(`${META_SAAS}/review`)

export function fetchMetaCandidate(): Promise<MetaCandidateState> {
  return getData('/admin/settings/integrations/meta-candidate')
}

/** Partial, like the provider console: a blank field is left alone. */
export function saveMetaCandidate(body: { client_id?: string; client_secret?: string; config_id?: string; scopes?: string[] }): Promise<MetaCandidateState & { fields_changed: string[] }> {
  return putData('/admin/settings/integrations/meta-candidate', body)
}

export function forgetMetaCandidateCredential(key: string): Promise<MetaCandidateState> {
  return deleteData(`/admin/settings/integrations/meta-candidate/credentials/${key}`)
}

/** Starts a round trip: the browser is sent to Meta's dialog and returns to this page. */
export function startMetaCandidateTest(): Promise<{ run: MetaCandidateRun; authorization_url: string; expires_in_minutes: number }> {
  return postData('/admin/settings/integrations/meta-candidate/test', {})
}

/** Candidate → Live. Refused (409) unless the latest round trip passed with today's credentials. */
export function promoteMetaCandidate(confirmNarrowScopes = false): Promise<MetaCandidateState> {
  return postData('/admin/settings/integrations/meta-candidate/promote', {
    confirm: true,
    // A separate, named confirmation: Live would stop requesting `dropped_scopes`.
    confirm_narrow_scopes: confirmNarrowScopes,
    // META-APP-SWITCH-GUARD-001 — sent only after the operator ticked the acknowledgement on the page.
    acknowledge_app_switch: true,
  })
}

/** One step back to the previous Live Meta app. */
export function rollbackMetaLive(): Promise<MetaCandidateState> {
  return postData('/admin/settings/integrations/meta-candidate/rollback', { confirm: true, acknowledge_app_switch: true })
}

// ---- Email operations (MAIL-014) -----------------------------------------------------------------

export interface EmailDelivery {
  id: string
  /** `transactional` (mail_deliveries) or `digest` (digest_sends) — two ledgers, one question. */
  source: 'transactional' | 'digest' | 'subscription'
  /** EMAIL-TEST-SEND-001 — a platform admin's own test: masked, and left out of `by_state`. */
  is_test?: boolean
  at: string
  kind: string
  template: string
  recipient: string | null
  tenant_name: string | null
  locale: string | null
  /** The stored word. Read `state`: `sent` here only ever meant a transport took it. */
  status: string
  /** queued, attempted, accepted, delivered, failed or suppressed — EMAIL-DELIVERY-LEDGER-001. */
  state: string
  suppression_reason?: string | null
  failure_code?: string | null
  /** What a person recorded having seen. The only thing that makes a row `delivered`. */
  evidence?: string | null
  next_attempt_at?: string | null
  provider_message_id?: string | null
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
  test_sends?: number
  transport: {
    state: 'awaiting_credentials' | 'sandbox' | 'live'
    provider_configured: boolean
    driver: string
    /** EMAIL-SETTINGS-PAGE-001 — addresses and timestamps only; the server never sends a credential. */
    sender?: { address: string; name: string }
    reply_to?: string
    last_success_at?: string | null
    last_failure?: { at: string; kind: string; error: string; failure_code?: string | null } | null
  }
  available_states: string[]
  /** NOTIFICATION-ENROLMENT-001 — who the notification system can reach, and when each scheduled digest last went out. */
  reach?: {
    members: number
    verified: number
    enrolled: number
    digest_on: number
    last_digest: { daily: string | null; weekly: string | null; monthly: string | null }
  }
}

export function fetchEmailLedger(params: {
  status?: string
  state?: string
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

/** What the transport answered. `delivery_state: accepted` is the mail server taking it, not an inbox receiving it. */
export interface TestSendResult {
  state: string
  delivery_state: string
  /** The address it really went to, whichever target was chosen. */
  recipient: string
  to?: TestSendTarget
  delivery_id?: string | null
  transport?: string | null
  suppression_reason?: string | null
  failure_code?: string | null
  provider_message_id?: string | null
}

/**
 * EMAIL-TEST-SEND-002 — the two places a test may go: the admin's own proven address, or the platform's
 * own mailbox (the configured Reply-To). A word, never an address: the server holds both.
 */
export type TestSendTarget = 'self' | 'platform_mailbox'

/** EMAIL-TEST-SEND-001 — one fixture message to one of the two targets. No address is ever sent from here. */
export const sendTestEmail = (lang: 'ar' | 'en', to: TestSendTarget, key?: string) =>
  postData<TestSendResult>('/admin/email/test', { lang, to, key })

export interface EmailPreviewList {
  keys: string[]
  locales: string[]
  /** Where a test would go, and whether that address was ever proved. */
  test_send?: { recipient: string; address_proven: boolean; platform_mailbox?: string | null }
}

export function fetchEmailPreviews(): Promise<EmailPreviewList> {
  return getData('/admin/email/previews')
}

export interface EmailPreview {
  key: string
  locale: string
  html: string
  subject?: string
  test_subject?: string
  text?: string
  /** A security or account message: nobody can switch it off. */
  mandatory?: boolean
}

export function fetchEmailPreview(key: string, locale: string): Promise<EmailPreview> {
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

/*
 * SUPERADMIN-ACCESS-001 — restore a person's access. A reason is always required; `password_link` and `reset_mfa` also need the
 * operator's own current password. A returned link is single use and shown once.
 */
export type UserAccessAction = 'revoke_sessions' | 'password_link' | 'reset_mfa' | 'deactivate' | 'activate'

export function actOnUserAccess(userUuid: string, body: { action: UserAccessAction; reason: string; current_password?: string }): Promise<{
  action: UserAccessAction
  link?: string | null
  delivery?: string | null
  sessions_revoked?: boolean
  mfa_enabled?: boolean
  disabled?: boolean
}> {
  return postData(`/admin/users/${userUuid}/access`, body)
}

/** SUPERADMIN-MEMBERS-001 — one person's role and membership in one workspace. A reason is always required. */
export type MembershipAction = 'change_role' | 'suspend' | 'reactivate' | 'remove'

export function actOnMembership(tenantId: string, userUuid: string, body: { action: MembershipAction; role?: string; reason: string; confirm_owner?: boolean }): Promise<{
  member: { roles: string[]; status: string | null; member: boolean }
}> {
  return postData(`/admin/tenants/${tenantId}/members/${userUuid}`, body)
}

/*
 * ADMIN-FULL-CONTROL-001 — a person into a workspace, a fresh link, and the subscription with no money moving. No password is set
 * or shown by any of these, and every one needs a reason on the server.
 */
export interface PendingInvitation {
  id: string
  email: string
  role: string
  delivery_status: string | null
  expires_at: string | null
}

export interface IssuedInvitation {
  delivery_status: string | null
  accept_link: string | null
  expires_in_hours: number | null
}

export function addTenantMember(tenantId: string, body: { email: string; role: string; reason: string }): Promise<{
  added: boolean
  member: { roles: string[]; status: string | null; member: boolean } | null
  invitation: IssuedInvitation | null
}> {
  return postData(`/admin/tenants/${tenantId}/members`, body)
}

export function reissueTenantInvitation(tenantId: string, invitationId: string, reason: string): Promise<{ invitation: IssuedInvitation | null }> {
  return postData(`/admin/tenants/${tenantId}/invitations/${invitationId}/reissue`, { reason })
}

export type TenantSubscriptionAction = 'activate' | 'extend' | 'end' | 'complimentary' | 'convert_paid' | 'convert_discount' | 'change_plan'

export interface TenantSubscriptionState {
  status: string
  plan_code: string | null
  plan_name: string | null
  billing_interval: string | null
  unit_amount: string | null
  currency: string | null
  current_period_end: string | null
  trial_ends_at: string | null
  grace_ends_at: string | null
  commercial_basis: string | null
  open_ended: boolean
  next_charge: { discount_percent: number; amount: string; currency: string | null } | null
}

export interface TenantSubscriptionOverview {
  subscription: TenantSubscriptionState | null
  plans: Array<{ code: string; name: string; currency: string; price_monthly: string | null; price_annual: string | null }>
  history: Array<{ action: string; at: string | null; by: string | null; reason: string | null }>
}

export function fetchTenantSubscription(tenantId: string): Promise<TenantSubscriptionOverview> {
  return getData(`/admin/tenants/${tenantId}/subscription`)
}

export function actOnTenantSubscription(tenantId: string, body: {
  action: TenantSubscriptionAction
  reason: string
  plan_code?: string
  interval?: 'monthly' | 'annual'
  ends_on?: string
  discount_percent?: number
  confirm_complimentary?: boolean
}): Promise<{ subscription: TenantSubscriptionState; public_intake_affected: boolean }> {
  return postData(`/admin/tenants/${tenantId}/subscription`, body)
}

/*
 * SUPERADMIN-WORKSPACE-001 — create a workspace and hand it to its owner.
 *
 * `accept_link` is a one-time credential returned only to the operator who issued it, for handing over when mail does not arrive.
 * It is shown once and never stored by the console.
 */
export interface OwnerInvitation {
  delivery_status: string | null
  accept_link: string | null
  expires_in_hours: number | null
}

export interface ProvisionedWorkspace {
  tenant: { id: string; name: string; slug: string; plan: string; modules: string[] }
  created: boolean
  owner_has_access: boolean
  invitation: OwnerInvitation | null
}

export function createWorkspace(body: {
  company_name: string
  workspace_name?: string
  owner_email: string
  plan?: string
  modules?: string[]
}): Promise<ProvisionedWorkspace> {
  return postData('/admin/tenants', body)
}

export function reissueOwnerInvitation(tenantId: string, ownerEmail: string): Promise<{ owner_has_access: boolean; invitation: OwnerInvitation | null }> {
  return postData(`/admin/tenants/${tenantId}/owner-invitation`, { owner_email: ownerEmail })
}

/*
 * SUPERADMIN-FEATURES-001 — capabilities on or off for every workspace, and one workspace's own switch. Enforced by the server's
 * `module:` checks, not by hiding: every change needs a reason and is audited.
 */
export interface PlatformCapability {
  module: string
  activation: string
  platform_disabled: boolean
  disabled_by_environment: boolean
}

export interface TenantCapability {
  module: string
  entitled: boolean
  enabled: boolean
  reason: string | null
  /** The workspace's own switch: true, false, or null when it inherits. */
  tenant_switch: boolean | null
}

export function fetchPlatformCapabilities(): Promise<{ capabilities: PlatformCapability[] }> {
  return getData('/admin/capabilities')
}

export function setPlatformCapability(module: string, disabled: boolean, reason: string): Promise<{ capabilities: PlatformCapability[] }> {
  return putData(`/admin/capabilities/${module}`, { disabled, reason })
}

/** SUPERADMIN-FEATURES-001 — plan level: which modules each plan carries, and how many workspaces it reaches. */
export interface PlanCapabilities {
  modules: Array<{ module: string; default_entitled: boolean }>
  plans: Array<{ id: string; code: string; name: string; name_ar: string | null; is_active: boolean; workspaces: number; modules: Record<string, boolean> }>
}

export function fetchPlanCapabilities(): Promise<PlanCapabilities> {
  return getData('/admin/plans/capabilities')
}

export function setPlanCapability(planId: string, module: string, enabled: boolean, reason: string): Promise<PlanCapabilities> {
  return putData(`/admin/plans/${planId}/capabilities/${module}`, { enabled, reason })
}

export function fetchTenantCapabilities(tenantId: string): Promise<{ capabilities: TenantCapability[] }> {
  return getData(`/admin/tenants/${tenantId}/capabilities`)
}

export function setTenantCapability(tenantId: string, module: string, enabled: boolean | null, reason: string): Promise<{ capabilities: TenantCapability[] }> {
  return putData(`/admin/tenants/${tenantId}/capabilities/${module}`, { enabled, reason })
}

/*
 * SUPERADMIN-SUPPORT-ACCESS-001 — «الدخول للدعم»: a 30-minute, read-only visit to one workspace. Needs a reason and the operator's
 * own password; the server refuses writes, secrets, money and exports for the whole visit.
 */
export function startSupportSession(tenantId: string, reason: string, currentPassword: string): Promise<{
  support_session_id: string
  tenant: { id: string; name: string }
  mode: string
  expires_at: string
  landing_path: string
}> {
  return postData(`/admin/tenants/${tenantId}/support-session`, { reason, current_password: currentPassword })
}

export function endSupportSession(): Promise<{ ended: boolean; return_path: string }> {
  return deleteData('/admin/support-session')
}

/** PRICING-ADDONS-001 — the add-ons catalogue and each workspace's add-ons, from the console. Every change carries a reason. */
export interface AdminAddon {
  id: string
  code: string
  kind: 'capacity' | 'feature'
  name: string
  name_ar: string
  price_monthly: string | null
  currency: string
  readiness: 'available' | 'coming_soon'
  is_active: boolean
  sold: boolean
  grants: { limits?: Record<string, number> } | null
  active_workspaces: number
  requests_waiting: number
}

export interface WorkspaceAddon {
  id: string
  code: string | null
  name: string | null
  name_ar: string | null
  quantity: number
  status: 'requested' | 'active' | 'declined' | 'cancelled'
  source: string
  unit_price: string | null
  currency: string | null
  reason: string | null
}

export function fetchAddons(): Promise<{ addons: AdminAddon[] }> {
  return getData('/admin/addons')
}

export function updateAddon(id: string, body: { price_monthly?: number | null; readiness?: 'available' | 'coming_soon'; is_active?: boolean; reason: string }): Promise<{ addon: AdminAddon }> {
  return patchData(`/admin/addons/${id}`, body)
}

export function fetchWorkspaceAddons(tenantId: string): Promise<{ addons: WorkspaceAddon[] }> {
  return getData(`/admin/tenants/${tenantId}/addons`)
}

export function grantWorkspaceAddon(tenantId: string, code: string, quantity: number, reason: string): Promise<{ addons: WorkspaceAddon[] }> {
  return postData(`/admin/tenants/${tenantId}/addons`, { code, quantity, reason })
}

export function decideWorkspaceAddon(id: string, decision: 'approve' | 'decline' | 'cancel', reason: string): Promise<{ addons: WorkspaceAddon[] }> {
  return patchData(`/admin/tenant-addons/${id}`, { decision, reason })
}

// ── PRICING-MIGRATION-001 — launch-catalogue subscribers onto the current catalogue ─────────────────────────────

export interface MigrationMetric {
  metric: 'projects' | 'clients' | 'team_members' | 'ad_accounts'
  usage: number
  current_limit: number | null
  new_limit: number | null
  fits: boolean
}

export interface MigrationTarget {
  plan: string
  plan_name: string
  amount: string | null
  currency: string
  interval: string
  fits: boolean
  metrics: MigrationMetric[]
}

export interface MigrationWorkspace {
  tenant_id: string
  tenant_name: string
  tenant_slug: string
  status: string
  current_period_end: string | null
  from: { plan: string | null; plan_name: string | null; amount: string | null; currency: string | null; interval: string | null }
  suggested: MigrationTarget | null
  scheduled: { plan: string; plan_name: string; amount: string | null; currency: string; effective_at: string | null } | null
}

export function fetchPlanMigration(): Promise<{ workspaces: MigrationWorkspace[]; catalogue: { currency: string; plans: { code: string; name: string }[] } }> {
  return getData('/admin/plan-migration')
}

export function schedulePlanMigration(tenantId: string, body: { plan_code: string; reason: string; confirm: string }): Promise<{ workspace: MigrationWorkspace }> {
  return postData(`/admin/tenants/${tenantId}/plan-migration`, body)
}

export function withdrawPlanMigration(tenantId: string, reason: string): Promise<{ workspace: MigrationWorkspace }> {
  return deleteData(`/admin/tenants/${tenantId}/plan-migration`, { reason })
}

export function bulkPlanMigration(body: { tenant_ids: string[]; reason: string; confirm: string }): Promise<{ scheduled: string[]; skipped: { tenant_id: string; reason: string }[] }> {
  return postData('/admin/plan-migration/bulk', body)
}

// PRICING-USAGE-CREDITS-001 — meters (actual unit cost, credit charging, allowances per plan) and each workspace's credits.
export interface AdminUsageMeter {
  code: string
  name: string
  name_ar: string
  unit: string
  funded_by: 'platform' | 'customer'
  unit_cost: string | null
  currency: string
  charges_credits: boolean
  charges: boolean
  included_per_plan: Record<string, number>
  is_active: boolean
}

export interface WorkspaceCredits {
  seats?: { active: number; holding: number; replacements_used: number; replacements_allowed: number | null; next_replacement_at: string | null; cap: number | null }
  balance: string
  currency: string
  meters: { code: string; name: string; name_ar: string; used: number; included: number | null; cost: string; credits_charged: string; uncovered: string; funded_by: 'platform' | 'customer' }[]
  transactions: { id: number; kind: 'grant' | 'consume' | 'adjust'; amount: string; balance_after: string; reason: string | null; actor_id: number | null; at: string | null }[]
}

export function fetchUsageMeters(): Promise<{ meters: AdminUsageMeter[] }> {
  return getData('/admin/usage/meters')
}

export function updateUsageMeter(code: string, body: { unit_cost?: number | null; charges_credits?: boolean; included_per_plan?: Record<string, number | null>; reason: string }): Promise<{ meter: AdminUsageMeter }> {
  return patchData(`/admin/usage/meters/${code}`, body)
}

export function fetchWorkspaceCredits(tenantId: string): Promise<WorkspaceCredits> {
  return getData(`/admin/tenants/${tenantId}/credits`)
}

export function adjustWorkspaceCredits(tenantId: string, amount: number, reason: string, idempotencyKey: string): Promise<{ balance: string; transaction_id: number }> {
  return postData(`/admin/tenants/${tenantId}/credits`, { amount, reason, idempotency_key: idempotencyKey })
}

// PRICING-VAT-002 — VAT treatment and price basis per catalogue edition (new sales only), and the read-only invoice review.
export interface TaxEdition {
  id: string
  number: number
  label: string
  currency: string
  status: string
  tax_treatment: string
  rate: number
  prices_include_vat: boolean
  subscriptions_keeping_their_terms: number
}

export interface InvoiceReviewRow {
  invoice_id: string
  number: string
  tenant: string | null
  status: string
  currency: string
  subtotal: string
  tax_total: string
  total: string
  amount_paid: string
  charged: string | null
  charge_status: string | null
  tax_treatment: string | null
  issued_at: string | null
  issues: string[]
}

export function fetchTax(): Promise<{ treatments: { key: string; rate: number }[]; needs_customer_eligibility?: string[]; editions: TaxEdition[] }> {
  return getData('/admin/tax')
}

export function updateTaxEdition(id: string, body: { tax_treatment: string; prices_include_vat: boolean; reason: string }): Promise<{ edition: TaxEdition }> {
  return patchData(`/admin/tax/editions/${id}`, body)
}

export function fetchInvoiceReview(): Promise<{ checked: number; flagged: number; by_issue: Record<string, number>; rows: InvoiceReviewRow[] }> {
  return getData('/admin/tax/invoice-review')
}

/*
 * PLATFORM-SERVICE-INBOX-001 — services ordered from the official CampaignsHub site.
 *
 * Platform-owned requests only. A subscriber's own requests are that subscriber's work and are not part of this
 * payload under any filter; the server decides the owner at intake and this console never sees the rest.
 */
export interface ServiceRequestName { key: string; name_ar: string; name_en: string }

export interface ServiceRequestRow {
  id: string
  reference: string
  type: ServiceRequestName
  status: ServiceRequestName & { is_terminal: boolean }
  priority: string
  company: string | null
  contact_name: string | null
  assignee: { id: number; name: string } | null
  submitted_at: string | null
  last_activity_at: string | null
  sla_due_at: string | null
  overdue: boolean
  source: string
}

export interface ServiceRequestList {
  requests: ServiceRequestRow[]
  total: number
  page: number
  per_page: number
  last_page: number
  counts: { open: number; unassigned: number; overdue: number }
  statuses: (ServiceRequestName & { is_terminal: boolean })[]
  administrators: { id: number; name: string }[]
}

export interface ServiceRequestDetail extends ServiceRequestRow {
  contact: { name: string | null; email: string | null; phone: string | null; company: string | null }
  objective: string | null
  budget: number | null
  currency: string | null
  services: string[]
  next_statuses: string[]
  /** The visitor's replies from the tracking page, and the team's client-visible replies. */
  messages: { id: number; from_visitor: boolean; author: string | null; body: string; at: string | null }[]
  files: { id: number; name: string; size: number; mime: string | null; at: string | null }[]
  activity: { type: string; from: string | null; to: string | null; message: string | null; actor_id: number | null; at: string | null }[]
  /** PLATFORM-INBOX-ATTENTION-001 — what the console emailed the visitor, each with the mail ledger's state. */
  replies?: InboxReply[]
}

export type ServiceRequestFilter = 'open' | 'unassigned' | 'all'

export function fetchServiceRequests(filter: ServiceRequestFilter = 'open', page = 1): Promise<ServiceRequestList> {
  const qs = filter === 'open' ? 'open=1' : filter === 'unassigned' ? 'open=1&unassigned=1' : 'include_archived=1'

  return getData<ServiceRequestList>(`/admin/service-requests?${qs}&page=${page}`)
}

/** Streamed by the server through the platform-owned request; the storage path never reaches the browser. */
export function serviceRequestFileUrl(id: string, fileId: number): string {
  return `/api/v1/admin/service-requests/${encodeURIComponent(id)}/files/${fileId}`
}

export function fetchServiceRequest(id: string): Promise<{ request: ServiceRequestDetail }> {
  return getData(`/admin/service-requests/${encodeURIComponent(id)}`)
}

export function updateServiceRequest(
  id: string,
  body: { status?: string; assigned_to?: number | null; reason?: string },
): Promise<{ request: ServiceRequestDetail }> {
  return patchData(`/admin/service-requests/${encodeURIComponent(id)}`, body)
}

/** PLATFORM-INBOX-ATTENTION-001 — reply to the visitor: on their tracking page, and by email to the request's contact. */
export function replyToServiceRequest(id: string, body: string): Promise<{ request: ServiceRequestDetail }> {
  return postData(`/admin/service-requests/${encodeURIComponent(id)}/reply`, { body })
}

/*
 * PLATFORM-INBOX-ATTENTION-001 — «الوارد»: contact messages, support tickets and data requests.
 *
 * Addressed to the platform, never to a workspace. Opening a row marks it read for everyone; a reply goes by email to
 * the address the visitor gave, and its state is the mail ledger's — `awaiting_credentials` says no mail left.
 */
export type InboxQueue = 'contact' | 'support' | 'data'

export interface InboxSummary {
  contact: { unread: number; open: number }
  support: { unread: number; open: number }
  data: { unread: number; open: number }
  unread: number
}

export interface InboxReply { id: string; author: string | null; body: string; delivery_status: string; at: string | null }

interface InboxRowBase {
  id: string
  name: string
  email: string
  phone: string | null
  status: string
  read_at: string | null
  created_at: string
  locale: string | null
}

export interface ContactMessageRow extends InboxRowBase {
  company: string | null
  topic: string | null
  source: string | null
  subject: string | null
  message: string | null
}

export interface SupportTicketRow extends InboxRowBase {
  reference: string
  subject: string
  message: string
  category: string
  priority: string | null
}

export interface DataRequestRow extends InboxRowBase {
  reference: string
  type: string
  details: string | null
  source: string | null
  verified_at: string | null
}

export type InboxRow = ContactMessageRow | SupportTicketRow | DataRequestRow

export const INBOX_STATUSES: Record<InboxQueue, readonly string[]> = {
  contact: ['new', 'read', 'answered', 'closed', 'spam'],
  support: ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed'],
  data: ['pending', 'verifying', 'in_review', 'blocked', 'completed', 'rejected', 'withdrawn'],
}

const INBOX_PATH: Record<InboxQueue, string> = {
  contact: '/admin/legal/contact-messages',
  support: '/admin/legal/support-tickets',
  data: '/admin/legal/data-requests',
}

const INBOX_LIST_KEY: Record<InboxQueue, 'messages' | 'tickets' | 'requests'> = {
  contact: 'messages',
  support: 'tickets',
  data: 'requests',
}

const INBOX_ROW_KEY: Record<InboxQueue, 'message' | 'ticket' | 'request'> = {
  contact: 'message',
  support: 'ticket',
  data: 'request',
}

export const fetchInboxSummary = () => getData<InboxSummary>('/admin/inbox/summary')

export async function fetchInbox(queue: InboxQueue, page = 1): Promise<{ rows: InboxRow[]; total: number; unread: number }> {
  const data = await getData<Record<string, unknown>>(`${INBOX_PATH[queue]}?page=${page}`)

  return {
    rows: (data[INBOX_LIST_KEY[queue]] as InboxRow[] | undefined) ?? [],
    total: Number(data.total ?? 0),
    unread: Number(data.unread ?? 0),
  }
}

export async function fetchInboxItem(queue: InboxQueue, id: string): Promise<{ row: InboxRow; replies: InboxReply[] }> {
  const data = await getData<Record<string, unknown>>(`${INBOX_PATH[queue]}/${encodeURIComponent(id)}`)

  return { row: data[INBOX_ROW_KEY[queue]] as InboxRow, replies: (data.replies as InboxReply[] | undefined) ?? [] }
}

export function updateInboxStatus(queue: InboxQueue, id: string, status: string): Promise<unknown> {
  return patchData(`${INBOX_PATH[queue]}/${encodeURIComponent(id)}`, { status })
}

export async function replyToInboxItem(queue: Exclude<InboxQueue, 'data'>, id: string, body: string): Promise<{ delivery_status: string }> {
  const data = await postData<{ reply: { delivery_status: string } }>(`${INBOX_PATH[queue]}/${encodeURIComponent(id)}/reply`, { body })

  return data.reply
}

/*
 * PLATFORM-PAYMENT-ALERTS-001 — customers stuck at payment or sign-up, for the platform owner only.
 *
 * The server writes every word and draws every card (the same card the owner's email carries), from recorded facts
 * only. The screen lays it out; it adds no figure of its own.
 */
export type PlatformAlertState = 'ok' | 'watch' | 'bad' | 'none'

export type PlatformAlertViz =
  | { type: 'steps'; steps: { label: string; state: PlatformAlertState }[] }
  | { type: 'count'; n: number; blocks: number; more: boolean; label: string; state: PlatformAlertState }

export interface PlatformAlertRow {
  id: string
  kind: 'checkout_abandoned' | 'payment_unverified' | 'payment_activation_mismatch' | 'settlement_missing' | 'customer_blocking_error'
  severity: 'critical' | 'warning'
  card: {
    severity: string
    title: string
    context: string
    line: string
    more: string[]
    steps: string
    viz: PlatformAlertViz
    links: { label: string; path: string }[]
  }
  occurrences: number
  first_seen_at: string | null
  last_seen_at: string | null
  emailed_at: string | null
  resolved_at: string | null
  acknowledged_at: string | null
  contact: { email: string | null; phone: string | null } | null
}

export interface PlatformAlertList {
  alerts: PlatformAlertRow[]
  counts: { open: number; critical: number; by_kind: Record<string, number> }
  settlement_check: 'available' | 'BLOCKED_EXTERNAL_CREDENTIALS'
}

export function fetchPlatformAlerts(scope: 'open' | 'all' = 'open'): Promise<PlatformAlertList> {
  return getData<PlatformAlertList>(`/admin/platform-alerts?status=${scope}`)
}

export function acknowledgePlatformAlert(id: string, acknowledged: boolean): Promise<{ alert: PlatformAlertRow }> {
  return patchData(`/admin/platform-alerts/${encodeURIComponent(id)}`, { acknowledged })
}
