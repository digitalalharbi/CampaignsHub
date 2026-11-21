import { useEffect } from 'react'
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

  useEffect(() => {
    if (moved) leaveChangedWorkspace()
  }, [moved])

  if (moved) return { kind: 'moving' }

  // No `current` is a platform admin outside any visit, or a probe that failed: nothing to claim and nothing to ask.
  if (current === null) return { kind: 'ready' }

  if (current.portal === portal) {
    claimMembership(current.id)

    return { kind: 'ready' }
  }

  const targets = (state?.memberships ?? []).filter((m) => m.portal === portal)

  // Not holding this portal is the guard's refusal to draw.
  return targets.length > 0 ? { kind: 'choose', current, targets } : { kind: 'ready' }
}
