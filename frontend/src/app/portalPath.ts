import { useLocation } from 'react-router-dom'
import type { PortalKey } from '@/features/auth/memberships'

/**
 * Portal-relative routing (ADR 0002).
 *
 * Several operational surfaces — clients, projects, campaigns, reports, tasks, files — are ONE engine
 * serving more than one portal. The advertiser reaches them at `/app/clients`, an agency operator at
 * `/agency/clients`, and the rows underneath are already narrowed by that user's membership scope on
 * the server.
 *
 * Those shared pages therefore must not hard-code `/app/…` in their links: doing so throws an agency
 * operator out of their portal mid-journey, which reads as the portals being one system wearing two
 * hats. `usePortalPath()` resolves a suffix against whichever portal the current URL is in, so a link
 * written once stays inside the portal the user is actually in.
 */

const PORTAL_PREFIXES: readonly string[] = ['/app', '/agency', '/influencers', '/portal']

/** The portal segment of a path, or `/app` when the path is not under a portal (the historic default). */
export function portalBaseOf(pathname: string): string {
  const match = PORTAL_PREFIXES.find((p) => pathname === p || pathname.startsWith(`${p}/`))
  return match ?? '/app'
}

/** The portal key of a path — for labels and analytics, not for authorisation (the server decides that). */
export function portalKeyOf(pathname: string): PortalKey {
  return portalBaseOf(pathname).slice(1) as PortalKey
}

/** `/app` or `/agency` … for the route the component is currently rendered under. */
export function usePortalBase(): string {
  return portalBaseOf(useLocation().pathname)
}

/**
 * Resolve a section path inside the current portal: `to('/clients')` → `/agency/clients`.
 * Absolute paths that already name a portal are returned untouched, so a deliberate cross-portal
 * link (rare, and always explicit) is still possible.
 */
export function usePortalPath(): (suffix: string) => string {
  const base = usePortalBase()
  return (suffix: string) => {
    if (PORTAL_PREFIXES.some((p) => suffix === p || suffix.startsWith(`${p}/`))) return suffix
    return `${base}${suffix.startsWith('/') ? suffix : `/${suffix}`}`
  }
}
