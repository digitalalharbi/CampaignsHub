import { useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { switchMembership, type MembershipState, type PortalKey } from './memberships'
import { completeWorkspaceSwitch } from './workspaceSwitch'

/**
 * ENSURE-PORTAL-SESSION-001 — opening a portal the session is not in is a switch, and it is made out loud.
 *
 * The server stopped moving the session when a route of another portal is hit, because that moved every other tab with
 * it and told nobody. So this is where following a link into another portal now moves the session: before that portal's
 * screens mount, through the same `POST /auth/memberships/switch` the chooser uses, and through the same
 * `completeWorkspaceSwitch`, so the query cache and the persisted project go and the other tabs are told.
 *
 * Without it the portal's own endpoints would answer from its tenant while the shared ones (`/projects`, every
 * `portal:app,agency` route) answered from the session's: two tenants on one screen. So the screens wait.
 *
 * The server still decides. The id comes from the server's list and the server re-verifies it; a refusal lands on
 * `failed`, and nothing here can reach a workspace the person does not hold. `memberships.find` takes the first in the
 * server's order, which is the membership `EnsurePortal` would have served.
 */
export type PortalEntry = 'ready' | 'moving' | 'failed'

const ATTEMPT_KEY = 'chub:portal-entry'

/** A reload that arrives back here unmoved within this long did not take; retrying would be a reload loop. */
const ATTEMPT_WINDOW_MS = 15_000

/** False when this portal was already tried a moment ago. Storage being unavailable only costs the loop check. */
function claimAttempt(portal: PortalKey): boolean {
  try {
    const raw = window.sessionStorage.getItem(ATTEMPT_KEY)
    const last = raw === null ? null : (JSON.parse(raw) as { portal?: unknown; at?: unknown })
    if (last?.portal === portal && typeof last.at === 'number' && Date.now() - last.at < ATTEMPT_WINDOW_MS) return false
    window.sessionStorage.setItem(ATTEMPT_KEY, JSON.stringify({ portal, at: Date.now() }))
  } catch {
    /* private mode, or a value that is not ours: go on */
  }
  return true
}

function releaseAttempt(): void {
  try {
    window.sessionStorage.removeItem(ATTEMPT_KEY)
  } catch {
    /* nothing to release */
  }
}

export function useEnterPortal(portal: PortalKey, state: MembershipState | undefined): PortalEntry {
  const queryClient = useQueryClient()
  const { pathname, search, hash } = useLocation()
  const [failed, setFailed] = useState(false)
  const started = useRef(false)

  // No `current` is a platform admin outside any visit, and not holding the portal is the caller's refusal to draw.
  const inAnother = state?.current != null && state.current.portal !== portal
  const target = inAnother ? (state.memberships.find((m) => m.portal === portal) ?? null) : null
  const targetId = target?.id ?? null
  const othersGoTo = target?.landing_path ?? null

  useEffect(() => {
    if (state !== undefined && !inAnother) releaseAttempt()
  }, [state, inAnother])

  useEffect(() => {
    if (targetId === null || othersGoTo === null || started.current) return
    started.current = true

    if (!claimAttempt(portal)) {
      setFailed(true)
      return
    }

    switchMembership(targetId).then(
      // This tab keeps the page the link named. The others had no part in it, so they go to the portal's front door.
      () => completeWorkspaceSwitch(queryClient, `${pathname}${search}${hash}`, othersGoTo),
      () => {
        releaseAttempt()
        setFailed(true)
      },
    )
  }, [targetId, othersGoTo, portal, queryClient, pathname, search, hash])

  if (targetId === null) return 'ready'
  return failed ? 'failed' : 'moving'
}
