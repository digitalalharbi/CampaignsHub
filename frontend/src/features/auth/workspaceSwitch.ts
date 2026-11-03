import type { QueryClient } from '@tanstack/react-query'
import { safeRedirect } from './redirect'
import { clearStaleWorkspaceSelection } from './signOut'
import { useAuth } from '@/stores/auth'

/**
 * WORKSPACE-SWITCH-001 — what moving between workspaces has to do in the browser.
 *
 * The server's half is the session's membership id, re-verified on every request. This is the rest: the shared
 * QueryClient holds lists whose keys carry no tenant, the persisted project and client ids belong to the workspace
 * just left, and the in-memory stores remember both. The hard navigation is what clears those stores; an in-app
 * route change would not.
 *
 * The other tabs move too. The workspace is a property of the session and every tab shares it, so a tab left behind
 * draws the old workspace while its next request runs in the new one — a write from it lands in a workspace its
 * screen does not name.
 */

const SIGNAL_KEY = 'chub:workspace-switched'

/** Every path that has just changed workspace ends here. */
export function completeWorkspaceSwitch(queryClient: QueryClient, destination: string): void {
  queryClient.clear()
  clearStaleWorkspaceSelection()

  try {
    // A fresh timestamp, so moving twice to the same place still fires `storage` in the other tabs.
    window.localStorage.setItem(SIGNAL_KEY, JSON.stringify({ destination, at: Date.now() }))
  } catch {
    /* private mode: this tab still moves; the others re-scope on their next load */
  }

  window.location.assign(destination)
}

/** Follow a switch made in another tab of this browser. Returns the unsubscribe. */
export function listenForWorkspaceSwitch(queryClient: QueryClient): () => void {
  const onStorage = (event: StorageEvent) => {
    if (event.key !== SIGNAL_KEY || event.newValue === null) return
    // A tab with nobody signed in has no workspace to leave: a public page or the sign-in form stays put.
    if (useAuth.getState().status !== 'authenticated') return

    let raw: unknown = null
    try {
      raw = (JSON.parse(event.newValue) as { destination?: unknown }).destination
    } catch {
      /* not ours to read: fall through to the neutral destination */
    }

    queryClient.clear()
    window.location.assign(safeRedirect(typeof raw === 'string' ? raw : null, '/switch'))
  }

  window.addEventListener('storage', onStorage)

  return () => window.removeEventListener('storage', onStorage)
}
