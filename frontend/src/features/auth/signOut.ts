import type { QueryClient } from '@tanstack/react-query'
import { logout } from './api'
import { useAuth } from '@/stores/auth'

/**
 * ACCESS-EXIT-001 — leave completely, from anywhere, even when everything else is broken.
 *
 * ## What «completely» has to mean
 *
 * Calling `/auth/logout` clears the SERVER's session and nothing else. Left behind: the cached user
 * in the auth store, every answer TanStack Query has memoised, the selected project and client in
 * persisted zustand stores, and a pile of `chub:draft:*` form drafts. Sign out, sign in as somebody
 * else, and the new session inherits the previous person's project selection and half-filled forms —
 * which is both confusing and, for a shared machine, a small disclosure.
 *
 * So this clears all four, in an order chosen so a failure part-way through still leaves the person
 * signed out rather than stranded in a half-state.
 *
 * ## The server call is allowed to fail
 *
 * This function exists mainly to rescue somebody who is already stuck — an expired session, a portal
 * they do not hold, a backend that is down. In exactly those situations `/auth/logout` is the request
 * most likely to fail, and if a failed logout stopped the local clean-up, the one action guaranteed to
 * work would be the one that stops working when it is needed. So the network call is best-effort and
 * everything local happens regardless.
 *
 * ## The redirect is a hard navigation
 *
 * `window.location.assign`, not the router. Every in-memory provider — auth, query cache, portal
 * guards — is rebuilt from nothing on a full load, which is what makes the result trustworthy: there
 * is no chance of a stale guard reading a store that a router transition had not yet reset.
 */

/** Storage keys this app owns. Prefix-matched keys are handled separately below. */
const OWNED_KEYS = [
  'campaign-hub-project-storage',
  'campaign-hub-agency-client',
  'chub:registration',
]

/** Anything under these prefixes belongs to a signed-in person and must not outlive them. */
const OWNED_PREFIXES = ['chub:draft:', 'ch-requests-', 'chub:']

/**
 * Sign out and clear everything this browser holds about the person.
 *
 * `destination` defaults to `/login`, which since LOGIN-UNIFIED-001 is the only sign-in page there
 * is: a client contact typing their address there reaches the one-time-code step, so there is no
 * longer a second door for callers on the portal side to send them to.
 */
export async function signOutCompletely(queryClient?: QueryClient, destination = '/login'): Promise<void> {
  // Best-effort: see the note above on why a failure here must not stop the rest.
  try {
    await logout()
  } catch {
    /* already expired, offline, or the backend is down — the local clean-up still has to happen */
  }

  try {
    useAuth.getState().setUser(null)
  } catch { /* store not initialised */ }

  try {
    queryClient?.clear()
  } catch { /* no cache to clear */ }

  clearOwnedStorage()

  window.location.assign(destination)
}

/**
 * Remove every key this app wrote, and nothing else.
 *
 * Enumerated rather than `localStorage.clear()`, because this origin is shared with whatever else the
 * browser has stored for it and wiping the lot is a bigger action than the user asked for. The
 * language and theme are deliberately KEPT: they are preferences of the person at the keyboard, not
 * of the session, and resetting an Arabic reader to English as a side effect of signing out is a
 * small insult that reads as a bug.
 */
export function clearOwnedStorage(): void {
  try {
    for (const key of OWNED_KEYS) {
      window.localStorage.removeItem(key)
    }

    // Snapshot the keys first: removing while iterating the live list skips entries.
    const keys = Object.keys(window.localStorage)
    for (const key of keys) {
      if (OWNED_PREFIXES.some((prefix) => key.startsWith(prefix))) {
        window.localStorage.removeItem(key)
      }
    }

    window.sessionStorage.clear()
  } catch {
    /* private mode, or storage disabled — nothing to clear */
  }
}

/**
 * Drop the persisted workspace selection — the stale-route problem in the form this app actually has.
 *
 * There is no `returnTo` or `lastVisitedRoute` here; what survives a session instead is
 * `campaign-hub-project-storage` and `campaign-hub-agency-client`. A project id from a workspace the
 * person no longer holds is exactly as harmful: the app boots, restores it, asks for data the account
 * cannot see, and lands them back on the wall they were trying to leave — which is why closing the
 * tab and returning used to change nothing.
 *
 * Called when a dead-end screen is reached: whatever is stored led here, so it is wrong by definition.
 * The language and theme are untouched — they are preferences of the person, not of the session.
 */
export function clearStaleWorkspaceSelection(): void {
  try {
    window.localStorage.removeItem('campaign-hub-project-storage')
    window.localStorage.removeItem('campaign-hub-agency-client')
  } catch {
    /* private mode, or storage disabled */
  }
}
