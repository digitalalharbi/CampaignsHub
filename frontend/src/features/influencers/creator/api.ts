import { getData, postData } from '@/lib/api/client'

/**
 * The CREATOR's side of the influencers portal (INFL-002).
 *
 * A separate module from `../api.ts` on purpose — these are not the same objects seen at a lower
 * permission level, they are the other party's view, and the types differ where it matters most:
 *
 *   the agency's `Collaboration` carries `agreed_fee` (billed to the client) and, with the costs
 *   permission, `influencer_fee` and `margin`.
 *
 *   this one carries `fee` — what the creator is PAID — and no client price at all. The agency's
 *   markup on a creator's own work is never sent to that creator, at any permission level, so there
 *   is deliberately no optional field here that could hold it.
 *
 * Nothing here takes a creator id. Every endpoint is scoped to whoever is signed in, so there is no
 * identifier in the browser that could be edited into someone else's earnings.
 */

export interface CreatorDeliverable {
  id: string
  type: string
  platform: string | null
  status: string
  due_on: string | null
  submitted_url: string | null
  submitted_at: string | null
  published_at: string | null
  is_overdue: boolean
  /** Written to be read by the creator — this is what makes a rejection actionable. */
  feedback: string | null
  /** The server's own answer, so the interface never offers a button the API would refuse. */
  can_submit: boolean
}

export interface CreatorCollaboration {
  id: string
  title: string
  status: string
  currency: string
  /** What THEY are paid. There is no client price on this type — see the module docblock. */
  fee: string | null
  starts_on: string | null
  ends_on: string | null
  brief: string | null
  client_name: string | null
  offered_at: string | null
  decision: 'accepted' | 'declined' | null
  responded_at: string | null
  can_respond: boolean
  can_submit: boolean
  deliverables: CreatorDeliverable[]
  progress: { total: number; awaiting_me: number; with_agency: number; done: number }
}

export interface CreatorProfile {
  creator: {
    id: string
    name: string
    handle: string | null
    primary_platform: string | null
    profile_url: string | null
    followers: number | null
    engagement_rate: string | null
  }
  summary: {
    offers_awaiting_response: number
    active: number
    deliverables_awaiting_me: number
  }
}

export function fetchCreatorProfile(): Promise<CreatorProfile> {
  return getData<CreatorProfile>('/influencers/me')
}

export function fetchMyCollaborations(): Promise<{ collaborations: CreatorCollaboration[] }> {
  return getData('/influencers/me/collaborations')
}

export function fetchMyCollaboration(id: string): Promise<{ collaboration: CreatorCollaboration }> {
  return getData(`/influencers/me/collaborations/${id}`)
}

/** Accept or decline the terms. Answerable once — the server refuses a second answer with 422. */
export function respondToTerms(
  id: string,
  body: { decision: 'accepted' | 'declined'; reason?: string },
): Promise<{ collaboration: CreatorCollaboration }> {
  return postData(`/influencers/me/collaborations/${id}/respond`, body)
}

export function submitDeliverable(
  collaborationId: string,
  deliverableId: string,
  body: { submitted_url: string; note?: string },
): Promise<{ collaboration: CreatorCollaboration }> {
  return postData(`/influencers/me/collaborations/${collaborationId}/deliverables/${deliverableId}/submit`, body)
}
