import { Navigate, useLocation } from 'react-router-dom'

/**
 * LOGIN-UNIFIED-001 — the old per-portal doors, pointed at the only door there is.
 *
 * `/admin/login`, `/app/login`, `/agency/login`, `/influencers/login` and `/portal/login` are all
 * live addresses in the wild: bookmarked, pasted into chats, printed in a handover document. They
 * are not deleted, because answering 404 to a URL somebody was given is a dead end of exactly the
 * kind ACCESS-EXIT-001 exists to remove. They redirect.
 *
 * ## `replace`, and why it is not a detail
 *
 * With a push instead, Back from `/login` returns to `/app/login`, which redirects forward to
 * `/login` again — the visitor presses Back, nothing happens, and the one control they reached for
 * is the one that cannot work. `replace` takes the old address out of the history entry so Back
 * goes to wherever they genuinely came from.
 *
 * ## The query string travels
 *
 * `?redirect=%2Fagency%2Fclients` is how somebody who was stopped at the auth gate gets returned to
 * the page they were opening. Dropping it here would land every redirected visitor on their portal
 * home instead, which reads as the link having been wrong.
 *
 * The portal that used to be encoded in the PATH is deliberately not carried over: the server
 * decides the destination from the account's real memberships, and a path segment claiming a portal
 * was never anything more than a request the server had to check anyway.
 */
export function LegacyLoginRedirect() {
  const { search } = useLocation()

  return <Navigate to={{ pathname: '/login', search }} replace />
}
