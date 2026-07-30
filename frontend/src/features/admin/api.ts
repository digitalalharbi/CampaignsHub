import { getData, patchData } from '@/lib/api/client'

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
