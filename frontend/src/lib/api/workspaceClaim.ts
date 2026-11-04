/**
 * ENSURE-PORTAL-SESSION-001 — the workspace THIS TAB's screen was drawn for.
 *
 * In memory on purpose: every tab has its own, and a full load, which is how a workspace change ends, starts it empty.
 * Sent on every request and checked by the server (`WorkspaceAssertion`). It can only get a request refused; what a
 * request runs under is still the session's, re-verified on the server.
 */
let claimed: string | null = null

export const claimedMembership = (): string | null => claimed

export function claimMembership(id: string): void {
  claimed = id
}

export function forgetClaimedMembership(): void {
  claimed = null
}

/** The session is no longer where this tab thinks. Leave with nothing kept, and say why on arrival. */
export function leaveChangedWorkspace(): void {
  claimed = null
  window.location.assign('/switch?reason=workspace_changed')
}
