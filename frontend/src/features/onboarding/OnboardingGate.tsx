import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/**
 * Gates the authenticated app behind email verification + onboarding completion. Sits inside RequireAuth
 * (so the user is known) and before AppShell. Unverified → /verify-email; verified but not onboarded →
 * /onboarding. The verify + onboarding pages render outside this gate.
 *
 * ## The `account: null` case, and why it is not "onboarded"
 *
 * `account` is built from the workspace the request resolved. It is null for exactly two reasons, and
 * they are not the same reason:
 *
 *   - the platform owner holds no membership BY DESIGN — `/admin` is gated by a flag, not by
 *     entitlements, and there is no workspace for them to onboard;
 *   - or the workspace could not be resolved for this payload at all.
 *
 * Reading both as "onboarded" was a guard failing open. The second case landed a brand-new customer
 * on `/app/dashboard` — a portal home for a workspace the payload could not even name — and left them
 * there, because nothing re-decides once the navigation has happened. It surfaced as a test watching
 * that dashboard for twenty seconds under a loaded three-browser run and never seeing the correction.
 *
 * `/switch` is the answer for "we do not know which workspace this is": the same destination
 * `resolvePostAuthOutcome` already falls back to when memberships cannot be read, and a real page with
 * exits rather than a dead end.
 */
export function OnboardingGate() {
  const user = useAuth((s) => s.user)
  const { pathname } = useLocation()

  if (!user) return <Outlet /> // RequireAuth already handled the guest case

  const verified = user.email_verified !== false // undefined (older payload) counts as verified

  if (!verified && pathname !== '/verify-email') {
    return <Navigate to="/verify-email" replace />
  }

  if (!verified) return <Outlet />

  // The owner has no workspace to onboard, and never had one.
  if (user.is_platform_admin) return <Outlet />

  if (!user.account) {
    return pathname === '/switch' ? <Outlet /> : <Navigate to="/switch" replace />
  }

  if (!user.account.onboarding.completed && pathname !== '/onboarding') {
    return <Navigate to="/onboarding" replace />
  }

  return <Outlet />
}
