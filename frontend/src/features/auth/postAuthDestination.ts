import { fetchMemberships, type PortalKey } from './memberships'
import { safeRedirect } from './redirect'

/**
 * Where to send someone the moment they are authenticated (ADR 0002).
 *
 * Two rules, in order:
 *
 *   1. an explicit `?redirect=` they were bounced from — the page they actually wanted;
 *   2. otherwise the destination the SERVER derives from their memberships.
 *
 * The destination is never computed here from an account type or a role. That rule lives in one place,
 * on the server, where it cannot be edited by whoever is holding the browser — and where "does this
 * user hold that portal?" can actually be answered.
 *
 * The portal a visitor asked for on the way in is passed along as a preference. The server honours it
 * only if they hold a membership for it, so it carries the journey through sign-in without becoming a
 * way to request a portal you do not have.
 */
export async function resolvePostAuthDestination(
  params: URLSearchParams,
  requestedPortal?: PortalKey | null,
): Promise<string> {
  return (await resolvePostAuthOutcome(params, requestedPortal)).destination
}

/** What the destination alone cannot say: whether the portal the visitor CHOSE is one they hold. */
export interface PostAuthOutcome {
  destination: string
  /**
   * False only when a portal was explicitly requested and the user does not hold it — the case the
   * interface has to explain rather than silently route around (LOGIN-001). True when they hold it,
   * and true when none was requested, because then there is nothing to be wrong about.
   */
  requestedPortalHeld: boolean
}

/**
 * The full answer, for callers that need to TELL the user something rather than just move them.
 *
 * An explicit `?redirect=` still wins: it is the page they were actually going to, and honouring it
 * is not a portal claim — whatever guards that page will answer for it.
 */
export async function resolvePostAuthOutcome(
  params: URLSearchParams,
  requestedPortal?: PortalKey | null,
): Promise<PostAuthOutcome> {
  const explicit = params.get('redirect')
  if (explicit) {
    const target = safeRedirect(explicit, '')
    if (target !== '') return { destination: target, requestedPortalHeld: true }
  }

  try {
    const state = await fetchMemberships(requestedPortal ?? undefined)

    return {
      destination: state.destination,
      // `requested_portal_held` is null when nothing was requested — which is not a refusal.
      requestedPortalHeld: state.requested_portal_held !== false,
    }
  } catch {
    // The session is valid — we just could not read the memberships. Send them to the neutral
    // switcher rather than guessing a portal they may not hold.
    return { destination: '/switch', requestedPortalHeld: true }
  }
}
