import { useLocation } from 'react-router-dom'
import { CLIENT_SPACE_PREFIX, clientSpaceSlugOf, getData } from '@/lib/api/client'

/**
 * Isolated client spaces (PORTAL-CLIENT-001).
 *
 * One person is routinely named on more than one of an agency's clients — a marketing lead covering
 * two brands, an owner of two companies. The portal used to merge those into one view, so two brands'
 * invoices sat in the same list with nothing to tell them apart.
 *
 * A space is now part of the URL: `/portal/clients/:clientSlug/...`. The slug is read from there and
 * sent with every portal request, and the server resolves it against the spaces the contact actually
 * owns — an unowned or unknown slug is refused, never quietly ignored.
 *
 * `/client/*` keeps working unchanged and means "everything this contact reaches", so no existing
 * link or bookmark breaks while the two live side by side.
 */

export { clientSpaceSlugOf }

/** `/portal/clients/acme` inside a space, `/client` outside one. */
export function clientSpaceBaseOf(pathname: string): string {
  const slug = clientSpaceSlugOf(pathname)

  return slug === null ? '/client' : `${CLIENT_SPACE_PREFIX}${encodeURIComponent(slug)}`
}

export function useClientSpaceSlug(): string | null {
  return clientSpaceSlugOf(useLocation().pathname)
}

/**
 * Resolve a portal section against the space the user is currently in, so a link written once works
 * in both trees: `to('/invoices')` → `/portal/clients/acme/invoices`, or `/client/invoices`.
 */
export function useClientSpacePath(): (suffix: string) => string {
  const base = clientSpaceBaseOf(useLocation().pathname)

  return (suffix: string) => (suffix === '' || suffix === '/' ? base : `${base}${suffix.startsWith('/') ? suffix : `/${suffix}`}`)
}

export interface ClientSpace {
  id: string
  slug: string
  name: string
}

export function fetchClientSpaces(): Promise<ClientSpace[]> {
  return getData<{ spaces: ClientSpace[] }>('/client/spaces').then((d) => d.spaces)
}
