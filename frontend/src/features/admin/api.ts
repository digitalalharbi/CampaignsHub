import { getData, patchData, postData } from '@/lib/api/client'

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

export interface AuditEntry {
  id: string
  action: string
  tenant_id: string | null
  user_id: string | null
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

export function fetchAudit(): Promise<{ entries: AuditEntry[]; meta: { total: number } }> {
  return getData('/admin/audit')
}

/* ------------------------------------------------------------------ ADMIN-002: plans & billing */

export interface PlatformPlan {
  id: string
  code: string
  name: string
  price_monthly: string
  currency: string
  is_active: boolean
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

/** Availability and name only — the price is deliberately not editable from the console. */
export function updatePlan(id: string, body: { name?: string; is_active?: boolean }): Promise<{ plan: { id: string; name: string; is_active: boolean } }> {
  return patchData(`/admin/plans/${id}`, body)
}

export function fetchSubscriptions(status?: string): Promise<{ subscriptions: PlatformSubscription[]; meta: { total: number } }> {
  return getData(`/admin/subscriptions${status ? `?status=${encodeURIComponent(status)}` : ''}`)
}

export function fetchRevenue(): Promise<PlatformRevenue> {
  return getData('/admin/revenue')
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
