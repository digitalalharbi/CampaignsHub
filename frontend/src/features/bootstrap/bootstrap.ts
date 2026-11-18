import type { QueryClient } from '@tanstack/react-query'
import { isDefinitelyGuest, probeSession } from '@/features/auth/api'
import { fetchMemberships, type MembershipState } from '@/features/auth/memberships'
import { toBellSummary, type BellSummary } from '@/features/notifications/api'
import type { CurrentSubscription } from '@/features/subscriptions/api'
import type { Branding } from '@/features/settings/api'
import { getData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'
import type { MyAccess } from '@/lib/accessSections'

/**
 * PERF-BOOTSTRAP-001 — `GET /api/v1/bootstrap`: the seven answers every page load asks first, in one request.
 *
 * Measured on /agency/dashboard: thirteen parallel API calls on a host with five PHP workers, where the cheapest of them took
 * 1.0–1.4 s inside the burst and ~0.2 s alone — they were queueing for a worker, not working. Seven of the thirteen are the same on
 * every page; this asks for them once and files each under the key its own hook reads, so the hooks find fresh data and do not ask.
 *
 * A section is `null` when the server withheld it (a portal, section switch or permission the caller does not have) or could
 * not build it. `null` is never filed: the hook that needs it asks its own endpoint, which answers exactly as it always did —
 * with its 403 or with its data. So nothing here widens what any screen can show.
 */
export interface BootstrapPayload {
  me: { user: AuthUser } | null
  memberships: MembershipState | null
  access: MyAccess | null
  subscription: CurrentSubscription | null
  branding: { branding: Branding } | null
  notifications_summary: Partial<BellSummary> | null
  funding_disclaimer: { ar: string; en: string } | null
}

export const fetchBootstrap = () => getData<BootstrapPayload>('/bootstrap')

/**
 * File every section the server sent under the EXACT key its own hook uses (and in the shape its queryFn returns), so that
 * `useMyAccess`, `useBranding`, the usage meter, the bell, the disclaimer and the portal guard find fresh data on mount.
 * `bootstrap.test.tsx` renders those hooks and counts the requests, which is what keeps these keys honest.
 */
export function seedFromBootstrap(queryClient: QueryClient, b: BootstrapPayload): void {
  if (b.memberships) queryClient.setQueryData(['memberships'], b.memberships)
  if (b.access) queryClient.setQueryData(['me', 'access'], b.access)
  if (b.branding) queryClient.setQueryData(['settings', 'branding'], b.branding)
  if (b.subscription) queryClient.setQueryData(['subscriptions', 'current'], b.subscription)
  if (b.notifications_summary) queryClient.setQueryData(['notifications', 'summary'], toBellSummary(b.notifications_summary))
  if (b.funding_disclaimer) queryClient.setQueryData(['funding', 'disclaimer'], b.funding_disclaimer)
}

/**
 * The session probe of an authenticated surface, answered by the bootstrap: who you are, and — filed before anything renders —
 * what the page is about to ask for.
 *
 * Same contract as `probeSession`: a guest is `{ user: null }`, and anything that is not an answer falls back to the plain probe
 * (which retries once and primes CSRF), so a bootstrap that fails for any other reason costs nothing but that fallback.
 */
export async function bootSession(queryClient: QueryClient): Promise<{ user: AuthUser | null; unreachable: boolean }> {
  try {
    const bootstrap = await fetchBootstrap()
    const user = bootstrap.me?.user ?? null
    if (user) {
      seedFromBootstrap(queryClient, bootstrap)
      return { user, unreachable: false }
    }
  } catch (error) {
    if (isDefinitelyGuest(error)) return { user: null, unreachable: false }
  }

  // The bootstrap did not answer (or answered without a person): the old two requests, exactly as before.
  void queryClient.prefetchQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })

  return probeSession()
}
