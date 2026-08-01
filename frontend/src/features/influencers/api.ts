import { deleteData, getData, patchData, postData } from '@/lib/api/client'

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
  /**
   * Where this stands with the creator (INFL-002). NOT gated behind the costs permission: "have they
   * said yes?" is a scheduling question, and an account manager who cannot see money still has to
   * know whether the work is agreed.
   */
  agreement?: {
    offered_at: string | null
    decision: 'accepted' | 'declined' | null
    responded_at: string | null
    decline_reason: string | null
    creator_has_access: boolean
    /** The server's own answer, so no button is offered that the API would refuse. */
    can_send_terms: boolean
  }
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

/** Turn an internal draft into an offer the creator can see and answer (INFL-002). */
export function sendTerms(collaborationId: string): Promise<{ collaboration: Collaboration }> {
  return postData(`/influencers/collaborations/${collaborationId}/send-terms`, {})
}

export function addDeliverable(
  collaborationId: string,
  body: { type: string; platform?: string | null; due_on?: string | null },
): Promise<{ collaboration: Collaboration }> {
  return postData(`/influencers/collaborations/${collaborationId}/deliverables`, body)
}

/* ── Nominations, attribution and per-post results (INFL-003) ─────────────────────────────── */

export interface Nomination {
  id: string
  status: 'proposed' | 'approved' | 'rejected' | 'withdrawn'
  campaign_id: string | null
  client_workspace_id: string | null
  proposed_fee: string | null
  currency: string | null
  rationale: string | null
  proposed_at: string | null
  decided_at: string | null
  decision_note: string | null
  /** True only for an approved nomination that has not already become work. */
  is_convertible: boolean
  collaboration_id: string | null
  influencer: {
    id: string
    name: string
    handle: string | null
    primary_platform: string | null
    followers: number | null
    tier: string | null
  } | null
}

export function fetchNominations(status?: string): Promise<Nomination[]> {
  return getData<Nomination[]>(`/influencers/nominations${status ? `?status=${encodeURIComponent(status)}` : ''}`)
}

export function proposeNomination(body: {
  influencer_id: string
  campaign_id?: string | null
  proposed_fee?: string | null
  currency?: string | null
  rationale?: string | null
}): Promise<Nomination> {
  return postData<Nomination>('/influencers/nominations', body)
}

/** Approve or reject. A rejection without a note is refused by the server, not just discouraged. */
export function decideNomination(id: string, decision: 'approved' | 'rejected', note?: string): Promise<Nomination> {
  return postData<Nomination>(`/influencers/nominations/${id}/decide`, { decision, note })
}

export function withdrawNomination(id: string): Promise<Nomination> {
  return deleteData<Nomination>(`/influencers/nominations/${id}`)
}

export function convertNomination(id: string, body: { title: string }): Promise<{ collaboration_id: string; nomination: Nomination }> {
  return postData(`/influencers/nominations/${id}/collaboration`, body)
}

export interface TrackingAsset {
  id: string
  kind: 'link' | 'discount_code'
  code: string
  deliverable_id: string | null
  destination_url: string | null
  /** Only a link has one — a discount code is not a URL this platform serves. */
  share_url: string | null
  discount_type: string | null
  discount_value: string | null
  clicks: number
  last_clicked_at: string | null
  redemptions: number
  redemptions_source: 'awaiting_credentials' | 'manual' | 'platform'
  /**
   * Whether the number beside this row is something the platform KNOWS.
   *
   * A link's clicks always are — the platform serves the redirect. A discount code's redemptions
   * only are once a person or a store supplied a figure; until then the zero is an absence of
   * information, and rendering it like a measured zero would be the clearest possible lie.
   */
  count_is_measured: boolean
  is_active: boolean
}

export function fetchTrackingAssets(collaborationId: string): Promise<TrackingAsset[]> {
  return getData<TrackingAsset[]>(`/influencers/collaborations/${collaborationId}/tracking`)
}

export function issueTrackingAsset(
  collaborationId: string,
  body: {
    kind: 'link' | 'discount_code'
    deliverable_id?: string | null
    destination_url?: string | null
    code?: string | null
    discount_type?: string | null
    discount_value?: string | null
  },
): Promise<TrackingAsset> {
  return postData<TrackingAsset>(`/influencers/collaborations/${collaborationId}/tracking`, body)
}

/** What the store reported. Stored as reported — never as something this platform measured. */
export function recordRedemptions(assetId: string, redemptions: number): Promise<TrackingAsset> {
  return patchData<TrackingAsset>(`/influencers/tracking/${assetId}/redemptions`, { redemptions })
}

export interface DeliverableResult {
  id: string
  source: 'manual' | 'platform'
  impressions: number | null
  reach: number | null
  engagements: number | null
  clicks: number | null
  conversions: number | null
  revenue: string | null
  currency: string | null
  /** Null when either side is unknown — never a 0% that would read as "nobody engaged". */
  engagement_rate: number | null
  measured_at: string | null
  note: string | null
}

export function fetchDeliverableResults(deliverableId: string): Promise<DeliverableResult[]> {
  return getData<DeliverableResult[]>(`/influencers/deliverables/${deliverableId}/results`)
}

export function recordDeliverableResult(
  deliverableId: string,
  body: Partial<Record<'impressions' | 'reach' | 'engagements' | 'clicks' | 'conversions', number>> & {
    revenue?: string
    currency?: string
    note?: string
  },
): Promise<DeliverableResult> {
  return postData<DeliverableResult>(`/influencers/deliverables/${deliverableId}/results`, body)
}
