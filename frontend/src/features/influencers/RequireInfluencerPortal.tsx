import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { fetchMemberships } from '@/features/auth/memberships'
import { AccessRecovery } from '@/features/auth/AccessRecovery'
import { useUi } from '@/stores/ui'

/**
 * The influencers portal's entry gate (ADR 0002, INFL-001).
 *
 * This is a COURTESY, not the security boundary: every influencer endpoint is gated server-side by
 * `portal:influencers`, and the client-scope narrowing happens in the database queries. What this adds is
 * an honest answer instead of a screen of failed requests — someone who does not hold an agency
 * membership is told so, and offered the portals they DO hold.
 *
 * It asks the server which memberships exist rather than reading an account type off the user, so a
 * user who holds both an advertiser and an agency membership passes, and editing anything in the
 * browser changes nothing.
 */
export function RequireInfluencerPortal() {
  const location = useLocation()
  const ar = useUi((s) => s.locale) === 'ar'
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })

  if (state.isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="h-6 w-6 animate-spin text-brand-600" aria-label={ar ? 'جارٍ التحميل' : 'Loading'} />
      </div>
    )
  }

  // A failed probe is not proof of absence — let the user through and let the API answer honestly.
  const holdsInfluencers = state.data ? state.data.memberships.some((m) => m.portal === 'influencers') : true
  const held = state.data?.memberships ?? []

  if (!holdsInfluencers) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background px-5">
        <div data-testid="influencer-portal-denied" className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 text-center">
          <h1 className="font-heading text-xl font-extrabold text-text-primary">
            {ar ? 'بوابة المؤثرين غير متاحة لحسابك' : 'The influencers portal is not open to your account'}
          </h1>
          <p className="mt-2 text-sm text-text-secondary">
            {ar
              ? 'هذه البوابة مخصّصة لفرق التسويق عبر المؤثرين والمحتوى الذي ينتجه المستخدمون. حسابك ليس عضوًا فيها.'
              : 'This portal is for teams running influencer and user-generated content work. Your account is not a member of one.'}
          </p>
          {/* ACCESS-EXIT-001 — the same one-button dead end as the other guards. */}
          <AccessRecovery memberships={held} onboarding={held.length === 0} />
        </div>
      </div>
    )
  }

  /*
   * A creator arriving at the portal root goes to their own side (INFL-002).
   *
   * Without this they land on the operator's collaborations page, which the API refuses — a 403 on
   * first sight of the product, for someone who did nothing wrong. Routing only, never security:
   * the creator's membership carries no `influencers.*` permission, so the operator endpoints
   * refuse them whether or not this redirect runs, and editing it in the browser gains nothing.
   */
  const isCreator = state.data?.memberships.some((m) => m.portal === 'influencers' && m.role === 'creator') ?? false
  const onOperatorRoot = location.pathname === '/influencers' || location.pathname === '/influencers/'

  if (isCreator && onOperatorRoot) {
    return <Navigate to="/influencers/me" replace />
  }

  return <Outlet />
}
