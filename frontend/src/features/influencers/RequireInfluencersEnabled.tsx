import { Navigate, Outlet } from 'react-router-dom'
import { INFLUENCERS_FALLBACK, features } from '@/lib/features'

/** The query the services page reads to explain why somebody arrived there. */
export const INFLUENCERS_UNAVAILABLE_QUERY = 'unavailable=influencers'

/**
 * The influencers & UGC portal, while it is not being offered (INFL-OFF-001).
 *
 * Wraps the whole `/influencers` subtree AND its door. Nothing below is deleted — every page,
 * component and test stays exactly where it is — so restoring the sub-system is flipping one flag
 * rather than rebuilding a portal.
 *
 * Where somebody goes matters as much as being stopped. Three wrong answers were available here:
 *
 *   - a **404**, which reads as "you typed it wrong" for a URL that was correct last week, and is
 *     what deleting the routes would have produced;
 *   - a **blank page**, which is what leaving the routes and removing the rail would have produced
 *     for anyone with the link bookmarked;
 *   - a page saying **"coming soon"**, which is a placeholder — the thing this product does not ship.
 *
 * So it is a redirect to the services catalogue, carrying the reason, and the catalogue says in
 * words that this service is not available yet. That is a real page, with real services on it, and
 * it tells the visitor both what happened and what they can do instead.
 *
 * `replace` matters: without it, Back returns to the retired URL and bounces straight forward again.
 */
export function RequireInfluencersEnabled() {
  if (!features.influencersUgc) {
    return <Navigate to={`${INFLUENCERS_FALLBACK}?${INFLUENCERS_UNAVAILABLE_QUERY}`} replace />
  }

  return <Outlet />
}
