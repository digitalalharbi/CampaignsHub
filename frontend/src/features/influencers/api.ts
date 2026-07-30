import { getData, patchData, postData } from '@/lib/api/client'

/**
 * Influencer & UGC marketing (INFL-001).
 *
 * The two halves of this portal have deliberately different boundaries, and the types say so:
 * the ROSTER is tenant-wide (a creator is not owned by a client), while COLLABORATIONS carry the
 * client and are narrowed by the membership's client scope on the server.
 */

export interface Influencer {
  id: string
  name: string
  handle: string | null
  primary_platform: string | null
  profile_url: string | null
  followers: number | null
  /** A percentage with two decimals as a string — "4.25" means 4.25%, never a ratio. */
  engagement_rate: string | null
  tier: string | null
  categories: string[]
  country: string | null
  language: string | null
  status: string
  collaborations_count: number
  /** Present only for someone who may manage the roster — absent, not blank, otherwise. */
  contact_email?: string | null
  contact_phone?: string | null
  internal_notes?: string | null
}

export interface RosterResult {
  influencers: Influencer[]
  meta: { total: number; page: number; per_page: number }
  can_manage: boolean
}

export interface Deliverable {
  id: string
  type: string
  platform: string | null
  status: string
  due_on: string | null
  submitted_url: string | null
  published_at: string | null
  is_overdue: boolean
  feedback: string | null
}

export interface Collaboration {
  id: string
  title: string
  status: string
  currency: string
  /** Billed to the client — not a secret, since the client sees it on their invoice. */
  agreed_fee: string | null
  starts_on: string | null
  ends_on: string | null
  brief: string | null
  influencer: { id: string; name: string; handle: string | null; primary_platform: string | null } | null
  client: { id: string; name: string } | null
  deliverables: Deliverable[]
  progress: { total: number; published: number; overdue: number }
  /** Costs are a SEPARATE permission — these keys are absent without it, never zeroed. */
  influencer_fee?: string | null
  margin?: string | null
  internal_notes?: string | null
}

export interface CollaborationsResult {
  collaborations: Collaboration[]
  meta: { total: number; page: number; per_page: number }
  can_manage: boolean
  can_see_costs: boolean
}

export interface RosterFilters {
  q?: string
  status?: string
  platform?: string
}

export function fetchRoster(filters: RosterFilters = {}): Promise<RosterResult> {
  const qs = new URLSearchParams(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][],
  ).toString()

  return getData<RosterResult>(`/influencers/roster${qs ? `?${qs}` : ''}`)
}

export function fetchCollaborations(status?: string): Promise<CollaborationsResult> {
  return getData<CollaborationsResult>(`/influencers/collaborations${status ? `?status=${encodeURIComponent(status)}` : ''}`)
}

export function fetchCollaboration(id: string): Promise<{ collaboration: Collaboration }> {
  return getData(`/influencers/collaborations/${id}`)
}

export function updateDeliverable(
  collaborationId: string,
  deliverableId: string,
  body: { status: string; submitted_url?: string | null; feedback?: string | null },
): Promise<{ collaboration: Collaboration }> {
  return patchData(`/influencers/collaborations/${collaborationId}/deliverables/${deliverableId}`, body)
}

export function addDeliverable(
  collaborationId: string,
  body: { type: string; platform?: string | null; due_on?: string | null },
): Promise<{ collaboration: Collaboration }> {
  return postData(`/influencers/collaborations/${collaborationId}/deliverables`, body)
}
