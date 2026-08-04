import { Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { fetchMemberships } from '@/features/auth/memberships'
import { AccessRecovery } from '@/features/auth/AccessRecovery'
import { useUi } from '@/stores/ui'

/**
 * The agency portal's entry gate (ADR 0002).
 *
 * This is a COURTESY, not the security boundary: every agency endpoint is gated server-side by
 * `portal:agency`, and the client-scope narrowing happens in the database queries. What this adds is
 * an honest answer instead of a screen of failed requests — someone who does not hold an agency
 * membership is told so, and offered the portals they DO hold.
 *
 * It asks the server which memberships exist rather than reading an account type off the user, so a
 * user who holds both an advertiser and an agency membership passes, and editing anything in the
 * browser changes nothing.
 */
export function RequireAgencyPortal() {
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
  const holdsAgency = state.data ? state.data.memberships.some((m) => m.portal === 'agency') : true
  const held = state.data?.memberships ?? []

  if (!holdsAgency) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background px-5">
        <div data-testid="agency-portal-denied" className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 text-center">
          <h1 className="font-heading text-xl font-extrabold text-text-primary">
            {ar ? 'بوابة الوكالة غير متاحة لحسابك' : 'The agency portal is not open to your account'}
          </h1>
          <p className="mt-2 text-sm text-text-secondary">
            {ar
              ? 'هذه البوابة مخصّصة لفرق الوكالات التي تدير عملاء متعددين. حسابك ليس عضوًا فيها.'
              : 'This portal is for agency teams managing multiple clients. Your account is not a member of one.'}
          </p>
          {/*
            ACCESS-EXIT-001 — «go to your workspaces» is not an exit for somebody who HAS none.
            It sent them to a screen that said «no workspace yet» and offered nothing.
          */}
          <AccessRecovery memberships={held} onboarding={held.length === 0} />
        </div>
      </div>
    )
  }

  return <Outlet />
}
