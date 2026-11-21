import { Link, Navigate, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { fetchMemberships, type PortalKey } from './memberships'
import { AccessRecovery } from './AccessRecovery'
import { PortalEntryChoice } from './PortalEntryChoice'
import { usePortalEntry } from './usePortalEntry'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'

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
  const isAdmin = useAuth((s) => s.user?.is_platform_admin === true)
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
  const entry = usePortalEntry(portal, state.data)
  const { pathname, search, hash } = useLocation()

  if (state.isLoading || entry.kind === 'moving') {
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

  if (entry.kind === 'choose') return <PortalEntryChoice portal={portal} current={entry.current} targets={entry.targets} />

  if (holds) return <Outlet />

  const held = state.data?.memberships ?? []

  /*
   * MAIL-LINK-PORTAL-001 — Owner 2026-11-21: «افتح أداء المحتوى» and «افتح التقرير» in a digest opened a wall, and the
   * wall's one button opened the home page, so the link led nowhere clear. Every mail link is written under `/app/…`, and
   * an agency member holds no advertiser membership. The agency tree serves the same pages at the same paths
   * (`analytics`, `reports`, `campaigns/:p/:c`, `alerts`, …), so a reader who holds the agency portal and not this one is
   * taken to the SAME page there, with its query (`?project=…&tab=…&days=…`) and hash intact. Nothing is widened: the
   * agency guard and the server still decide what that page shows.
   */
  const sibling = portal === 'app' && held.some((m) => m.portal === 'agency') ? 'agency' : null
  if (sibling !== null) {
    const rest = pathname.startsWith(`/${portal}`) ? pathname.slice(portal.length + 1) : ''
    return <Navigate to={`/${sibling}${rest === '' ? '/dashboard' : rest}${search}${hash}`} replace />
  }

  const copy = DENIED[portal]

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
          {/* CONS-ADMIN-SWITCH — the admin panel is this account's own; say where it is instead of leaving a wall. */}
          {isAdmin && (
            <Link to="/admin" data-testid="denied-back-to-admin" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
              {ar ? 'العودة إلى لوحة الإدارة' : 'Back to the admin panel'}
            </Link>
          )}
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
