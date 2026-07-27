import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/**
 * Gates the authenticated app behind email verification + onboarding completion. Sits inside RequireAuth
 * (so the user is known) and before AppShell. Unverified → /verify-email; verified but not onboarded →
 * /onboarding. The verify + onboarding pages render outside this gate.
 */
export function OnboardingGate() {
  const user = useAuth((s) => s.user)
  const { pathname } = useLocation()

  if (!user) return <Outlet /> // RequireAuth already handled the guest case

  const verified = user.email_verified !== false // undefined (older payload) counts as verified
  const onboarded = user.account ? user.account.onboarding.completed : true

  if (!verified && pathname !== '/verify-email') {
    return <Navigate to="/verify-email" replace />
  }
  if (verified && !onboarded && pathname !== '/onboarding') {
    return <Navigate to="/onboarding" replace />
  }

  return <Outlet />
}
