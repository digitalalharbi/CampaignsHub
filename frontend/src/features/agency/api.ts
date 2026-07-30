import { deleteData, getData, postData, putData } from '@/lib/api/client'

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

/* ------------------------------------------------------------------ team & scopes */

export interface AgencyTeamMember {
  id: string
  role: string
  status: string
  user: { id: string; name: string | null; email: string | null }
  client_scope_ids: string[]
  /** `name` is null for a client the reader cannot see — counted, but not named. */
  clients: { id: string; name: string | null }[]
  /** True when this membership is confined to named clients. */
  is_client_scoped: boolean
  /** Unrestricted access is a POSITIVE permission — never inferred from having no scopes. */
  has_unrestricted_permission: boolean
  /** The signed-in operator's own row. Nobody may widen their own ceiling — the server refuses it. */
  is_self: boolean
}

export interface AgencyTeam {
  members: AgencyTeamMember[]
  can_manage: boolean
  /** Only the clients the SIGNED-IN operator may hand out. */
  assignable_clients: { id: string; name: string }[]
}

export function fetchAgencyTeam(): Promise<AgencyTeam> {
  return getData<AgencyTeam>('/agency/team')
}

/**
 * Three verbs, never one "save". Collapsing them would make "give them one more client" and
 * "redefine everything this person can see" the same request — and the second is destructive.
 */
export function grantClientScopes(membershipId: string, clientIds: string[]): Promise<{ member: AgencyTeamMember }> {
  return postData(`/agency/team/${membershipId}/scopes`, { client_ids: clientIds })
}

export function withdrawClientScope(membershipId: string, clientId: string): Promise<{ member: AgencyTeamMember }> {
  return deleteData(`/agency/team/${membershipId}/scopes/${clientId}`)
}

export function replaceClientScopes(membershipId: string, clientIds: string[]): Promise<{ member: AgencyTeamMember }> {
  return putData(`/agency/team/${membershipId}/scopes`, { client_ids: clientIds })
}
