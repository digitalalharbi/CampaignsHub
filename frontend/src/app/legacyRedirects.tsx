import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/stores/auth'

/**
 * Keeps every pre-ADR-0002 path alive after the advertiser portal moved under `/app/*`.
 *
 * The tree used to be half at the root (`/dashboard`, `/campaigns`) and half already prefixed
 * (`/app/requests`). Consolidating it would have broken every bookmark, every link in an already-sent
 * report or email, and every deep link in the E2E suite — so each old path still resolves, and says so
 * by redirecting rather than by quietly rendering something different.
 *
 * One component handles them all: it re-uses the CURRENT pathname, which already carries whatever
 * route parameters were matched, so `/campaigns/:projectId/:campaignId` needs no special case and no
 * list of parameter names to keep in sync.
 */
function PrefixWithApp() {
  const { pathname, search, hash } = useLocation()
  const status = useAuth((s) => s.status)
  const nav = useAuth((s) => s.user?.account?.nav)
  const portal = useAuth((s) => s.user?.account?.portal)

  /*
   * WAIT for the session probe before deciding (LOGIN-002).
   *
   * This component renders immediately on a cold load, while `status` is still `loading` and the
   * user is not known — so reading the portal at that moment always yielded `undefined` and every
   * legacy path fell back to `/app`, which is precisely the bug this redirect exists to avoid. It
   * looked correct in a warm SPA and failed on every direct navigation, which is how people
   * actually follow an old bookmark.
   *
   * Rendering nothing for the moment it takes is right: there is no page here, only a decision, and
   * making it on incomplete information is worse than making it a beat later.
   */
  if (status === 'loading') return null

  /*
   * A GUEST cannot be given a portal yet, so do not pick one for them.
   *
   * Prefixing anonymously wrote `/app` into the sign-in redirect, and that stuck: after signing in,
   * an agency operator was sent to the advertiser portal's copy of their own profile page and
   * refused there. Bounce to sign-in carrying the ORIGINAL path instead — once they are known, this
   * same component resolves it into their portal.
   */
  if (status === 'guest') {
    const intended = `${pathname}${search}${hash}`
    return <Navigate to={`/login?redirect=${encodeURIComponent(intended)}`} replace />
  }

  /*
   * Into the reader's OWN portal when it offers this section (LOGIN-002).
   *
   * These paths are all pre-ADR-0002, from when there was one tree and it lived at the root, so
   * `/campaigns` meant "campaigns" and not "the advertiser's campaigns". Sending every one of them
   * to `/app` was harmless while that tree was ungated; once it was guarded, an agency operator
   * following an old bookmark met a refusal for a section their own portal has.
   *
   * `/app` stays the fallback for a section the reader's portal does not offer — an agency has no
   * Integrations screen, and `/agency/integrations` would be a 404 dressed up as a redirect. There
   * the advertiser portal's guard gives the honest answer instead.
   */
  /*
   * `account` is PERSONAL and belongs to the reader wherever they sign in, so it is portal-relative
   * unconditionally — it is not in any portal's `sections()`, which lists WORKSPACE sections, and
   * treating its absence there as "not offered" sent an agency operator's own profile link into the
   * advertiser portal and then into a refusal.
   */
  const section = pathname.split('/')[1] ?? ''
  const personal = section === 'account'
  const base = portal && (personal || nav?.includes(section)) ? `/${portal}` : '/app'

  return <Navigate to={`${base}${pathname}${search}${hash}`} replace />
}

/**
 * The paths that lived at the root before the move. Parameterised segments are declared exactly as
 * the router matched them, so the redirect fires for the same URLs the old tree served.
 */
const MOVED_TO_APP = [
  'dashboard',
  'analytics',
  'system',
  'design',
  'projects',
  'projects/:projectId/integrations',
  'projects/:projectId/team',
  'clients',
  'campaigns',
  'campaigns/:projectId/:campaignId',
  'content',
  'approvals',
  'tracking',
  'reports',
  'optimization',
  'tasks',
  'notifications',
  'files',
  'settings',
  'settings/workspace',
  'settings/permissions',
  'settings/public-pages',
  'settings/portals',
  'settings/branding',
  'settings/taxonomies',
  'settings/profile',
  'settings/password',
  'settings/security',
  'account',
  'account/profile',
  'account/password',
  'account/security',
  'account/preferences',
  'account/notifications',
  'leads',
  'opportunities',
  // Sections that existed at the root before the move but were missed the first time round, so a
  // pre-move bookmark to any of them was a dead link. Found by comparing the /app child routes with
  // this list rather than by waiting for someone to report one.
  'requests',
  'requests/:requestId',
  'clients/:clientId',
  'alerts',
  'integrations',
  'integrations/drive',
  'connections',
  'drive',
  'branding',
  'billing',
  'billing/quotes',
  'billing/invoices',
  'billing/payments',
  'finance',
  'messages',
  'subscriptions',
  'request-journey',
] as const

/**
 * Registered OUTSIDE the authenticated tree on purpose: a signed-out visitor following an old link
 * should be redirected to the new path and then meet the sign-in gate there, rather than being
 * bounced to sign-in and losing where they were going.
 */
export const legacyAppRedirects = MOVED_TO_APP.map((path) => ({
  path: `/${path}`,
  element: <PrefixWithApp />,
}))

/**
 * The multi-client surfaces moved from `/app/*` to `/agency/*` (REG-001).
 *
 * A client roster, an inbound requests inbox, client invoices and client conversations all presume
 * you run campaigns for other people, and while they were mounted in the advertiser portal that
 * portal looked like an agency console — the regression this fixes. They are not deleted, and not
 * duplicated: they live in the agency portal, which is the one whose purpose they serve.
 *
 * Swaps only the leading segment, so `/app/clients/abc-123` keeps its id and `/app/billing/quotes`
 * keeps its sub-path — no per-route list of parameter names to keep in step.
 *
 * The agency portal's own gate answers what happens next: an operator who holds an agency
 * membership carries on to the page, and one who does not is told so plainly.
 */
export function LegacyAgencyRedirect() {
  const { pathname, search, hash } = useLocation()

  return <Navigate to={`/agency${pathname.slice('/app'.length)}${search}${hash}`} replace />
}

/** The `/app` paths whose sections moved to `/agency`. */
const MOVED_TO_AGENCY = [
  'requests',
  'requests/:requestId',
  'clients',
  'clients/:clientId',
  'messages',
  'billing',
  'billing/quotes',
  'billing/invoices',
  'billing/payments',
  'finance',
] as const

/**
 * Registered OUTSIDE the `/app` portal guard, and outside the authenticated tree (LOGIN-002).
 *
 * These have to resolve for people who do NOT hold the advertiser portal — an agency operator
 * following an old `/app/clients` bookmark is the main case, and they are exactly who the guard
 * would otherwise turn away at the door of a portal they were only passing through. The redirect
 * happens first; the agency portal's own gate then answers for the destination.
 */
export const legacyAgencyRedirects = MOVED_TO_AGENCY.map((path) => ({
  path: `/app/${path}`,
  element: <LegacyAgencyRedirect />,
}))

/**
 * The external client portal moved from `/client/*` to `/portal/*` (ADR 0002), so all four portals
 * are addressed the same way.
 *
 * These paths are in clients' bookmarks and in emails already sent — a client who follows a link
 * from a quote notification and lands on a blank page has no way to recover, and no login of their
 * own to retry with. Each old path therefore still resolves and says where it went.
 *
 * Note this is a rename of the URL space only: the portal still runs its own OTP cookie session
 * (PORTAL-AUTH-001 is not done), and nothing here changes who can see what.
 */
function ClientPathToPortal() {
  const { pathname, search, hash } = useLocation()

  // `/client` → `/portal`, `/client/invoices/1` → `/portal/invoices/1`. Slicing rather than
  // replacing so a stray "client" later in the path is left alone.
  return <Navigate to={`/portal${pathname.slice('/client'.length)}${search}${hash}`} replace />
}

const MOVED_TO_PORTAL = [
  '',
  'login',
  'requests',
  'requests/:reference',
  'quotes',
  'quotes/:id',
  'invoices',
  'invoices/:id',
  'messages',
  'messages/:id',
  'profile',
  'files',
  'campaigns',
  'reports',
]

export const legacyClientPortalRedirects = MOVED_TO_PORTAL.map((path) => ({
  path: `/client${path === '' ? '' : `/${path}`}`,
  element: <ClientPathToPortal />,
}))
