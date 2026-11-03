import { fetchMemberships, type PortalKey } from './memberships'
import { safeRedirect } from './redirect'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'

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

/** Where an ended session goes: sign-in, told why, and back to choosing a workspace afterwards. */
export const SESSION_ENDED_PATH = `/login?reason=session&redirect=${encodeURIComponent('/switch')}`

/**
 * The second look, once (WORKSPACE-SWITCH-001).
 *
 * The request straight after a sign-in is the one most likely to lose a race or meet a busy host, and one failure is
 * not yet a conclusion. Only one: the switcher has its own bounded retries, and two layers each looping is how a
 * redirect chain starts.
 */
const SECOND_LOOK_MS = 600

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

  const read = () => fetchMemberships(requestedPortal ?? undefined)

  try {
    const state = await read().catch(async (first: unknown) => {
      if (toApiError(first).status === 403) throw first
      await new Promise((resolve) => setTimeout(resolve, SECOND_LOOK_MS))

      return read()
    })

    return {
      destination: state.destination,
      // `requested_portal_held` is null when nothing was requested — which is not a refusal.
      requestedPortalHeld: state.requested_portal_held !== false,
    }
  } catch (error) {
    /*
     * The server says nobody is signed in, so that is what the app must believe too. Left authenticated in the store,
     * `/login` would bounce straight back through here (OWNER DEFECT #102 was that chain, ending on a failed switcher).
     */
    if (toApiError(error).status === 401) {
      useAuth.getState().setUser(null)

      return { destination: SESSION_ENDED_PATH, requestedPortalHeld: true }
    }

    // A refusal or an outage, which only a page can explain: never a guessed portal. The switcher says which it is.
    return { destination: '/switch', requestedPortalHeld: true }
  }
}
