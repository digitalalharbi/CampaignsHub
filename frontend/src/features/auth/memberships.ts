import { getData, postData } from '@/lib/api/client'

/**
 * The portal/workspace membership layer on the client (ADR 0002).
 *
 * The destination after signing in is NOT derived here from an account type or a role — the server
 * decides it from the user's memberships and returns it. Computing it in the browser would put a
 * routing rule in two places, and the browser's copy would be the one an attacker can edit.
 */

export type PortalKey = 'app' | 'agency' | 'influencers' | 'portal'

export interface Membership {
  id: string
  portal: PortalKey
  portal_path: string
  landing_path: string
  role: string
  is_default: boolean
  is_active: boolean
  tenant: { id: string; name: string | null; slug: string | null }
  /** Present only for a membership confined to one client space. */
  client_workspace: { id: string; name: string; slug: string } | null
}

export interface MembershipState {
  memberships: Membership[]
  current: Membership | null
  /** Where the server says this user should land right now. */
  destination: string
  needs_switcher: boolean
}

/**
 * `portal` is a preference, not a claim: the server returns a destination for a portal the user
 * actually holds, and falls back to their own when they do not hold the one asked for.
 */
export function fetchMemberships(portal?: PortalKey): Promise<MembershipState> {
  return getData<MembershipState>(portal ? `/auth/memberships?portal=${portal}` : '/auth/memberships')
}

/** Switch the active workspace. The server refuses ids the caller does not own, with 403. */
export function switchMembership(membershipId: string): Promise<{ current: Membership; destination: string }> {
  return postData('/auth/memberships/switch', { membership_id: membershipId })
}

/**
 * The auth pages describe portals in marketing terms (`default`, `client`, `influencer`); the system
 * names them `app`, `portal`, `influencers`. Mapping them here, once, keeps the two vocabularies from
 * being confused at a call site — where `influencer` silently not matching `influencers` would send a
 * visitor to the wrong place with no error.
 */
export function portalKeyFor(authPortal: 'default' | 'client' | 'influencer' | 'agency'): PortalKey {
  switch (authPortal) {
    case 'agency': return 'agency'
    case 'influencer': return 'influencers'
    case 'client': return 'portal'
    default: return 'app'
  }
}

/** Human labels for the four portals. Kept beside the type so a new portal cannot be added unlabelled. */
export const PORTAL_LABELS: Record<PortalKey, { ar: string; en: string }> = {
  app: { ar: 'إدارة الحملات', en: 'Campaign management' },
  agency: { ar: 'الوكالة', en: 'Agency' },
  influencers: { ar: 'المؤثرون وUGC', en: 'Influencers & UGC' },
  portal: { ar: 'متابعة الطلبات', en: 'Request tracking' },
}
