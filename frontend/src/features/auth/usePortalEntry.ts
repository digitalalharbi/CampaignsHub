import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import type { Membership, MembershipState, PortalKey } from './memberships'
import { claimedMembership, claimMembership, leaveChangedWorkspace } from '@/lib/api/workspaceClaim'

/**
 * ENSURE-PORTAL-SESSION-001 — opening a portal's page changes nothing; only an answer to a question does.
 *
 * A route alone, on the server or here, never moves the session (Owner, #945). When the session is in another portal,
 * this says `choose`: the guard draws `PortalEntryChoice` in place of the portal, so none of its screens mount and none
 * of its requests leave until the person decides.
 *
 * `ready` is where this tab takes its claim, before any child can ask for anything. It is taken once per page load: a
 * later answer naming another membership is not news to adopt, it means the session moved under a screen drawn for the
 * old one, so `moving` leaves, on a full load, with nothing kept. That is also how a tab that missed the storage signal
 * finds out, even without making a request of its own.
 */
export type PortalEntry =
  | { kind: 'ready' }
  | { kind: 'moving' }
  | { kind: 'choose'; current: Membership; targets: Membership[] }

export function usePortalEntry(portal: PortalKey, state: MembershipState | undefined): PortalEntry {
  const current = state?.current ?? null
  const claim = claimedMembership()
  const moved = current !== null && claim !== null && claim !== current.id
  const { search } = useLocation()

  useEffect(() => {
    if (moved) leaveChangedWorkspace()
  }, [moved])

  if (moved) return { kind: 'moving' }

  // No `current` is a platform admin outside any visit, or a probe that failed: nothing to claim and nothing to ask.
  if (current === null) return { kind: 'ready' }

  /*
   * MAIL-LINK-RECIPIENT-001 — a link from an email names the workspace it is about (`?ws=<tenant id>`). A person who belongs to
   * several workspaces and whose session is in ANOTHER one is asked to switch to that one (the same one-click question as a link
   * into another portal), instead of the page opening silently on the wrong workspace's data. Only memberships of this portal in
   * that workspace are offered; nothing moves without the click.
   */
  const wanted = workspaceFromSearch(search)
  const inWanted = (state?.memberships ?? []).filter((m) => m.portal === portal && m.tenant.id === wanted)
  if (wanted !== null && current.tenant.id !== wanted && inWanted.length > 0) {
    return { kind: 'choose', current, targets: inWanted }
  }

  if (current.portal === portal) {
    claimMembership(current.id)

    return { kind: 'ready' }
  }

  const targets = (state?.memberships ?? []).filter((m) => m.portal === portal && (wanted === null || inWanted.length === 0 || m.tenant.id === wanted))

  // Not holding this portal is the guard's refusal to draw.
  return targets.length > 0 ? { kind: 'choose', current, targets } : { kind: 'ready' }
}

/** The workspace an email link is about (`?ws=`), or null. Read from the address itself, never stored. */
function workspaceFromSearch(search: string): string | null {
  const ws = new URLSearchParams(search).get('ws')

  return ws !== null && ws.trim() !== '' ? ws.trim() : null
}
