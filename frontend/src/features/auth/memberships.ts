import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

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
  /**
   * The clients / projects this membership is confined to. EMPTY means unrestricted within the
   * tenant — it never means "nothing", and a non-empty list is a hard ceiling the server enforces
   * whatever the role would otherwise allow. Named scopes are a list, not a single id, because one
   * account manager routinely covers several clients from one membership.
   */
  client_scope_ids: string[]
  project_scope_ids: string[]
}

export interface MembershipState {
  memberships: Membership[]
  current: Membership | null
  /** Where the server says this user should land right now. */
  destination: string
  needs_switcher: boolean
  /**
   * The portal the visitor asked for, and whether they hold it (LOGIN-001).
   *
   * `destination` cannot express "you chose Agency and you are not in one" — it simply returns the
   * portal they DO hold, so someone who deliberately picked a tab lands somewhere else with no
   * explanation. These two let the interface say so and offer the right portal instead.
   *
   * Both null when no portal was requested: a plain `/login` claims nothing.
   */
  requested_portal: PortalKey | null
  requested_portal_held: boolean | null
  /**
   * WORKSPACE-SWITCH-001 — this person's OWN application that has not become a workspace yet.
   *
   * Null for most accounts with no membership: an invited person who was removed has no application at all, so
   * «no membership» is never taken to mean «has a registration». When present, `destination` already points at it.
   */
  pending_registration?: { id: string; state: string } | null
}

/**
 * `portal` is a preference, not a claim: the server returns a destination for a portal the user
 * actually holds, and falls back to their own when they do not hold the one asked for.
 */
export function fetchMemberships(portal?: PortalKey, options?: { timeoutMs?: number; intended?: string }): Promise<MembershipState> {
  const query = new URLSearchParams()
  if (portal) query.set('portal', portal)
  // LOGIN-SINGLE-DOOR-001 — the page a sign-in was interrupted on, for the SERVER to accept or refuse.
  if (options?.intended) query.set('intended', options.intended)
  const suffix = query.toString()

  return getData<MembershipState>(
    suffix === '' ? '/auth/memberships' : `/auth/memberships?${suffix}`,
    options?.timeoutMs === undefined ? undefined : { timeout: options.timeoutMs },
  )
}

/** Switch the active workspace. The server refuses ids the caller does not own, with 403. */
export async function switchMembership(membershipId: string): Promise<{ current: Membership; destination: string }> {
  await ensureCsrfCookie()
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
