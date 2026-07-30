import { getData } from '@/lib/api/client'

/**
 * The agency portal's data layer (ADR 0002).
 *
 * Deliberately thin, mirroring `routes/api/agency.php`: the agency does not get its own copies of
 * clients, projects or campaigns — those engines live under /app and are already narrowed by the
 * membership's client scope through `ClientAccess`. Only genuinely agency-shaped reads live here.
 */

export interface AgencyScope {
  /** How many clients these figures cover. */
  client_count: number
  /** True when the operator's membership names specific clients rather than the whole agency. */
  is_restricted: boolean
}

export interface AgencyDashboard {
  scope: AgencyScope
  clients: { total: number; active: number; onboarding: number; needs_attention: number }
  projects: { total: number; active: number }
  campaigns: {
    total: number
    active: number
    paused: number
    /** Objective → count. Empty when the agency has no campaigns; never sample data. */
    by_objective: Record<string, number>
  }
  requests: { open: number; awaiting_client: number }
}

export function fetchAgencyDashboard(): Promise<AgencyDashboard> {
  return getData<AgencyDashboard>('/agency/dashboard')
}
