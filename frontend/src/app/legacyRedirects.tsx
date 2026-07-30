import { Navigate, useLocation } from 'react-router-dom'

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

  return <Navigate to={`/app${pathname}${search}${hash}`} replace />
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
