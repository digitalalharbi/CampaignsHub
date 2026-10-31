/** Mirrors the backend response envelope (see backend App\Support\ApiResponse). */
export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
  meta: { request_id?: string } & Record<string, unknown>
  errors: Record<string, string[]> | null
}

export interface AuthUser {
  id: string
  name: string
  email: string
  tenant_id: string | null
  is_platform_admin: boolean
  /** SUPERADMIN-SUPPORT-ACCESS-001 — the read-only support visit this session runs under, if any. */
  support?: { session_id: string; tenant_id: string; tenant_name: string | null; mode: string; expires_at: string } | null
  permissions: string[]
  created_at: string | null
  // Profile + menu-header fields (from /api/me and /auth/*). Optional so older payloads still type-check.
  first_name?: string | null
  last_name?: string | null
  job_title?: string | null
  phone?: string | null
  bio?: string | null
  avatar_url?: string | null
  initials?: string
  workspace_name?: string | null
  role?: string | null
  role_slug?: string | null
  status?: 'active' | 'unverified' | 'suspended'
  email_verified?: boolean
  two_factor_enabled?: boolean
  locale?: 'ar' | 'en'
  timezone?: string
  date_format?: string
  number_format?: 'latin' | 'arabic'
  theme?: 'light' | 'dark' | 'system'
  // Account entitlements — drives navigation persona (personal full vs company simplified) + onboarding.
  account?: AccountEntitlements | null
}

export interface AccountEntitlements {
  account_type: string | null
  workspace_kind: 'personal' | 'company'
  /**
   * Which portal these entitlements describe (REG-001). Null for the platform owner, who holds no
   * membership — `/admin` is gated by a flag and is not entitlement-driven.
   */
  portal: 'app' | 'agency' | 'influencers' | 'portal' | null
  enabled_modules: string[]
  module_switcher: boolean
  nav: string[]
  subscription_plan: string | null
  /** SALLA-REVIEW-001 — present only on the app-store review workspace; every page wears its banner. */
  review?: { label: string; active: boolean; expires_at: string | null } | null
  onboarding: { completed: boolean; step: string }
}

export interface AuthResult {
  user: AuthUser
  token: string
}

export interface HealthData {
  status: string
  service?: string
  checks?: Record<string, 'up' | 'down'>
}
