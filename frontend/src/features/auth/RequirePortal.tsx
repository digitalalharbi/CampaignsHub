import { Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { fetchMemberships, type PortalKey } from './memberships'
import { AccessRecovery } from './AccessRecovery'
import { useUi } from '@/stores/ui'

/**
 * The entry gate for a portal route tree (LOGIN-002).
 *
 * A COURTESY, not the security boundary: every portal's endpoints are gated server-side by
 * `portal:<name>`, and the data narrowing happens in the queries. What this adds is an honest answer
 * instead of a screen of failed requests — someone who does not hold the portal is told which one
 * they are looking at and offered the portals they DO hold.
 *
 * It asks the server which memberships exist rather than reading an account type off the user, so a
 * person who holds two portals passes both gates and editing anything in the browser changes
 * nothing.
 *
 * `/app` had no gate at all until this existed, so an agency operator could open the advertiser tree
 * and meet a rail filtered down to whatever the two portals happened to share — coherent-looking and
 * wrong. Hiding menu items was doing the work a guard should do.
 */
/*
 * There is no `fallback` prop, deliberately.
 *
 * One was declared (`fallback = '/switch'`) and never read: this guard does not redirect, it renders
 * a refusal with `AccessRecovery` beneath it — which is the whole point of ACCESS-EXIT-001, since
 * `/switch` was the dead end people were being sent to. A prop that names a destination this
 * component never navigates to is a promise to the caller that nothing keeps.
 */
export function RequirePortal({ portal }: { portal: PortalKey }) {
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
  // Fail-OPEN here on purpose: the server is fail-closed, so a network blip must not lock someone
  // out of a portal they legitimately hold.
  const holds = state.data ? state.data.memberships.some((m) => m.portal === portal) : true

  if (holds) return <Outlet />

  const copy = DENIED[portal]
  const held = state.data?.memberships ?? []

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-5 py-10">
      <div
        data-testid={`${portal}-portal-denied`}
        className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 text-center"
      >
        <h1 className="font-heading text-xl font-extrabold text-text-primary">
          {ar ? copy.ar.title : copy.en.title}
        </h1>
        <p className="mt-2 text-sm text-text-secondary">{ar ? copy.ar.body : copy.en.body}</p>

        {/*
          ACCESS-EXIT-001 — a refusal that offers no exit is a wall.
          This used to render ONE button, which for somebody holding no membership pointed at
          `/switch` — a screen that said «no workspace yet» and offered nothing at all. Closing the
          tab and returning landed on the same wall, because the session was still valid. The only
          escape was clearing site data by hand.
        */}
        <AccessRecovery memberships={held} onboarding={held.length === 0} />
      </div>
    </div>
  )
}

/** What each portal is FOR, said plainly — a refusal that does not explain is just a wall. */
const DENIED: Record<PortalKey, { ar: { title: string; body: string }; en: { title: string; body: string } }> = {
  app: {
    ar: {
      title: 'بوابة إدارة الحملات غير متاحة لحسابك',
      body: 'هذه البوابة مخصّصة للمعلنين والمتاجر الذين يديرون حملاتهم الإعلانية الخاصة. حسابك ليس عضوًا فيها.',
    },
    en: {
      title: 'The campaign management portal is not open to your account',
      body: 'This portal is for advertisers and stores running their own paid campaigns. Your account is not a member of one.',
    },
  },
  agency: {
    ar: {
      title: 'بوابة الوكالة غير متاحة لحسابك',
      body: 'هذه البوابة مخصّصة لفرق الوكالات التي تدير عملاء متعددين. حسابك ليس عضوًا فيها.',
    },
    en: {
      title: 'The agency portal is not open to your account',
      body: 'This portal is for agency teams managing multiple clients. Your account is not a member of one.',
    },
  },
  influencers: {
    ar: {
      title: 'بوابة المؤثرين غير متاحة لحسابك',
      body: 'هذه البوابة مخصّصة لفرق التسويق عبر المؤثرين والمحتوى الذي ينتجه المستخدمون. حسابك ليس عضوًا فيها.',
    },
    en: {
      title: 'The influencers portal is not open to your account',
      body: 'This portal is for teams running influencer and user-generated content work. Your account is not a member of one.',
    },
  },
  portal: {
    ar: {
      title: 'بوابة متابعة الطلبات غير متاحة لحسابك',
      body: 'هذه البوابة مخصّصة لعملاء الخدمات لمتابعة طلباتهم وعروضهم وفواتيرهم. حسابك ليس عضوًا فيها.',
    },
    en: {
      title: 'The request tracking portal is not open to your account',
      body: 'This portal is for service clients following their requests, quotes and invoices. Your account is not a member of one.',
    },
  },
}
